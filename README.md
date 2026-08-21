# Sistema Inteligente de Reserva de Espacios Universitarios

## 📌 Descripción

Este proyecto busca solucionar el problema del mal uso de los espacios universitarios. Muchas veces se reservan salones o salas y los usuarios no asisten, haciendo que estos espacios queden disponibles pero no puedan ser utilizados por otras personas.

La idea es desarrollar una plataforma web que permita consultar y reservar espacios de una manera más sencilla, utilizando un asistente de inteligencia artificial.

## 💡 ¿Cómo funciona?

El usuario podrá escribir una solicitud normal, por ejemplo:

> "Necesito un espacio para estudiar con tres compañeros mañana a las 4 de la tarde."

La IA se encargará de identificar datos como:

* Fecha
* Hora
* Cantidad de personas
* Tipo de espacio
* Recursos necesarios

Después, el backend consulta la base de datos para verificar qué espacios están realmente disponibles y le muestra las opciones al usuario.

La IA **no decide la disponibilidad**, simplemente interpreta la solicitud y se comunica con el sistema de reservas.

## 🤖 Ejemplo

**Usuario:**

> Quiero un espacio mañana a las 3 para estudiar con dos personas.

**Asistente:**

> Encontré dos espacios disponibles. ¿Cuál deseas reservar?

El usuario selecciona uno y el sistema registra la reserva. Después se envía un correo de confirmación.

## 🏗️ Arquitectura

El proyecto estará dividido principalmente en:

### Servicio Web

Se encargará de:

* Página web
* Inicio de sesión
* Reservas
* Consulta de espacios
* Comunicación con la IA
* Comunicación con la base de datos

### Servicio de Notificaciones

Se encargará de:

* Correos de confirmación
* Recordatorios
* Cancelaciones

## ☁️ Infraestructura

El sistema estará desplegado en la nube y se tendrán en cuenta aspectos como:

* HTTPS / SSL
* Control de acceso
* Firewall
* Variables de entorno
* Protección de la base de datos
* Balanceo de carga
* Escalabilidad
* GitHub Actions para despliegues

## 🎯 Objetivo

El objetivo principal es hacer que reservar un espacio universitario sea más fácil y rápido, utilizando IA para que el usuario pueda expresar lo que necesita sin tener que buscar manualmente entre todos los espacios disponibles.

El flujo esperado sería:

**Usuario → IA → Backend → Base de datos → Espacios disponibles → Reserva → Correo de confirmación**

## 🚧 Estado del proyecto

Actualmente el proyecto se encuentra en desarrollo.

### Próximamente

* [ ] Diseño de la interfaz web
* [ ] Sistema de autenticación
* [ ] Gestión de espacios
* [ ] Sistema de reservas
* [ ] Integración con IA
* [ ] Servicio de notificaciones
* [ ] Despliegue en la nube

---

**Proyecto universitario — Sistema Inteligente de Reserva de Espacios**


⭐ Proyecto académico orientado a la aplicación de conceptos de telecomunicaciones, sistemas distribuidos, infraestructura en la nube, seguridad, automatización e Inteligencia Artificial.
