# Carpeta `.ai`

**Proyecto:** Antares SIS

---

# Propósito

La carpeta `.ai` contiene la documentación base del proyecto utilizada como referencia por los desarrolladores y por los asistentes de inteligencia artificial que colaboran en el desarrollo.

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
| `CODING_STANDARDS.md` | Convenciones y estándares de programación. |
| `ROADMAP.md` | Estado del proyecto y planificación de entregables. |
| `DECISIONS.md` | Registro de decisiones arquitectónicas (ADR). |
| `PROMPT_TEMPLATE.md` | Plantilla oficial para interactuar con asistentes de IA. |
| `README.md` | Guía de uso de esta carpeta. |

---

# Cómo utilizar esta documentación

Antes de comenzar cualquier desarrollo se recomienda revisar los documentos en el siguiente orden:

1. `PROJECT_CONTEXT.md`
2. `ARCHITECTURE.md`
3. `DOMAIN_MODEL.md`
4. `CODING_STANDARDS.md`
5. `ROADMAP.md`
6. `DECISIONS.md`

El archivo `PROMPT_TEMPLATE.md` debe utilizarse como base para solicitar implementaciones a herramientas de inteligencia artificial.

---

# Responsabilidad de cada documento

Cada archivo tiene una única responsabilidad.

No debe duplicarse información entre documentos.

Como referencia:

- **PROJECT_CONTEXT** responde: *¿Qué es el proyecto?*
- **ARCHITECTURE** responde: *¿Cómo está construido?*
- **DOMAIN_MODEL** responde: *¿Cómo funciona el negocio?*
- **CODING_STANDARDS** responde: *¿Cómo escribimos el código?*
- **ROADMAP** responde: *¿Qué estamos desarrollando?*
- **DECISIONS** responde: *¿Por qué se tomaron ciertas decisiones?*
- **PROMPT_TEMPLATE** responde: *¿Cómo interactuamos con la IA?*

---

# Mantenimiento

La documentación deberá actualizarse únicamente cuando cambie alguno de los siguientes aspectos:

- visión del proyecto;
- arquitectura;
- modelo de dominio;
- estándares de programación;
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
9. Actualizar el `ROADMAP.md` cuando cambie el estado del proyecto.

---

# Convenciones

- La documentación del proyecto se redacta en español.
- El código fuente se escribe en inglés.
- La arquitectura definida en esta carpeta es la referencia oficial del proyecto.
- Toda excepción deberá registrarse en `DECISIONS.md`.

---

# Relación con el repositorio

La carpeta `.ai` no contiene código ejecutable.

Su función es documentar el contexto del proyecto para facilitar el desarrollo, la revisión técnica y la colaboración entre personas y herramientas de inteligencia artificial.

No sustituye la documentación funcional ni la documentación técnica del producto.

---

# Evolución

Esta carpeta crecerá junto con el proyecto.

Podrán incorporarse nuevos documentos cuando exista una necesidad clara y aporten valor al desarrollo, procurando mantener una estructura sencilla y evitar duplicidades.

---

# Estado

Versión 1.0

Con este documento se completa la documentación base de la carpeta `.ai` para la primera fase del desarrollo de Antares SIS.