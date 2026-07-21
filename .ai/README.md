# Carpeta `.ai`

**Proyecto:** Antares SIS
**Versión:** 1.1

---

# Propósito

La carpeta `.ai` contiene la documentación oficial del proyecto utilizada como referencia por los desarrolladores y por los asistentes de inteligencia artificial que colaboran en el desarrollo.

Su objetivo es mantener un contexto único, consistente y actualizado para reducir la ambigüedad, evitar decisiones repetitivas y garantizar que todas las implementaciones respeten la arquitectura del sistema.

Esta documentación forma parte del proyecto y debe mantenerse sincronizada con su evolución.

---

# Contenido

La carpeta contiene los siguientes documentos:

| Archivo | Propósito |
|----------|-----------|
| `PROJECT_CONTEXT.md` | Visión general del proyecto y reglas globales. |
| `ARCHITECTURE.md` | Arquitectura oficial del sistema. |
| `DOMAIN_MODEL.md` | Modelo de dominio y reglas del negocio. |
| `DATABASE_DESIGN.md` | Diseño físico de la base de datos. |
| `ERD.md` | Representación visual oficial del modelo físico. |
| `CATALOGS.md` | Catálogos oficiales y valores de referencia del sistema. |
| `CODING_STANDARDS.md` | Convenciones y estándares de programación. |
| `SECURITY_GUIDELINES.md` | Políticas y lineamientos de seguridad. |
| `ROADMAP.md` | Estado del proyecto y planificación de entregables. |
| `DECISIONS.md` | Registro de decisiones arquitectónicas (ADR). |
| `PROMPT_TEMPLATE.md` | Plantilla oficial para interactuar con asistentes de IA. |
| `README.md` | Guía de uso de esta carpeta. |

---

# Orden recomendado de lectura

Antes de comenzar cualquier desarrollo se recomienda revisar los documentos en el siguiente orden:

1. `PROJECT_CONTEXT.md`
2. `ARCHITECTURE.md`
3. `DOMAIN_MODEL.md`
4. `DATABASE_DESIGN.md`
5. `ERD.md`
6. `CATALOGS.md`
7. `SECURITY_GUIDELINES.md`
8. `CODING_STANDARDS.md`
9. `ROADMAP.md`
10. `DECISIONS.md`

El archivo `PROMPT_TEMPLATE.md` debe utilizarse como base para solicitar implementaciones a herramientas de inteligencia artificial.

---

# Responsabilidad de cada documento

Cada documento posee una única responsabilidad y constituye la referencia oficial para su ámbito.

No debe duplicarse información entre documentos.

Como referencia:

| Documento | Responde a la pregunta |
|------------|------------------------|
| **PROJECT_CONTEXT** | ¿Qué es el proyecto y cuáles son sus objetivos? |
| **ARCHITECTURE** | ¿Cómo está construido el sistema? |
| **DOMAIN_MODEL** | ¿Cómo funciona el negocio? |
| **DATABASE_DESIGN** | ¿Cómo se implementa físicamente el modelo de datos? |
| **ERD** | ¿Cómo se representan visualmente las entidades y relaciones? |
| **CATALOGS** | ¿Qué catálogos oficiales utiliza el sistema? |
| **CODING_STANDARDS** | ¿Cómo debe escribirse el código? |
| **SECURITY_GUIDELINES** | ¿Qué políticas de seguridad deben respetarse? |
| **ROADMAP** | ¿Qué se está desarrollando actualmente? |
| **DECISIONS** | ¿Por qué se tomaron determinadas decisiones? |
| **PROMPT_TEMPLATE** | ¿Cómo interactuar con los asistentes de IA? |

---

# Jerarquía documental

La documentación mantiene la siguiente jerarquía de autoridad:

1. `PROJECT_CONTEXT.md`
2. `ARCHITECTURE.md`
3. `DOMAIN_MODEL.md`
4. `DATABASE_DESIGN.md`
5. `ERD.md`
6. `CATALOGS.md`
7. `SECURITY_GUIDELINES.md`
8. `CODING_STANDARDS.md`
9. Código fuente

Cuando exista una aparente discrepancia entre documentos, prevalecerá el documento de mayor jerarquía.

El ERD constituye la representación visual oficial del modelo físico y deberá mantenerse siempre sincronizado con `DATABASE_DESIGN.md`.

---

# Mantenimiento

La documentación deberá actualizarse únicamente cuando cambie alguno de los siguientes aspectos:

- visión del proyecto;
- arquitectura;
- modelo de dominio;
- diseño físico de la base de datos;
- modelo entidad-relación;
- catálogos oficiales;
- estándares de programación;
- lineamientos de seguridad;
- planificación;
- decisiones arquitectónicas.

No debe modificarse por cambios menores de implementación.

---

# Flujo de trabajo recomendado

Para cada entregable del proyecto se seguirá el siguiente proceso:

1. Definir el objetivo del entregable.
2. Revisar la documentación relevante.
3. Generar los artefactos necesarios.
4. Incorporarlos al repositorio.
5. Ejecutar las pruebas.
6. Revisar el resultado.
7. Realizar las correcciones necesarias.
8. Crear el commit correspondiente.
9. Actualizar la documentación afectada.
10. Actualizar el `ROADMAP.md` cuando cambie el estado del proyecto.

---

# Convenciones

- La documentación del proyecto se redacta en español.
- El código fuente se escribe en inglés.
- La arquitectura definida en esta carpeta constituye la referencia oficial del proyecto.
- Toda excepción deberá registrarse en `DECISIONS.md`.
- Ningún documento deberá contradecir a otro de mayor jerarquía.

---

# Relación con el repositorio

La carpeta `.ai` no contiene código ejecutable.

Su función es documentar el contexto del proyecto para facilitar el desarrollo, la revisión técnica y la colaboración entre personas y herramientas de inteligencia artificial.

No sustituye la documentación funcional ni la documentación técnica del producto.

---

# Evolución

Esta carpeta evolucionará junto con el proyecto.

Podrán incorporarse nuevos documentos cuando exista una necesidad clara y aporten valor al desarrollo, procurando mantener una estructura sencilla, evitar duplicidades y preservar la coherencia arquitectónica.

---

# Estado

**Versión:** 1.1

Este documento constituye la guía oficial para comprender la organización, el propósito y la jerarquía de la documentación contenida en la carpeta `.ai`.
