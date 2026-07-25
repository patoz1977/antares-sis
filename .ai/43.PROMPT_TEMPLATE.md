# PROMPT_TEMPLATE

**Proyecto:** Antares SIS  
**Versión:** 1.0

---

# Propósito

Este documento define la plantilla oficial para solicitar implementaciones mediante herramientas de inteligencia artificial.

Su objetivo es garantizar que todas las respuestas generadas sean consistentes con la arquitectura, el modelo de dominio y los estándares del proyecto.

Antes de solicitar cualquier implementación, la IA deberá asumir que ya ha leído los documentos de la carpeta `.ai`.

---

# Documentos de referencia

Antes de generar código deberán considerarse los siguientes documentos:

- PROJECT_CONTEXT.md
- ARCHITECTURE.md
- DOMAIN_MODEL.md
- CODING_STANDARDS.md
- ROADMAP.md
- DECISIONS.md

No deberán contradecirse las decisiones contenidas en dichos documentos.

---

# Plantilla general

Copiar la siguiente plantilla y completar únicamente la sección **Solicitud**.

```
Contexto

Estás colaborando en el desarrollo del proyecto Antares SIS.

Antes de generar cualquier código debes respetar la documentación contenida en la carpeta .ai del proyecto.

Debes asumir que ya conoces:

- PROJECT_CONTEXT.md
- ARCHITECTURE.md
- DOMAIN_MODEL.md
- CODING_STANDARDS.md
- ROADMAP.md
- DECISIONS.md

No modifiques la arquitectura.

No cambies las convenciones.

No agregues nuevas dependencias.

No amplíes el alcance solicitado.

Implementa únicamente lo solicitado.

Entrega únicamente código listo para incorporarse al proyecto.

Solicitud

<describir aquí el trabajo solicitado>
```

---

# Reglas para solicitudes

Cada solicitud deberá:

- describir un único objetivo;
- corresponder a un único entregable;
- evitar múltiples funcionalidades simultáneas;
- indicar claramente el resultado esperado.

Solicitudes pequeñas producen mejores resultados.

---

# Información que debe incluir una solicitud

Siempre que sea posible indicar:

- objetivo;
- archivos afectados;
- comportamiento esperado;
- restricciones;
- criterios de aceptación.

---

# Información que no debe incluirse

No repetir información ya documentada en la carpeta `.ai`.

Ejemplos:

- arquitectura;
- convenciones;
- reglas generales;
- stack tecnológico.

La IA deberá asumir dichas reglas automáticamente.

---

# Qué debe generar la IA

Las respuestas deberán contener únicamente lo necesario para completar la tarea.

Dependiendo del caso, la respuesta podrá incluir:

- código;
- archivos nuevos;
- modificaciones de archivos existentes;
- migraciones;
- pruebas;
- instrucciones de incorporación.

No deberá generar documentación adicional salvo que se solicite expresamente.

---

# Qué no debe hacer la IA

La IA no deberá:

- cambiar la arquitectura;
- modificar decisiones aprobadas;
- agregar librerías;
- refactorizar módulos no relacionados;
- cambiar nombres arbitrariamente;
- implementar funcionalidades futuras;
- modificar el alcance del entregable.

---

# Ejemplo 1

## Solicitud

```
Implementar el Router del entregable E001.

Debe permitir registrar rutas GET y POST.

No implementar middleware todavía.

Debe respetar PSR-4 y la arquitectura definida.
```

---

# Ejemplo 2

## Solicitud

```
Implementar el StudentRepository.

Debe utilizar PDO.

No debe contener reglas del negocio.

Debe utilizar Prepared Statements.

No implementar pruebas todavía.
```

---

# Ejemplo 3

## Solicitud

```
Implementar EnrollmentService.

Debe contener exclusivamente las reglas de negocio relacionadas con la matrícula.

No acceder directamente a PDO.

Utilizar el Repository correspondiente.
```

---

# Lista de verificación

Antes de utilizar una respuesta generada por IA verificar:

- ¿Respeta la arquitectura?
- ¿Respeta el modelo de dominio?
- ¿Cumple los estándares de programación?
- ¿No incorpora dependencias nuevas?
- ¿No amplía el alcance?
- ¿Compila correctamente?
- ¿Es consistente con el resto del proyecto?

Si alguna respuesta es negativa, revisar antes de incorporar el código.

---

# Flujo recomendado

Para cada entregable utilizar el siguiente proceso:

1. Definir el objetivo.
2. Preparar la solicitud.
3. Generar el código con la IA.
4. Revisar el resultado.
5. Incorporar al repositorio.
6. Ejecutar pruebas.
7. Corregir si es necesario.
8. Realizar el commit.
9. Actualizar el ROADMAP.

---

# Buenas prácticas

- Solicitar una única funcionalidad por vez.
- Evitar solicitudes demasiado amplias.
- Revisar siempre el código generado.
- Mantener las respuestas pequeñas y enfocadas.
- Preferir varias iteraciones cortas antes que una única solicitud muy grande.

---

# Estado del documento

Versión 1.0

Este documento constituye la plantilla oficial para interactuar con asistentes de inteligencia artificial durante el desarrollo de Antares SIS.