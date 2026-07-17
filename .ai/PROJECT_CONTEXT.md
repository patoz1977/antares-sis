# PROJECT_CONTEXT

**Proyecto:** Antares SIS  
**Versión:** 1.0  
**Estado:** Activo

---

# Propósito de este documento

Este documento es el punto de entrada para cualquier asistente de inteligencia artificial que participe en el desarrollo del proyecto.

Antes de generar, modificar o eliminar código, la IA deberá leer este documento y utilizarlo como contexto general del proyecto.

Este documento no contiene toda la documentación técnica. Su función es indicar la visión general del sistema y los documentos que deben consultarse para cada tarea.

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

Las funcionalidades iniciales incluyen:

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

Los módulos futuros se incorporarán posteriormente.

---

# Principios del proyecto

Todo el desarrollo deberá respetar los siguientes principios.

## Simplicidad

La solución más simple que resuelva correctamente el problema será la preferida.

No debe agregarse complejidad innecesaria.

---

## Mantenibilidad

El código deberá ser sencillo de comprender.

Las clases pequeñas son preferibles a clases muy grandes.

Las responsabilidades deben estar claramente separadas.

---

## Escalabilidad

Toda funcionalidad deberá permitir el crecimiento futuro del sistema.

Las decisiones de diseño deberán favorecer la incorporación de nuevos módulos.

---

## Reutilización

Las soluciones deberán poder utilizarse por cualquier institución educativa.

No deberán incorporarse reglas específicas del Colegio Antares dentro del código.

---

## Consistencia

Todo el proyecto deberá seguir las mismas convenciones.

No deberán coexistir múltiples estilos de programación.

---

# Arquitectura

La arquitectura del sistema está definida en:

ARCHITECTURE.md

No debe modificarse sin una decisión explícita del proyecto.

---

# Modelo de dominio

Las entidades, relaciones y reglas del negocio se encuentran definidas en:

DOMAIN_MODEL.md

Toda implementación deberá respetar dicho modelo.

---

# Estándares de programación

Las convenciones de código se encuentran definidas en:

CODING_STANDARDS.md

Toda implementación deberá cumplirlas.

---

# Roadmap

El estado del proyecto se encuentra definido en:

ROADMAP.md

No deberán implementarse funcionalidades pertenecientes a entregables futuros.

---

# Decisiones arquitectónicas

Las decisiones relevantes del proyecto se registran en:

DECISIONS.md

Antes de modificar una decisión existente deberá consultarse dicho documento.

---

# Flujo de trabajo

Cada entregable seguirá el siguiente proceso:

1. Definición del objetivo.
2. Generación del código.
3. Revisión técnica.
4. Pruebas.
5. Correcciones.
6. Commit.

No deberá omitirse ninguna etapa.

---

# Convenciones generales

## Idioma

La documentación del proyecto se escribirá en español.

El código fuente se escribirá en inglés.

La interfaz del usuario se desarrollará inicialmente en español.

---

## Frameworks

No deberán introducirse frameworks distintos de los aprobados por el proyecto.

---

## Arquitectura

No deberán inventarse nuevas capas arquitectónicas.

---

## Dependencias

Toda nueva dependencia deberá estar justificada.

---

## Refactorización

No deberá modificarse código estable sin una razón funcional.

---

## Compatibilidad

El sistema deberá funcionar en hosting compartido.

---

# Instrucciones para cualquier IA

Antes de comenzar cualquier tarea:

1. Leer PROJECT_CONTEXT.md.
2. Consultar los documentos especializados necesarios.
3. Respetar la arquitectura existente.
4. Mantener la consistencia del proyecto.
5. No inventar nuevas convenciones.
6. No modificar el alcance del entregable.

Cuando exista una duda sobre arquitectura o reglas de negocio:

Detener la generación de código y solicitar una decisión.

Nunca asumir.

Nunca improvisar.

---

# Documentos del proyecto

La carpeta `.ai` contiene los siguientes documentos:

- PROJECT_CONTEXT.md
- ARCHITECTURE.md
- DOMAIN_MODEL.md
- CODING_STANDARDS.md
- ROADMAP.md
- DECISIONS.md
- PROMPT_TEMPLATE.md
- README.md

Cada documento tiene una responsabilidad específica.

No deberá duplicarse información entre ellos.

---

# Estado del documento

Versión: 1.0

Este documento deberá modificarse únicamente cuando cambie la visión general del proyecto o la forma de trabajo.