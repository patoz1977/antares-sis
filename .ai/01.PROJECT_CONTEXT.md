# PROJECT_CONTEXT

**Proyecto:** Antares SIS  
**Versión:** 1.1  
**Estado:** Activo

---

# Propósito de este documento

Este documento es el punto de entrada para cualquier asistente de inteligencia artificial que participe en el desarrollo del proyecto.

Antes de generar, modificar o eliminar código, la IA deberá leer este documento y utilizarlo como contexto general del proyecto.

Su función es proporcionar la visión global del sistema e indicar qué documentación especializada debe consultarse según la naturaleza de cada tarea.

---

# Visión del producto

Antares SIS es un Sistema de Gestión Escolar (School Information System - SIS) desarrollado como un producto comercial.

La primera institución que utilizará el sistema será la Unidad Educativa Antares, pero el software deberá ser completamente reutilizable por cualquier institución educativa.

El producto será:

- Multiinstitución.
- White Label.
- Responsive.
- Mobile First.
- Escalable.
- Mantenible.
- Modular.

El objetivo a largo plazo es evolucionar hacia una plataforma SaaS.

---

# Objetivos del proyecto

Los objetivos principales son:

- Construir un sistema sólido y mantenible.
- Reducir la deuda técnica.
- Facilitar el crecimiento del producto.
- Permitir incorporar nuevos módulos sin afectar los existentes.
- Mantener una arquitectura consistente durante toda la vida del proyecto.
- Producir código limpio, simple y fácil de mantener.

---

# Alcance actual

La primera fase del proyecto desarrolla el Portal del Representante.

Funcionalidades iniciales:

- Autenticación.
- Gestión de familias.
- Gestión de estudiantes.
- Matrícula en línea.
- Información médica.
- Contactos de emergencia.
- Personas autorizadas para retiro.
- Transporte.
- Facturación.
- Revisión administrativa.

---

# Documentación oficial del proyecto

Antes de generar código, la IA deberá identificar qué documentos son relevantes para la tarea.

- Contexto general → PROJECT_CONTEXT.md
- Arquitectura → ARCHITECTURE.md
- Reglas del negocio → DOMAIN_MODEL.md
- Diseño físico → DATABASE_DESIGN.md
- Relaciones entre tablas → ERD.md
- Catálogos → CATALOGS.md
- Seguridad → SECURITY_GUIDELINES.md
- Estándares de programación → CODING_STANDARDS.md
- Decisiones arquitectónicas → DECISIONS.md
- Planificación → ROADMAP.md

No deberá generarse código sin consultar previamente la documentación pertinente.

---

# Flujo de trabajo

1. Definición del objetivo.
2. Revisión de la documentación aplicable.
3. Generación del código.
4. Revisión técnica.
5. Pruebas.
6. Correcciones.
7. Commit.

---

# Instrucciones para cualquier IA

Antes de comenzar cualquier tarea:

1. Leer PROJECT_CONTEXT.md.
2. Identificar los documentos especializados necesarios.
3. Consultarlos antes de generar código.
4. Respetar la arquitectura existente.
5. Mantener la consistencia del proyecto.
6. No inventar nuevas convenciones.
7. No modificar el alcance del entregable.

Si existe una duda sobre arquitectura o reglas de negocio:

- Detener la generación de código.
- Solicitar una decisión.
- Nunca asumir.
- Nunca improvisar.

---

# Documentos del proyecto

- PROJECT_CONTEXT.md
- ARCHITECTURE.md
- DOMAIN_MODEL.md
- DATABASE_DESIGN.md
- ERD.md
- CATALOGS.md
- SECURITY_GUIDELINES.md
- CODING_STANDARDS.md
- ROADMAP.md
- DECISIONS.md
- PROMPT_TEMPLATE.md
- README.md

Cada documento tiene una responsabilidad específica y no deberá duplicar información contenida en otro.

---

# Estado del documento

**Versión:** 1.1

Este documento deberá modificarse únicamente cuando cambie la visión general del proyecto o la forma oficial de trabajo.
