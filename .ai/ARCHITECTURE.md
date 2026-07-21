# ARCHITECTURE

**Proyecto:** Antares SIS
**Versión:** 1.1

---

# Propósito

Este documento define la arquitectura oficial del proyecto Antares SIS.

Toda implementación deberá respetar las decisiones aquí establecidas.

Ningún módulo podrá apartarse de esta arquitectura sin una decisión explícita registrada en `DECISIONS.md`.

---

# Objetivos de la arquitectura

La arquitectura del proyecto debe cumplir los siguientes objetivos:

- Facilitar el mantenimiento.
- Reducir el acoplamiento.
- Favorecer la reutilización.
- Permitir el crecimiento del sistema.
- Facilitar las pruebas.
- Ser compatible con hosting compartido.
- Evitar dependencias innecesarias.

---

# Arquitectura general

El sistema utilizará una arquitectura MVC enriquecida con capas de servicios y repositorios.

La responsabilidad de cada capa deberá estar claramente definida.

```
HTTP Request
      │
      ▼
 Front Controller
      │
      ▼
    Router
      │
      ▼
 Controller
      │
      ▼
  Service Layer
      │
      ▼
 Repository
      │
      ▼
 Database (PDO)
```

Las vistas únicamente consumirán información preparada por el controlador.

---

# Responsabilidades por capa

## Front Controller

Responsable de:

- Inicializar la aplicación.
- Cargar configuración.
- Inicializar Composer.
- Registrar manejo de errores.
- Iniciar sesión.
- Entregar el control al Router.

No deberá contener lógica del negocio.

---

## Router

Responsable de:

- Resolver rutas.
- Ejecutar middleware.
- Invocar el controlador correspondiente.

No deberá acceder a la base de datos.

---

## Controller

Responsable de:

- Recibir la petición.
- Validar parámetros básicos.
- Invocar los servicios.
- Seleccionar la vista.
- Construir la respuesta.

No deberá contener reglas de negocio.

No deberá ejecutar SQL.

---

## Service

Es la capa más importante del sistema.

Toda regla de negocio deberá implementarse aquí.

Ejemplos:

- Matricular estudiante.
- Validar representante.
- Calcular estado de matrícula.
- Verificar permisos.

Los servicios podrán utilizar múltiples repositorios.

---

## Repository

Responsable exclusivamente del acceso a datos.

Podrá:

- consultar;
- insertar;
- actualizar;
- eliminar.

No deberá contener lógica del negocio.

---

## Model

Representa entidades del dominio.

No deberá conocer:

- HTML
- SQL
- HTTP

Debe representar únicamente el estado de una entidad.

---

## View

Responsable únicamente de presentar información.

No deberá contener:

- SQL
- reglas de negocio
- validaciones complejas

---

# Flujo de una petición

Cada petición seguirá el siguiente flujo:

1. El navegador envía la petición.
2. El Front Controller inicializa la aplicación.
3. El Router resuelve la ruta.
4. Se ejecuta el Controller.
5. El Controller invoca uno o varios Services.
6. Los Services utilizan Repositories.
7. Los Repositories consultan la base de datos.
8. El resultado vuelve al Controller.
9. El Controller entrega la información a la View.
10. La View genera la respuesta HTML.

---

# Stack tecnológico

## Backend

- PHP 8.2
- Composer
- PSR-4
- PDO
- MySQL 8

---

## Frontend

- Bootstrap 5
- Bootstrap Icons
- Alpine.js
- JavaScript Vanilla

---

## Librerías permitidas

Solo podrán incorporarse nuevas librerías cuando exista una justificación técnica.

Toda nueva dependencia deberá ser aprobada antes de incorporarse.

---

# Estructura del proyecto

```
AntaresSIS/
│
├── core/              # Framework propio
│
├── app/               # Código de la aplicación
│   ├── Console/
│   ├── Contracts/
│   ├── Controllers/
│   ├── DTO/
│   ├── Exceptions/
│   ├── Helpers/
│   ├── Http/
│   │   ├── Middleware/
│   │   ├── Requests/
│   │   └── Responses/
│   ├── Models/
│   ├── Providers/
│   ├── Repositories/
│   ├── Services/
│   └── Validation/
│
├── bootstrap/
├── config/
├── database/
├── public/
├── resources/
├── routes/
├── storage/
├── tests/
└── vendor/
```

---

# Organización del código

Cada módulo seguirá la misma estructura.

Ejemplo:

```
Student/

Controller

Service

Repository

Model

Requests

Views
```

No deberán mezclarse responsabilidades.

---

# Configuración

Toda configuración deberá centralizarse.

Nunca deberán existir valores de configuración escritos directamente dentro del código.

La configuración deberá provenir de:

- variables de entorno
- archivos de configuración

---

# Base de datos

Toda comunicación con la base de datos deberá realizarse mediante PDO.

No se permitirá:

- mysqli
- consultas concatenando variables
- SQL dentro de Controllers

Siempre deberán utilizarse Prepared Statements.

---

# Manejo de errores

Todas las excepciones deberán propagarse hasta el manejador central.

No deberán utilizarse:

- die()
- exit()
- var_dump()

como mecanismo normal de control.

---

# Manejo de sesiones

Toda interacción con la sesión deberá realizarse mediante un componente específico.

No deberá accederse directamente a `$_SESSION` desde cualquier parte del sistema.

---

# Seguridad

Toda implementación deberá contemplar:

- Prepared Statements
- Hash de contraseñas
- Protección CSRF
- Escape de salida
- Regeneración de sesión
- Validación de entrada
- Autorización por roles

Los lineamientos específicos de seguridad se documentan en `SECURITY_GUIDELINES.md`.

---

# Auditoría

La arquitectura deberá permitir incorporar auditoría sin modificar la lógica de negocio.

Toda operación crítica deberá poder registrarse posteriormente.

---

# Multiinstitución

La arquitectura deberá permitir múltiples instituciones.

El código no deberá contener reglas específicas de una institución.

Las personalizaciones deberán realizarse mediante configuración.

---

# White Label

La identidad visual deberá ser configurable.

Como mínimo deberá permitir:

- nombre de la institución
- logotipo
- colores
- favicon

Sin modificar código.

---

# Mobile First

Toda interfaz deberá diseñarse primero para dispositivos móviles.

Posteriormente se adaptará a tablet y escritorio.

---

# Escalabilidad

El sistema deberá permitir incorporar nuevos módulos sin modificar los existentes.

Ejemplos:

- Biblioteca
- Transporte
- CRM
- Finanzas
- Recursos Humanos
- Nómina

---

# Principios arquitectónicos

Toda implementación deberá respetar:

- SOLID
- DRY
- KISS
- YAGNI

La arquitectura priorizará siempre:

- claridad;
- simplicidad;
- mantenibilidad.

---

# Restricciones

No se permitirá:

- lógica del negocio en Views;
- SQL en Controllers;
- acceso directo a PDO desde Views;
- duplicación de lógica;
- dependencias circulares;
- acoplamiento innecesario.

---

## Separación entre Framework y Aplicación

El proyecto se divide en dos capas claramente diferenciadas.

### Core

La carpeta `core/` contiene la infraestructura del framework desarrollada para Antares SIS.

Ejemplos:

- Application
- Router
- Request
- Response
- Container
- ErrorHandler

El código ubicado en `core/` debe ser reutilizable y no debe contener reglas de negocio.

### App

La carpeta `app/` contiene exclusivamente el código específico de la aplicación.

Incluye:

- Controllers
- Services
- Repositories
- Models
- Validations

Las clases de `app/` pueden depender de `core/`.

Las clases de `core/` nunca deberán depender de `app/`.


---

# Documentación Arquitectónica

Toda implementación deberá respetar la documentación oficial del proyecto.

Antes de desarrollar una funcionalidad deberán revisarse, según corresponda:

- PROJECT_CONTEXT.md
- ARCHITECTURE.md
- DOMAIN_MODEL.md
- DATABASE_DESIGN.md
- ERD.md
- CATALOGS.md
- CODING_STANDARDS.md
- SECURITY_GUIDELINES.md
- DECISIONS.md

Cada documento posee una responsabilidad específica y no deberá duplicar información contenida en otro.

El modelo físico oficial se define en `DATABASE_DESIGN.md`.

`ERD.md` constituye la representación visual oficial de dicho modelo y deberá mantenerse siempre sincronizado con él.


---

# Evolución de la arquitectura

Toda modificación importante deberá registrarse previamente en `DECISIONS.md`.

No deberán realizarse cambios arquitectónicos durante un entregable funcional sin una justificación explícita.

---

# Estado del documento

**Versión:** 1.1

Este documento constituye la referencia oficial para toda decisión técnica del proyecto.
