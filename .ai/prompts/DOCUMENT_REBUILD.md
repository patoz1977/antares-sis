# DOCUMENT_REBUILD.md

## Objetivo

Reconstruir completamente un documento del proyecto a partir de las fuentes oficiales, manteniendo consistencia con la arquitectura, el dominio y las decisiones documentadas.

---

# Principios

- GitHub es la fuente oficial del proyecto.
- La carpeta `.ai` contiene la documentación oficial.
- No utilizar conversaciones anteriores como fuente de verdad.
- No asumir reglas de negocio.
- No inventar atributos, entidades, relaciones o procesos.
- Ante cualquier contradicción o vacío indispensable, detener la reconstrucción.
- Priorizar consistencia sobre velocidad.

---

# Entradas

Antes de comenzar, deben proporcionarse:

- Documento objetivo.
- Lista de documentos fuente.
- Archivos autorizados para modificación.
- Mensaje de commit.
- Estado esperado del árbol de trabajo (opcional).

---

# Procedimiento

## 1. Preparación

- Verificar que la rama sea la correcta.
- Verificar el estado del árbol de trabajo.
- Si existen cambios:
  - continuar únicamente cuando todos los cambios correspondan a archivos expresamente autorizados para esta tarea o hayan sido informados por el usuario;
  - detenerse únicamente si existen cambios inesperados que puedan interferir con la reconstrucción.
- Confirmar el documento objetivo.
- Leer completamente las fuentes en el orden indicado.

---

## 2. Análisis

Construir una comprensión completa del documento antes de escribir.

Identificar:

- contradicciones;
- reglas incompletas;
- dependencias faltantes;
- decisiones físicas o funcionales no documentadas.

Si alguna de ellas impide generar correctamente el documento:

- detenerse;
- no modificar archivos;
- no hacer commit;
- reportar únicamente los bloqueos encontrados.

---

## 3. Reconstrucción

Generar el documento completo en memoria.

No escribir parcialmente.

No dejar documentos incompletos.

Mantener:

- estructura consistente;
- terminología oficial;
- numeración;
- formato del proyecto;
- ausencia de duplicación.

---

## 4. Escritura

Una vez validado el documento completo:

- reemplazar el contenido completo del archivo objetivo;
- verificar que el archivo no esté truncado;
- confirmar que el archivo pueda abrirse correctamente.

---

## 5. Validación

Antes del commit verificar:

- coherencia interna;
- coherencia con las fuentes;
- ausencia de contradicciones;
- documento completo;
- git diff.

Confirmar que únicamente cambiaron los archivos autorizados.

---

## 6. Commit

Realizar commit únicamente cuando:

- no existan bloqueos;
- todas las validaciones sean satisfactorias.

Utilizar exclusivamente el mensaje de commit indicado.

---

## 7. Reporte

Al finalizar informar:

- resultado (éxito o bloqueo);
- archivos modificados;
- decisiones nuevas detectadas;
- bloqueos encontrados;
- validaciones ejecutadas;
- SHA del commit (si existió).

---

# Restricciones

Nunca:

- modificar documentos no autorizados;
- modificar código cuando el trabajo sea documental;
- modificar documentación oficial sin autorización explícita;
- resolver contradicciones mediante supuestos;
- continuar la reconstrucción después de detectar un bloqueo.

---

# Criterio de finalización

La tarea se considera terminada únicamente cuando:

- el documento quedó completamente reconstruido;
- pasó todas las validaciones;
- el repositorio permanece consistente;
- el commit fue realizado (si correspondía);
- el reporte final fue entregado.
