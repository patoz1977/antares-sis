# ROADMAP

**Versión:** 2.1

**Estado:** Aprobado

**Última actualización:** Julio 2026

**Proyecto:** School Information System (SIS)

---

# Propósito

Este documento define la planificación oficial del desarrollo del Sistema de Información Escolar (SIS).

Su objetivo es mantener una visión clara del avance del proyecto, los entregables completados, los entregables en desarrollo y la evolución funcional del producto.

No sustituye la documentación técnica de cada módulo.

---

# Estado general del proyecto

**Estado actual:** E002 — Autenticación.

La fase de documentación base del proyecto se encuentra completada.

Documentación consolidada:

- README.md
- PROJECT_CONTEXT.md
- ARCHITECTURE.md
- DOMAIN_MODEL.md
- DATABASE_DESIGN.md
- ERD.md
- CATALOGS.md
- SECURITY_GUIDELINES.md
- CODING_STANDARDS.md
- AI_DATABASE_RULES.md
- DECISIONS.md

La infraestructura base del framework (E001) ha sido finalizada y validada. El desarrollo continúa con el primer módulo funcional del sistema.

---

# Objetivos generales

El proyecto persigue los siguientes objetivos:

- Construir un Sistema de Información Escolar moderno.
- Desarrollar un producto comercial.
- Ser completamente White Label.
- Ser multiinstitución.
- Mantener una arquitectura limpia.
- Permitir crecimiento modular.
- Minimizar la deuda técnica.
- Priorizar funcionalidades utilizables sobre infraestructura.

---

# Producto Mínimo Viable (MVP)

El primer objetivo comercial del proyecto es disponer de un Portal del Representante completamente funcional.

El MVP deberá permitir:

- autenticación;
- administración de usuarios;
- ingreso y actualización de información del representante;
- administración de estudiantes;
- matrícula en línea;
- información médica;
- contactos de emergencia;
- personas autorizadas para retiro;
- transporte escolar;
- revisión administrativa;
- reportes iniciales para secretaría.

Los módulos adicionales del SIS (finanzas, calificaciones, asistencia, biblioteca, inventario, etc.) se desarrollarán como fases posteriores o complementos del producto.

---

# Modelo de desarrollo

El proyecto se desarrolla mediante entregables incrementales.

Cada entregable debe producir una funcionalidad verificable y utilizable.

La infraestructura del framework se considera finalizada con E001 y solamente evolucionará cuando una funcionalidad futura lo requiera.

Toda funcionalidad nueva deberá mantener sincronizados:

- la documentación de la carpeta `.ai`;
- las ADR cuando exista una decisión arquitectónica nueva;
- este ROADMAP cuando cambie el estado de un entregable.

---

# Estado de los entregables

| Código | Entregable | Estado |
|---------|------------|:------:|
| E000 | Preparación del proyecto y documentación IA | ✅ |
| E001 | Framework base | ✅ |
| E002 | Autenticación | ⏳ |
| E003 | Gestión de usuarios | ⏳ |
| E004 | Portal del Representante | ⏳ |
| E005 | Matrícula en línea | ⏳ |
| E006 | Información médica | ⏳ |
| E007 | Contactos de emergencia | ⏳ |
| E008 | Personas autorizadas para retiro | ⏳ |
| E009 | Transporte escolar | ⏳ |
| E010 | Revisión administrativa | ⏳ |
| E011 | Reportes iniciales | ⏳ |
| E012 | Optimización y estabilización | ⏳ |
| E013 | Preparación para producción | ⏳ |

---

# E000 — Preparación del proyecto y documentación IA

## Estado

✅ Completado

### Resultado

Se definieron:

- contexto del proyecto;
- arquitectura;
- estándares de programación;
- decisiones arquitectónicas;
- metodología de trabajo;
- documentación base para IA.

---

# E001 — Framework base

## Estado

✅ Completado

### Entregables

- ✅ E001.1 Estructura del proyecto
- ✅ E001.2 Bootstrap
- ✅ E001.3 Configuración y entorno
- ✅ E001.4 Application
- ✅ E001.5 Router
- ✅ E001.6 Request
- ✅ E001.7 Kernel
- ✅ E001.8 Response
- ✅ E001.9 Controller + View
- ✅ E001.10 Layout

### Resultado

Framework MVC funcional compuesto por:

- Bootstrap
- Configuración por entorno
- Application
- Kernel
- Router
- Request
- Response
- Renderizador de vistas
- Layout base
- Controlador base

A partir de este punto el desarrollo se centrará en funcionalidades del producto.

---

# E002 — Autenticación (Siguiente entregable)

## Estado

⏳ En desarrollo

### Objetivo funcional

Permitir que un usuario autorizado pueda iniciar sesión de forma segura y acceder al sistema.

### Alcance

- Configuración de base de datos
- Conexión PDO (Lazy Connection)
- Script SQL inicial
- Tabla de usuarios
- Usuario administrador inicial
- Inicio de sesión
- Cierre de sesión
- Manejo de sesiones
- Protección de rutas
- Página inicial autenticada

### Resultado esperado

Primer flujo funcional completo del sistema.

---

# E003 — Gestión de usuarios

## Objetivo funcional

Permitir a Secretaría administrar los usuarios que accederán al sistema.

### Alcance

- CRUD de usuarios
- Roles
- Estado del usuario
- Restablecimiento de contraseña
- Activación y desactivación

### Resultado esperado

Secretaría administra completamente los accesos al sistema.

---

# E004 — Portal del Representante

## Objetivo funcional

Permitir que el representante legal complete y mantenga actualizada su información desde un portal web.

### Resultado esperado

El representante puede administrar su información sin intervención de Secretaría.

---

# E005 — Matrícula en línea

## Objetivo funcional

Digitalizar completamente el proceso de matrícula.

### Resultado esperado

El representante completa el proceso de matrícula desde el portal.

---

# E006 — Información médica

## Objetivo funcional

Administrar toda la información médica relevante del estudiante.

### Resultado esperado

La institución dispone de un expediente médico actualizado para cada estudiante.

---

# E007 — Contactos de emergencia

## Objetivo funcional

Registrar y administrar los contactos autorizados para emergencias.

### Resultado esperado

Cada estudiante cuenta con información de contacto confiable y actualizada.

---

# E008 — Personas autorizadas para retiro

## Objetivo funcional

Administrar las personas autorizadas para retirar al estudiante.

### Resultado esperado

El personal dispone de un listado actualizado para validar retiros.

---

# E009 — Transporte escolar

## Objetivo funcional

Administrar la información relacionada con el transporte del estudiante.

### Resultado esperado

La institución dispone de información organizada sobre rutas y responsables del transporte.

---

# E010 — Revisión administrativa

## Objetivo funcional

Permitir a Secretaría revisar, validar y aprobar la información ingresada por los representantes.

### Resultado esperado

La información queda validada antes del inicio del año lectivo.

---

# E011 — Reportes iniciales

## Objetivo funcional

Generar reportes básicos para apoyar el proceso administrativo.

### Resultado esperado

Secretaría dispone de información consolidada para la gestión del proceso de matrícula.

---

# E012 — Optimización y estabilización

## Objetivo funcional

Optimizar el rendimiento, corregir incidencias y preparar el sistema para su uso institucional.

---

# E013 — Preparación para producción

## Objetivo funcional

Preparar el sistema para su despliegue comercial.

Incluye:

- seguridad;
- optimización;
- respaldos;
- monitoreo;
- documentación final;
- empaquetado para distribución.

---

# Add-ons futuros

Los siguientes módulos no forman parte del MVP y se desarrollarán posteriormente como complementos del producto:

- Finanzas
- Facturación
- Calificaciones
- Asistencia
- Horarios
- Biblioteca
- Inventario
- Talento humano
- Comunicación institucional
- Analítica
- Integraciones externas

---

# Criterios para finalizar un entregable

Un entregable se considera terminado únicamente cuando:

- cumple el objetivo funcional definido;
- funciona correctamente;
- supera las pruebas establecidas;
- ha sido revisado técnicamente;
- se realizó el commit correspondiente;
- se actualizó este ROADMAP.

---

# Flujo de trabajo

Cada entregable seguirá el siguiente ciclo:

1. Definición del objetivo.
2. Implementación.
3. Pruebas.
4. Revisión técnica.
5. Correcciones.
6. Commit.
7. Actualización del ROADMAP.

---

# Gestión del alcance

Durante un entregable:

- no se incorporarán funcionalidades de entregables futuros;
- no se realizarán optimizaciones prematuras;
- no se modificarán decisiones arquitectónicas salvo necesidad técnica justificada.

---

# Gestión de cambios

Toda mejora identificada durante el desarrollo será evaluada antes de incorporarse al roadmap.

La prioridad será completar funcionalidades utilizables del producto antes de ampliar el alcance del sistema.

---

# Criterios de calidad

Todo entregable deberá cumplir:

- arquitectura definida;
- estándares de programación;
- reglas del dominio;
- AI_DATABASE_RULES;
- SECURITY_GUIDELINES;
- pruebas satisfactorias;
- documentación actualizada.

---

# Evolución del roadmap

Este documento se actualizará únicamente cuando:

- un entregable cambie de estado;
- se incorpore un nuevo entregable;
- se elimine un entregable;
- cambie la planificación general del proyecto.

No deberá modificarse durante el desarrollo normal de una funcionalidad.

---


# Evolución del Documento

**Versión:** 2.1

Este roadmap representa el estado oficial del proyecto y deberá mantenerse sincronizado con los entregables implementados.

No sustituye la documentación técnica ni las ADR, sino que refleja el avance funcional del producto.
