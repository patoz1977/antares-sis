# DECISIONS

**Proyecto:** Antares SIS
**Versión:** 1.0

---

# Propósito

Este documento registra las decisiones arquitectónicas y técnicas relevantes del proyecto.

Su objetivo es conservar el contexto detrás de cada decisión importante para evitar que, con el tiempo, se desconozca por qué fue adoptada.

No se registrarán cambios menores de implementación.

---

# Cuándo registrar una decisión

Debe registrarse una nueva decisión cuando se modifique alguno de los siguientes aspectos:

- Arquitectura.
- Stack tecnológico.
- Organización del proyecto.
- Modelo de dominio.
- Seguridad.
- Persistencia.
- Integraciones.
- Estrategias de despliegue.
- Cualquier decisión cuya reversión tenga un impacto importante.

---

# Formato de las decisiones

Cada decisión utilizará la siguiente estructura:

```text
ADR-XXXX

Estado

Fecha

Contexto

Decisión

Consecuencias
```

Los identificadores serán secuenciales.

Ejemplos:

- ADR-0001
- ADR-0002
- ADR-0003

---

# Estados permitidos

Cada decisión tendrá uno de los siguientes estados:

- Propuesta
- Aprobada
- Reemplazada
- Obsoleta

Normalmente las decisiones permanecerán en estado **Aprobada**.

No deberán eliminarse decisiones antiguas.

Si una decisión deja de ser válida, se marcará como:

- Reemplazada

o

- Obsoleta

según corresponda.

---

# Registro de decisiones

---

## ADR-0001

### Estado

Aprobada

### Fecha

2026-07

### Contexto

Se requiere una arquitectura sencilla, mantenible y compatible con hosting compartido, evitando dependencias innecesarias y facilitando la evolución hacia un producto comercial.

### Decisión

Utilizar una arquitectura MVC enriquecida con capas de Services y Repositories.

### Consecuencias

- Separación clara de responsabilidades.
- Menor acoplamiento.
- Mayor facilidad de mantenimiento.
- Facilidad para incorporar nuevos módulos.

---

## ADR-0002

### Estado

Aprobada

### Fecha

2026-07

### Contexto

Se requiere una solución ampliamente soportada y compatible con la mayoría de proveedores de hosting.

### Decisión

Utilizar PHP 8.2 como plataforma principal de desarrollo.

### Consecuencias

- Amplia compatibilidad.
- Buen rendimiento.
- Acceso a características modernas del lenguaje.
- Gran disponibilidad de proveedores de hosting.

---

## ADR-0003

### Estado

Aprobada

### Fecha

2026-07

### Contexto

Se requiere un mecanismo estándar para la carga automática de clases.

### Decisión

Utilizar Composer con PSR-4.

### Consecuencias

- Organización consistente del código.
- Eliminación de cargas manuales.
- Compatibilidad con librerías modernas.

---

## ADR-0004

### Estado

Aprobada

### Fecha

2026-07

### Contexto

El sistema debe ser reutilizable por múltiples instituciones educativas.

### Decisión

Construir el sistema con soporte nativo para múltiples instituciones y personalización White Label.

### Consecuencias

- Mayor reutilización.
- Escalabilidad comercial.
- Separación entre configuración e implementación.

---

## ADR-0005

### Estado

Aprobada

### Fecha

2026-07

### Contexto

Es necesario mantener una única fuente oficial para las reglas de negocio.

### Decisión

Toda regla del negocio se implementará exclusivamente en la capa Service.

### Consecuencias

- Controllers simples.
- Repositories especializados en persistencia.
- Mejor mantenibilidad.
- Reutilización de reglas de negocio.

---

## ADR-0006

### Estado

Aprobada

### Fecha

2026-07

### Contexto

El proyecto será desarrollado con apoyo de herramientas de inteligencia artificial.

### Decisión

Mantener una carpeta `.ai` con la documentación de referencia utilizada tanto por desarrolladores como por asistentes de IA.

### Consecuencias

- Mayor consistencia en la generación de código.
- Reducción de decisiones repetitivas.
- Incorporación más rápida de nuevos colaboradores.

---

## ADR-0007

### Estado

Aprobada

### Fecha

2026-07

### Contexto

El objetivo del proyecto es desarrollar un Sistema de Información Escolar comercial con soporte para múltiples instituciones y White Label.

Utilizar el nombre "Antares" como namespace del framework acoplaría la infraestructura a una institución específica.

### Decisión

Separar la infraestructura del framework de la aplicación mediante dos namespaces independientes:

```text
Core\
App\
```

El namespace `Core` contendrá exclusivamente la infraestructura técnica del framework.

El namespace `App` contendrá toda la lógica específica de la aplicación.

### Consecuencias

- El framework podrá reutilizarse en cualquier institución.
- La marca Antares queda limitada a la configuración del producto.
- Se facilita el mantenimiento y la evolución del núcleo.
- Se reduce el acoplamiento entre infraestructura y dominio.
---

# Reglas de mantenimiento

Las decisiones existentes no deberán modificarse para cambiar su significado histórico.

Si una decisión cambia:

1. Se crea una nueva ADR.
2. Se referencia la ADR anterior.
3. La decisión previa pasa a estado **Reemplazada** cuando corresponda.

De esta forma se conserva el historial del proyecto.

---

# Decisiones no registradas

No deben registrarse como ADR:

- Correcciones de errores.
- Refactorizaciones menores.
- Cambios de nombres.
- Mejoras cosméticas.
- Ajustes de formato.
- Cambios de documentación.

Las ADR deben reservarse para decisiones con impacto arquitectónico o estratégico.

---

# Referencias

Las decisiones documentadas en este archivo complementan, pero no sustituyen, los siguientes documentos:

- PROJECT_CONTEXT.md
- ARCHITECTURE.md
- DOMAIN_MODEL.md
- CODING_STANDARDS.md
- ROADMAP.md

Cuando exista una discrepancia entre documentos, deberá revisarse si corresponde crear una nueva ADR antes de modificar la documentación existente.

---

# Estado del documento

Versión 1.0

Este documento constituye el registro oficial de decisiones arquitectónicas del proyecto Antares SIS.
