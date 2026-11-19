# Configuración de Infraestructura en Azure usando Terraform
# Este archivo define todo lo necesario para desplegar en Azure

terraform {
  required_version = ">= 1.0"
  
  required_providers {
    azurerm = {
      source  = "hashicorp/azurerm"
      version = "~> 3.0"
    }
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "~> 2.0"
    }
  }

  # Configuración del backend (State Management)
  # Cambiar a tu Azure Storage Account
  backend "azurerm" {
    resource_group_name  = "terraform-state-rg"
    storage_account_name = "tfstate123456"
    container_name       = "state"
    key                  = "prod.tfstate"
  }
}

provider "azurerm" {
  features {
    key_vault {
      purge_soft_delete_on_destroy = true
    }
    virtual_machine {
      delete_os_disk_on_delete = true
    }
  }

  skip_provider_registration = false
}

provider "kubernetes" {
  host                   = azurerm_kubernetes_cluster.main.kube_config[0].host
  client_certificate     = base64decode(azurerm_kubernetes_cluster.main.kube_config[0].client_certificate)
  client_key             = base64decode(azurerm_kubernetes_cluster.main.kube_config[0].client_key)
  cluster_ca_certificate = base64decode(azurerm_kubernetes_cluster.main.kube_config[0].cluster_ca_certificate)
}

# ==============================================================================
# VARIABLES
# ==============================================================================

variable "environment" {
  description = "Environment name"
  type        = string
  default     = "production"
}

variable "location" {
  description = "Azure region"
  type        = string
  default     = "Central US"
}

variable "project_name" {
  description = "Project name"
  type        = string
  default     = "unillanos-reservas"
}

variable "aks_node_count" {
  description = "Number of AKS nodes"
  type        = number
  default     = 1
}

variable "sql_admin_username" {
  description = "SQL admin username"
  type        = string
  default     = "azureadmin"
  sensitive   = true
}

variable "sql_admin_password" {
  description = "SQL admin password"
  type        = string
  sensitive   = true
}

variable "jwt_secret" {
  description = "JWT secret for authentication"
  type        = string
  sensitive   = true
}

# ==============================================================================
# RESOURCE GROUP
# ==============================================================================

resource "azurerm_resource_group" "main" {
  name     = "${var.project_name}-${var.environment}-rg"
  location = var.location

  tags = {
    Environment = var.environment
    Project     = var.project_name
    ManagedBy   = "Terraform"
  }
}

# ==============================================================================
# CONTAINER REGISTRY
# ==============================================================================

resource "azurerm_container_registry" "main" {
  name                = replace("${var.project_name}acr${var.environment}", "-", "")
  resource_group_name = azurerm_resource_group.main.name
  location            = azurerm_resource_group.main.location
  sku                 = "Basic"
  admin_enabled       = true

  tags = {
    Environment = var.environment
  }
}

# ==============================================================================
# AZURE SQL DATABASE
# ==============================================================================

resource "azurerm_mssql_server" "main" {
  name                         = "${var.project_name}-sql-${var.environment}"
  resource_group_name          = azurerm_resource_group.main.name
  location                     = azurerm_resource_group.main.location
  version                      = "12.0"
  administrator_login          = var.sql_admin_username
  administrator_login_password = var.sql_admin_password

  azuread_administrator {
    login_username = "AzureAD Admin"
    object_id      = data.azurerm_client_config.current.object_id
  }

  tags = {
    Environment = var.environment
  }
}

resource "azurerm_mssql_database" "main" {
  name           = "${var.project_name}_${var.environment}"
  server_id      = azurerm_mssql_server.main.id
  collation      = "SQL_Latin1_General_CP1_CI_AS"
  license_type   = "LicenseIncluded"
  max_size_gb    = 2
  sku_name       = "S0"

  tags = {
    Environment = var.environment
  }
}

# ==============================================================================
# AKS CLUSTER
# ==============================================================================

resource "azurerm_kubernetes_cluster" "main" {
  name                = "${var.project_name}-aks-${var.environment}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  dns_prefix          = "${var.project_name}-aks"
  kubernetes_version  = "1.27"

  default_node_pool {
    name            = "agentpool"
    node_count      = var.aks_node_count
    vm_size         = "Standard_B2s"
    os_disk_size_gb = 30

    tags = {
      Environment = var.environment
    }
  }

  service_principal {
    client_id     = azuread_service_principal.aks.client_id
    client_secret = azuread_service_principal_password.aks.value
  }

  network_profile {
    network_plugin    = "azure"
    service_cidr      = "10.0.0.0/16"
    dns_service_ip    = "10.0.0.10"
    docker_bridge_cidr = "172.17.0.1/16"
  }

  enable_http_application_routing = false
  enable_azure_policy             = false

  tags = {
    Environment = var.environment
  }
}

# ==============================================================================
# APP SERVICE (Frontend)
# ==============================================================================

resource "azurerm_app_service_plan" "main" {
  name                = "${var.project_name}-plan-${var.environment}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name

  sku {
    tier = "Free"
    size = "F1"
  }

  tags = {
    Environment = var.environment
  }
}

resource "azurerm_app_service" "frontend" {
  name                = "${var.project_name}-frontend-${var.environment}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  app_service_plan_id = azurerm_app_service_plan.main.id

  site_config {
    php_version = "8.3"
    
    cors {
      allowed_origins = ["*"]
    }
  }

  app_settings = {
    WEBSITE_RUN_FROM_PACKAGE = "1"
    CORS_ORIGIN              = "*"
  }

  tags = {
    Environment = var.environment
  }
}

# ==============================================================================
# AZURE COGNITIVE SERVICES
# ==============================================================================

# Azure OpenAI
resource "azurerm_cognitive_account" "openai" {
  name                = "${var.project_name}-openai-${var.environment}"
  location            = "East US"  # Solo disponible en algunas regiones
  resource_group_name = azurerm_resource_group.main.name
  kind                = "OpenAI"
  sku {
    name = "S0"
  }

  tags = {
    Environment = var.environment
  }
}

# Language Service (NLP)
resource "azurerm_cognitive_account" "language" {
  name                = "${var.project_name}-language-${var.environment}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  kind                = "TextAnalytics"
  sku {
    name = "F0"  # Free tier
  }

  tags = {
    Environment = var.environment
  }
}

# Anomaly Detector
resource "azurerm_cognitive_account" "anomaly" {
  name                = "${var.project_name}-anomaly-${var.environment}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  kind                = "AnomalyDetector"
  sku {
    name = "F0"  # Free tier
  }

  tags = {
    Environment = var.environment
  }
}

# ==============================================================================
# KEY VAULT (Gestión de secretos)
# ==============================================================================

resource "azurerm_key_vault" "main" {
  name                = "${var.project_name}-vault-${var.environment}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  tenant_id           = data.azurerm_client_config.current.tenant_id
  sku_name            = "standard"

  purge_protection_enabled   = true
  soft_delete_retention_days = 7

  access_policy {
    tenant_id = data.azurerm_client_config.current.tenant_id
    object_id = data.azurerm_client_config.current.object_id

    secret_permissions = [
      "Backup",
      "Delete",
      "Get",
      "List",
      "Purge",
      "Recover",
      "Restore",
      "Set",
    ]
  }

  tags = {
    Environment = var.environment
  }
}

# Secretos en Key Vault
resource "azurerm_key_vault_secret" "jwt" {
  name         = "JWT-SECRET"
  value        = var.jwt_secret
  key_vault_id = azurerm_key_vault.main.id
}

resource "azurerm_key_vault_secret" "sql_connection" {
  name  = "SQL-CONNECTION-STRING"
  value = "Server=tcp:${azurerm_mssql_server.main.fully_qualified_domain_name},1433;Initial Catalog=${azurerm_mssql_database.main.name};Persist Security Info=False;User ID=${var.sql_admin_username};Password=${var.sql_admin_password};MultipleActiveResultSets=False;Encrypt=True;TrustServerCertificate=False;Connection Timeout=30;"

  key_vault_id = azurerm_key_vault.main.id
}

resource "azurerm_key_vault_secret" "openai_key" {
  name         = "AZURE-OPENAI-KEY"
  value        = azurerm_cognitive_account.openai.primary_access_key
  key_vault_id = azurerm_key_vault.main.id
}

resource "azurerm_key_vault_secret" "openai_endpoint" {
  name         = "AZURE-OPENAI-ENDPOINT"
  value        = azurerm_cognitive_account.openai.endpoint
  key_vault_id = azurerm_key_vault.main.id
}

# ==============================================================================
# AZURE FRONT DOOR (CDN + WAF)
# ==============================================================================

resource "azurerm_frontdoor" "main" {
  name                = "${var.project_name}-fd-${var.environment}"
  resource_group_name = azurerm_resource_group.main.name
  enforce_backend_pools_certificate_name_check = true

  routing_rule {
    name               = "frontend"
    accepted_protocols = ["Http", "Https"]
    patterns_to_match  = ["/*"]
    frontend_endpoints = ["frontend"]
    forwarding_configuration {
      forwarding_protocol = "HttpsOnly"
      backend_pool_name   = "frontend"
    }
  }

  backend_pool {
    name = "frontend"
    backend {
      host_header = azurerm_app_service.frontend.default_site_hostname
      address     = azurerm_app_service.frontend.default_site_hostname
      http_port   = 80
      https_port  = 443
    }
    load_balancing_settings {
      name = "default"
    }
    health_probes_settings {
      name = "default"
    }
  }

  backend_pool_health_probes_settings {
    name             = "default"
    enabled          = true
    path             = "/"
    protocol         = "Https"
    interval_in_seconds = 120
  }

  backend_pool_load_balancing_settings {
    name = "default"
  }

  frontend_endpoint {
    name      = "frontend"
    host_name = "${var.project_name}-fd-${var.environment}.azurefd.net"
  }

  tags = {
    Environment = var.environment
  }
}

# ==============================================================================
# AZURE MONITOR
# ==============================================================================

resource "azurerm_application_insights" "main" {
  name                = "${var.project_name}-insights-${var.environment}"
  location            = azurerm_resource_group.main.location
  resource_group_name = azurerm_resource_group.main.name
  application_type    = "web"
  retention_in_days   = 30

  tags = {
    Environment = var.environment
  }
}

# ==============================================================================
# DATA SOURCES
# ==============================================================================

data "azurerm_client_config" "current" {}

# ==============================================================================
# SERVICE PRINCIPAL PARA AKS
# ==============================================================================

resource "azuread_application" "aks" {
  display_name = "${var.project_name}-aks-${var.environment}"
}

resource "azuread_service_principal" "aks" {
  client_id = azuread_application.aks.client_id
}

resource "azuread_service_principal_password" "aks" {
  service_principal_id = azuread_service_principal.aks.id
  end_date_relative    = "8760h" # 1 año
}

resource "azurerm_role_assignment" "aks" {
  scope              = azurerm_resource_group.main.id
  role_definition_name = "Contributor"
  principal_id       = azuread_service_principal.aks.object_id
}

# ==============================================================================
# OUTPUTS
# ==============================================================================

output "frontend_url" {
  description = "URL del frontend"
  value       = "https://${azurerm_app_service.frontend.default_site_hostname}"
}

output "frontdoor_url" {
  description = "URL de Azure Front Door"
  value       = "https://${azurerm_frontdoor.main.frontend_endpoints[0].host_name}"
}

output "aks_cluster_name" {
  description = "Nombre del cluster AKS"
  value       = azurerm_kubernetes_cluster.main.name
}

output "acr_login_server" {
  description = "Azure Container Registry login server"
  value       = azurerm_container_registry.main.login_server
}

output "sql_server_fqdn" {
  description = "SQL Server FQDN"
  value       = azurerm_mssql_server.main.fully_qualified_domain_name
}

output "key_vault_id" {
  description = "Azure Key Vault ID"
  value       = azurerm_key_vault.main.id
}

output "openai_endpoint" {
  description = "Azure OpenAI endpoint"
  value       = azurerm_cognitive_account.openai.endpoint
}

output "application_insights_id" {
  description = "Application Insights ID"
  value       = azurerm_application_insights.main.id
}
