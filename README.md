[README.md](https://github.com/user-attachments/files/25109857/README.md)
# ⚙️ GameFest - Backend API

API RESTful desarrollada en **PHP nativo** para gestionar la plataforma de eventos GameFest. Sirve datos al frontend (Vue.js) y gestiona la lógica de negocio, autenticación y base de datos.

## 📋 Descripción

Este backend funciona como un **Enrutador MVC simplificado**. Todas las peticiones pasan por un único punto de entrada (`index.php`), que decide qué función ejecutar basándose en la URL.

### Características
* **API REST:** Respuestas en formato JSON estándar.
* **Autenticación:** Login, Registro y Logout con hashing de contraseñas (`bcrypt`) y manejo de sesiones PHP.
* **Gestión de Archivos:** Subida de imágenes para eventos con validación de tipos MIME.
* **Seguridad:** Consultas preparadas (`prepared statements`) para evitar Inyección SQL y CORS configurado.
* **Persistencia:** Conexión a base de datos MySQL.
