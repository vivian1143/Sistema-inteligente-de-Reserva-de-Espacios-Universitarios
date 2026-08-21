🏫 Sistema Inteligente de Reserva de Espacios Universitarios
Plataforma web inteligente para consultar, gestionar y reservar espacios universitarios mediante un asistente conversacional basado en Inteligencia Artificial.

📌 Descripción del proyecto
En las universidades, algunos espacios de estudio son reservados por profesores o tutores que en ocasiones no hacen uso de ellos. Esto genera un desaprovechamiento de los espacios disponibles, mientras otros estudiantes pueden necesitarlos.
Este proyecto propone una plataforma web que permita a los estudiantes consultar la disponibilidad y reservar espacios universitarios de una manera sencilla e intuitiva.
Como elemento diferencial, el sistema incorpora un asistente conversacional basado en Inteligencia Artificial, capaz de interpretar solicitudes realizadas en lenguaje natural y convertirlas en operaciones reales dentro del sistema de reservas.

🎯 Problema
La gestión tradicional de espacios universitarios puede generar:
❌ Reservas que no son utilizadas.
❌ Espacios disponibles que no son aprovechados.
❌ Dificultad para conocer la disponibilidad en tiempo real.
❌ Procesos de reserva poco intuitivos.
❌ Poca flexibilidad para realizar solicitudes.
Esto provoca que estudiantes que realmente necesitan un espacio tengan dificultades para acceder a él.

💡 Solución propuesta
La solución consiste en una plataforma web de reservas conectada a una base de datos que mantiene la información real sobre los espacios disponibles.
El sistema contará con un asistente conversacional con IA, permitiendo que los estudiantes realicen solicitudes utilizando lenguaje natural.
💬 Ejemplo
Estudiante:
"Necesito un espacio para estudiar con tres compañeros mañana a las 4 de la tarde."
La IA interpreta la solicitud y obtiene información como:
Información
Ejemplo
📅 Fecha
Mañana
🕐 Hora
4:00 p. m.
👥 Personas
4
🏫 Tipo de espacio
Espacio de estudio
🔌 Recursos
Según disponibilidad

Posteriormente, el sistema consulta la base de datos para encontrar los espacios que realmente están disponibles.
IA:
"Encontré dos espacios disponibles. ¿Cuál deseas reservar?"
Una vez el estudiante confirma su elección, el backend registra la reserva y el servicio de notificaciones envía un correo de confirmación.

🤖 Funcionamiento de la Inteligencia Artificial
La IA funciona como una interfaz inteligente entre el estudiante y el sistema de reservas.
🔄 Flujo de funcionamiento
┌──────────────────┐
│    👨‍🎓 Usuario    │
└────────┬─────────┘
         │
         │ Lenguaje natural
         ▼
┌──────────────────┐
│   🤖 Asistente   │
│       IA         │
└────────┬─────────┘
         │
         │ Información estructurada
         ▼
┌──────────────────┐
│    🌐 Backend    │
└────────┬─────────┘
         │
         │ Consulta
         ▼
┌──────────────────┐
│   🗄️ Base de     │
│     datos        │
└────────┬─────────┘
         │
         │ Espacios disponibles
         ▼
┌──────────────────┐
│   🤖 Asistente   │
└────────┬─────────┘
         │
         │ Opciones
         ▼
┌──────────────────┐
│    👨‍🎓 Usuario    │
└────────┬─────────┘
         │
         │ Confirmación
         ▼
┌──────────────────┐
│    🌐 Backend    │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│  📧 Notificación │
└──────────────────┘
⚠️ Importante
La Inteligencia Artificial no determina por sí misma la disponibilidad de los espacios.
La disponibilidad siempre será consultada en la base de datos, mientras que el backend será responsable de ejecutar las operaciones de reserva.
Esto permite mantener una separación clara entre:
🧠 Interpretación de lenguaje natural → IA
🔎 Consulta de disponibilidad → Backend + Base de datos
📝 Registro de reservas → Backend
📧 Confirmaciones → Servicio de notificaciones

🏗️ Arquitectura e infraestructura
La solución estará organizada principalmente en dos servicios:
🌐 Servicio Web
Será responsable de:
Interfaz web.
Autenticación de usuarios.
Gestión de reservas.
Consulta de disponibilidad.
Comunicación con la base de datos.
Integración con el asistente de IA.
📧 Servicio de Notificaciones
Será responsable de:
Envío de correos de confirmación.
Notificaciones relacionadas con las reservas.
Comunicación con el servicio principal.
☁️ Infraestructura
La plataforma será desplegada en la nube y se contemplarán mecanismos de:
⚖️ Balanceo de carga.
📈 Escalabilidad.
🗄️ Base de datos protegida.
🔐 HTTPS y certificados SSL/TLS.
🛡️ Control de acceso y firewall.
🔑 Variables de entorno para proteger credenciales.
⚙️ GitHub Actions para automatizar el despliegue.

🔐 Seguridad
El sistema tendrá en cuenta diferentes mecanismos de seguridad:
Mecanismo
Objetivo
🔐 HTTPS / SSL-TLS
Proteger la comunicación
🛡️ Firewall
Controlar el tráfico
🔑 Variables de entorno
Proteger credenciales
👤 Autenticación
Controlar el acceso
🗄️ Base de datos protegida
Proteger la información
⚙️ CI/CD
Automatizar despliegues seguros


🚀 Flujo principal del sistema
1. 👨‍🎓 El estudiante ingresa a la plataforma
                    ↓
2. 💬 Describe lo que necesita
                    ↓
3. 🤖 La IA interpreta la solicitud
                    ↓
4. 🌐 El backend procesa los parámetros
                    ↓
5. 🗄️ Se consulta la disponibilidad
                    ↓
6. 📋 Se muestran las opciones disponibles
                    ↓
7. 👨‍🎓 El estudiante selecciona un espacio
                    ↓
8. ✅ Se registra la reserva
                    ↓
9. 📧 Se envía la confirmación

🎯 Resultado esperado
Se espera obtener un prototipo funcional que permita al estudiante:
Interactuar con el chatbot → expresar su necesidad → recibir espacios disponibles → seleccionar uno → realizar la reserva → recibir confirmación por correo.
El principal valor diferencial del proyecto será utilizar la Inteligencia Artificial como parte funcional del sistema, permitiendo que el usuario interactúe mediante lenguaje natural y conectando dicha interacción con las funcionalidades reales de reserva.

🧩 Tecnologías
Esta sección puede actualizarse a medida que se definan las tecnologías definitivas del proyecto.
🌐 Aplicación Web
🤖 Inteligencia Artificial / LLM
🗄️ Base de datos
🔌 API / Backend
📧 Servicio de correo
☁️ Infraestructura Cloud
🔐 HTTPS / SSL-TLS
⚙️ GitHub Actions
🐳 Contenedores / Microservicios

📂 Estructura propuesta
📦 sistema-reserva-espacios
│
├── 📁 frontend
│   └── Aplicación web
│
├── 📁 backend
│   └── API y lógica de reservas
│
├── 📁 notification-service
│   └── Servicio de notificaciones
│
├── 📁 database
│   └── Scripts y configuración de BD
│
├── 📁 docs
│   └── Documentación del proyecto
│
├── ⚙️ .github
│   └── workflows
│
├── 📄 README.md
└── 📄 .gitignore

👥 Equipo
Integrante
Rol
👩‍💻 Integrante 1
Desarrollo
👨‍💻 Integrante 2
Desarrollo
👩‍💻 Integrante 3
Desarrollo


📚 Proyecto académico
Materia: Teleco 1
Proyecto: Sistema Inteligente de Reserva de Espacios Universitarios

⭐ Proyecto académico orientado a la aplicación de conceptos de telecomunicaciones, sistemas distribuidos, infraestructura en la nube, seguridad, automatización e Inteligencia Artificial.
