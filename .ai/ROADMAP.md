# ROADMAP

**Proyecto:** Antares SIS  
**Versión:** 1.0

---

# Propósito

Este documento define el plan de desarrollo del proyecto Antares SIS.

Su objetivo es proporcionar una visión clara del estado del proyecto, los entregables completados, los entregables en desarrollo y los planificados.

No sustituye la documentación técnica de cada módulo.

---

# Estado general del proyecto

**Estado actual:** E001 — Foundation.

La documentación base del proyecto ha sido completada y el desarrollo de la infraestructura inicial del sistema es el siguiente paso.

---

# Objetivos generales

El proyecto persigue los siguientes objetivos:

- Construir un SIS moderno.
- Desarrollar un producto comercial.
- Ser multiinstitución.
- Ser White Label.
- Mantener una arquitectura limpia.
- Permitir crecimiento modular.
- Minimizar la deuda técnica.

---

# Fases del proyecto

El desarrollo se organiza en entregables incrementales.

Cada entregable debe ser:

- pequeño;
- verificable;
- funcional;
- revisado antes de continuar.

No se comenzará un nuevo entregable hasta haber validado el anterior.

---

# Estado de los entregables

| Código | Entregable | Estado |
|---------|------------|--------|
| E000 | Preparación del proyecto y documentación IA | ✅ Completado |
| E001 | Foundation (estructura base del sistema) | 🟡 En progreso |
| E002 | Autenticación | ⏳ Pendiente |
| E003 | Gestión de usuarios | ⏳ Pendiente |
| E004 | Portal del Representante | ⏳ Pendiente |
| E005 | Matrícula en línea | ⏳ Pendiente |
| E006 | Información médica | ⏳ Pendiente |
| E007 | Contactos de emergencia | ⏳ Pendiente |
| E008 | Personas autorizadas para retiro | ⏳ Pendiente |
| E009 | Transporte escolar | ⏳ Pendiente |
| E010 | Facturación | ⏳ Pendiente |
| E011 | Revisión administrativa | ⏳ Pendiente |
| E012 | Reportes iniciales | ⏳ Pendiente |
| E013 | Seguridad avanzada | ⏳ Pendiente |
| E014 | Optimización y estabilización | ⏳ Pendiente |
| E015 | Preparación para producción | ⏳ Pendiente |

---

# Entregable actual

## E001 — Foundation

Objetivo:

Construir la infraestructura mínima necesaria para comenzar el desarrollo funcional del sistema.

Incluye:

- estructura del proyecto;
- Composer;
- PSR-4;
- configuración;
- router;
- bootstrap;
- controlador base;
- vistas base;
- conexión PDO;
- manejo de errores;
- entorno de desarrollo funcional.

Estado:

En progreso.

---

# Próximo entregable

## E002 — Autenticación

Implementar el sistema de autenticación del proyecto respetando la arquitectura definida y utilizando la infraestructura creada durante E001.

---

# Criterios para considerar un entregable terminado

Un entregable solamente podrá marcarse como completado cuando:

- compile correctamente;
- funcione;
- haya sido revisado;
- pase las pruebas definidas;
- se haya realizado el commit correspondiente.

---

# Flujo de trabajo

Cada entregable seguirá el siguiente ciclo:

1. Definición del objetivo.
2. Generación de artefactos.
3. Incorporación al repositorio.
4. Ejecución de pruebas.
5. Revisión técnica.
6. Correcciones.
7. Commit.
8. Actualización del ROADMAP.

---

# Gestión del alcance

Durante un entregable:

- no se incorporarán funcionalidades de entregables futuros;
- no se realizarán optimizaciones prematuras;
- no se modificarán decisiones arquitectónicas salvo necesidad justificada.

---

# Gestión de cambios

Si durante el desarrollo aparece una mejora o nueva funcionalidad:

1. No se incorporará inmediatamente.
2. Se evaluará su impacto.
3. Si procede, se añadirá como un nuevo entregable o tarea futura.

Esto permite mantener el foco y evitar ampliar el alcance del trabajo en curso.

---

# Riesgos identificados

Los principales riesgos del proyecto son:

- crecimiento descontrolado del alcance;
- duplicación de lógica;
- incorporación de dependencias innecesarias;
- pérdida de consistencia arquitectónica;
- deuda técnica.

Cada decisión deberá buscar reducir estos riesgos.

---

# Criterios de calidad

Todo entregable deberá cumplir:

- arquitectura definida;
- estándares de programación;
- reglas del dominio;
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

# Estado del documento

Versión 1.0

Este documento constituye la planificación oficial de alto nivel del proyecto Antares SIS.