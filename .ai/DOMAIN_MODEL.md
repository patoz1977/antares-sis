# DOMAIN_MODEL

**Proyecto:** Antares SIS  
**Versión:** 1.0

---

# Propósito

Este documento define el modelo de dominio del proyecto Antares SIS.

Describe las entidades principales, sus relaciones y las reglas de negocio que deberán respetarse durante el desarrollo.

No representa el diseño físico de la base de datos. Su objetivo es modelar el negocio.

---

# Visión del dominio

Antares SIS es un Sistema de Gestión Escolar (School Information System - SIS) orientado a administrar los procesos académicos y administrativos de una institución educativa.

El dominio se organiza en módulos funcionales independientes, relacionados entre sí mediante entidades comunes.

La primera fase del proyecto implementará el Portal del Representante y el proceso de matrícula en línea.

---

# Principios del modelo de dominio

- Cada entidad representa un concepto real del negocio.
- Las relaciones deben reflejar la realidad institucional.
- No se duplicará información.
- Cada dato tendrá una única fuente de verdad.
- El modelo debe permitir crecer sin rediseños importantes.

---

# Entidades principales

## Institution

Representa una institución educativa.

Ejemplos:

- Colegio Antares
- Unidad Educativa Demo

Responsabilidades:

- Identidad institucional.
- Configuración.
- Personalización visual.
- Parámetros generales.

---

## AcademicYear

Representa un período académico.

Ejemplos:

- 2026-2027
- 2027-2028

Responsabilidades:

- Fechas oficiales.
- Estado del período.
- Configuración académica.

---

## Campus

Representa una sede física.

Una institución puede tener múltiples sedes.

---

## Grade

Representa un grado académico.

Ejemplos:

- Inicial 2
- Primero EGB
- Segundo Bachillerato

---

## Section

Representa un paralelo.

Ejemplos:

- A
- B
- C

---

## Student

Representa un estudiante.

Información típica:

- identificación
- nombres
- apellidos
- fecha de nacimiento
- sexo
- fotografía
- estado

---

## Guardian

Representa al representante legal o responsable económico.

Un representante puede estar asociado a varios estudiantes.

---

## Family

Agrupa estudiantes pertenecientes al mismo núcleo familiar.

Facilita:

- facturación
- descuentos
- comunicación
- matrícula

---

## Enrollment

Representa el proceso de matrícula de un estudiante.

Incluye:

- período académico
- grado
- paralelo
- estado
- fecha

Cada estudiante tendrá una matrícula por período académico.

---

## MedicalRecord

Información médica del estudiante.

Ejemplos:

- alergias
- medicamentos
- enfermedades
- restricciones

---

## EmergencyContact

Persona autorizada para ser contactada en caso de emergencia.

Un estudiante puede tener varios contactos.

---

## AuthorizedPickup

Persona autorizada para retirar al estudiante.

Puede incluir:

- parentesco
- fotografía
- documento
- observaciones

---

## Transportation

Información del transporte escolar.

Puede ser:

- institucional
- externo
- particular

---

## Invoice

Documento de facturación.

---

## Payment

Registro de pagos.

---

## User

Cuenta utilizada para acceder al sistema.

No representa una persona.

Representa credenciales.

---

## Role

Rol asignado a un usuario.

Ejemplos:

- Administrador
- Representante
- Secretaría
- Tesorería

---

## Permission

Permiso específico del sistema.

---

# Relaciones principales

## Institution

Tiene muchos:

- AcademicYear
- Campus
- Users

---

## AcademicYear

Tiene muchos:

- Enrollments

---

## Family

Tiene muchos:

- Students

---

## Guardian

Puede representar:

- uno o varios estudiantes.

---

## Student

Pertenece a:

- Family

Tiene:

- MedicalRecord
- varios EmergencyContact
- varios AuthorizedPickup
- varias Enrollment

---

## Enrollment

Pertenece a:

- Student
- AcademicYear
- Grade
- Section

---

## User

Tiene uno o varios Roles.

---

## Role

Tiene múltiples Permissions.

---

# Reglas de negocio

## Matrícula

Un estudiante solamente podrá tener una matrícula activa por período académico.

---

## Representantes

Un representante podrá estar asociado a múltiples estudiantes.

Un estudiante podrá tener varios representantes.

Siempre deberá existir al menos un representante principal.

---

## Contactos de emergencia

Cada estudiante deberá tener al menos un contacto de emergencia.

---

## Personas autorizadas

El retiro del estudiante únicamente podrá realizarlo una persona autorizada.

---

## Información médica

Toda actualización deberá conservar historial cuando la funcionalidad de auditoría esté disponible.

---

## Facturación

La factura pertenece a una familia.

No al estudiante.

---

## Pagos

Un pago podrá cubrir:

- una factura;
- varias facturas;
- un saldo parcial.

---

## Usuarios

Las credenciales pertenecen al usuario.

Los datos personales pertenecen a la persona.

Nunca deberán mezclarse ambos conceptos.

---

# Estados

## Student

Posibles estados:

- Activo
- Inactivo
- Retirado
- Graduado

---

## Enrollment

Posibles estados:

- Borrador
- Pendiente
- En revisión
- Aprobada
- Rechazada
- Anulada

---

## AcademicYear

Posibles estados:

- Planificación
- Activo
- Cerrado

---

# Auditoría

Las entidades críticas deberán poder registrar:

- creación
- modificación
- eliminación lógica
- usuario responsable
- fecha y hora

---

# Eliminación lógica

Las entidades funcionales utilizarán Soft Delete cuando corresponda.

No deberán eliminarse físicamente registros históricos importantes.

Ejemplos:

- estudiantes
- matrículas
- facturas
- pagos

---

# Convenciones para identificadores

Cada entidad tendrá una clave primaria única.

Las claves primarias serán enteros autoincrementales durante la primera versión del sistema.

En el futuro podrán sustituirse por UUID sin afectar el dominio.

---

# Convenciones para nombres

Las entidades utilizarán nombres en singular.

Ejemplos:

Correcto:

- Student
- Family
- Enrollment

Incorrecto:

- Students
- Families
- Enrollments

---

# Reglas para relaciones

Las relaciones deberán representarse mediante claves foráneas.

No deberán duplicarse datos ya existentes en otras entidades.

---

# Extensibilidad

El modelo deberá permitir incorporar nuevos módulos sin alterar las entidades existentes.

Ejemplos:

- Biblioteca
- Inventario
- Recursos Humanos
- CRM
- Portal Docente
- Portal del Estudiante
- Nómina
- Admisiones

---

# Restricciones

No deberán implementarse reglas de negocio directamente en la base de datos.

Las reglas del dominio pertenecen a la capa de Services.

La base de datos garantizará únicamente la integridad de la información.

---

# Evolución

Toda modificación importante del modelo de dominio deberá registrarse previamente en `DECISIONS.md`.

---

# Estado del documento

Versión 1.0

Este documento constituye la referencia oficial del dominio del negocio para Antares SIS.