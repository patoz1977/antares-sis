# DATABASE DESIGN

**Versión:** 1.0
**Estado:** En desarrollo
**Última actualización:** Julio 2026

---

# 1. Objetivo

Este documento define el diseño físico de la base de datos del Sistema de Información Escolar (SIS).

Su propósito es establecer una arquitectura de persistencia consistente, escalable y mantenible que traduzca el modelo de dominio definido en `DOMAIN_MODEL.md` a una implementación relacional en MySQL.

Este documento constituye la referencia oficial para:

- diseño de tablas;
- claves primarias y foráneas;
- restricciones;
- índices;
- convenciones de nombres;
- tipos de datos;
- reglas de integridad;
- migraciones.

No describe reglas de negocio. Dichas reglas pertenecen exclusivamente al modelo de dominio.

---

# 2. Objetivos del diseño

El diseño físico de la base de datos debe cumplir los siguientes objetivos:

- representar fielmente el modelo de dominio;
- minimizar la duplicación de datos;
- garantizar la integridad referencial;
- facilitar futuras ampliaciones;
- mantener consistencia entre módulos;
- optimizar consultas frecuentes;
- simplificar el mantenimiento.

El diseño debe privilegiar claridad y mantenibilidad sobre optimizaciones prematuras.

---

# 3. Principios Generales

## 3.1 El dominio gobierna la base de datos

La base de datos implementa el modelo definido en `DOMAIN_MODEL.md`.

Nunca deberá modificar el modelo de dominio para adaptarlo a limitaciones técnicas.

---

## 3.2 Una tabla por entidad

Cada entidad persistente del dominio tendrá su propia tabla.

Ejemplo:

- persons
- users
- representatives
- students

No se utilizarán tablas con múltiples responsabilidades.

---

## 3.3 Una tabla por Business Role

Cada Business Role tendrá su propia tabla.

Ejemplo:

- representatives
- students

Nunca existirán columnas como:

- is_student
- is_representative
- is_teacher

en la tabla `persons`.

Los roles evolucionan independientemente de la identidad.

---

## 3.4 Una sola fuente de identidad

Toda información personal reside exclusivamente en `persons`.

Ninguna otra tabla podrá duplicar información como:

- nombres;
- apellidos;
- documento;
- fecha de nacimiento;
- dirección;
- correo electrónico.

Las demás tablas referenciarán a `persons` mediante claves foráneas.

---

## 3.5 Normalización

El diseño seguirá, como mínimo, Tercera Forma Normal (3NF).

La desnormalización únicamente podrá realizarse cuando exista evidencia objetiva de un beneficio significativo de rendimiento.

Toda excepción deberá documentarse.

---

## 3.6 Integridad Referencial

Toda relación entre entidades deberá implementarse mediante claves foráneas.

No se permitirán referencias implícitas ni identificadores "huérfanos".

---

## 3.7 Evolución

El esquema deberá permitir incorporar nuevos módulos sin alterar las tablas existentes.

Ejemplos:

- docentes;
- empleados;
- exalumnos;
- biblioteca;
- calificaciones.

---

# 4. Convenciones de Nombres

La consistencia en los nombres es obligatoria.

## Tablas

Todas las tablas utilizarán:

- minúsculas;
- plural;
- snake_case.

Ejemplos:

```text
persons
users
students
medical_records
student_emergency_contacts
family_students
```

Nunca utilizar:

```text
Persons
tblPersons
studentProfile
```

---

## Columnas

Todas las columnas utilizarán:

- minúsculas;
- snake_case.

Ejemplos:

```text
first_name
last_name
birth_date
created_at
updated_at
```

---

## Claves Primarias

Todas las tablas utilizarán exactamente:

```text
id
```

Nunca:

```text
person_id
student_id
user_id
```

como clave primaria.

---

## Claves Foráneas

Las claves foráneas utilizarán el patrón:

```text
tabla_singular_id
```

Ejemplos:

```text
person_id
student_id
family_id
representative_id
```

---

## Tablas Relacionales

Las tablas puente utilizarán:

```text
entidad1_entidad2
```

Ejemplos:

```text
family_students

family_representatives

student_authorized_pickups
```

---

# 5. Claves Primarias

Todas las tablas utilizarán una clave primaria sustituta.

Características:

- BIGINT UNSIGNED;
- AUTO_INCREMENT;
- PRIMARY KEY.

Ejemplo:

```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
```

No se utilizarán claves naturales.

---

# 6. Claves Foráneas

Todas las relaciones utilizarán claves foráneas explícitas.

Ejemplo:

```text
students.person_id
            ↓
persons.id
```

Toda FK deberá:

- ser NOT NULL cuando la relación sea obligatoria;
- tener índice;
- utilizar restricciones FOREIGN KEY.

---

# 7. Auditoría

Todas las tablas del dominio incluirán los siguientes campos:

```text
created_at
updated_at
```

Tipos:

```sql
TIMESTAMP
```

Estos campos serán administrados por la aplicación.

---

## Usuario responsable

Las tablas que requieran trazabilidad incluirán:

```text
created_by

updated_by
```

Ambos campos referenciarán:

```text
users.id
```

Su incorporación dependerá de las necesidades funcionales del módulo.

---

# 8. Soft Delete

El sistema utilizará eliminación lógica.

Campo estándar:

```text
deleted_at
```

Tipo:

```sql
TIMESTAMP NULL
```

Una fila con `deleted_at IS NOT NULL` se considerará eliminada.

No se eliminarán registros del dominio mediante `DELETE` físico, salvo procesos excepcionales de mantenimiento.

---

# 9. Tipos de Datos

Se utilizarán tipos consistentes en todo el sistema.

| Información | Tipo |
|-------------|------|
| PK | BIGINT UNSIGNED |
| FK | BIGINT UNSIGNED |
| Texto corto | VARCHAR |
| Texto largo | TEXT |
| Fecha | DATE |
| Fecha y hora | TIMESTAMP |
| Booleano | BOOLEAN |
| Decimal | DECIMAL |
| JSON | JSON (solo cuando esté justificado) |

---

## Uso de VARCHAR

Como regla general:

- nombres → VARCHAR(100)
- correos → VARCHAR(255)
- documentos → VARCHAR(50)
- teléfonos → VARCHAR(30)

La longitud deberá responder al negocio y no utilizar valores arbitrarios.

---

# 10. Organización del Esquema

Las tablas se agrupan por contexto funcional.

## Identity

- persons
- users

---

## Family

- families
- representatives
- family_representatives
- family_students

---

## Academic

- students

---

## Student Profile

- medical_records
- student_emergency_contacts
- student_authorized_pickups
- transports

---

## Enrollment

Se incorporará en el módulo correspondiente.

---

## Catalogs

Tablas incluidas en este documento para garantizar trazabilidad completa del modelo físico:

- status_types
- statuses
- document_types
- genders
- nationalities
- relationship_types
- blood_types
- transport_types

---

# 11. Principios para Nuevas Tablas

Toda nueva tabla deberá responder afirmativamente a las siguientes preguntas:

1. ¿Representa una entidad del dominio?
2. ¿Existe ya una tabla equivalente?
3. ¿Debe pertenecer a un Aggregate existente?
4. ¿Cumple las convenciones de nombres?
5. ¿Posee claves primarias y foráneas consistentes?
6. ¿Respeta la normalización?
7. ¿Incluye auditoría?
8. ¿Debe soportar Soft Delete?

Ninguna tabla nueva podrá incorporarse sin cumplir estos criterios.

---

# 12. Convención Base para las Tablas del Dominio

Con el objetivo de mantener uniformidad, trazabilidad y facilitar el mantenimiento del sistema, todas las tablas del dominio deberán seguir una estructura base común.

Esta convención aplica a todas las entidades persistentes del negocio, salvo excepciones debidamente justificadas (por ejemplo, tablas temporales o tablas de sistema).

---

## Estructura Base

Todas las tablas del dominio deberán incluir los siguientes campos:

| Campo | Tipo | Obligatorio | Descripción |
|--------|------|-------------|-------------|
| id | BIGINT UNSIGNED | Sí | Clave primaria sustituta. |
| created_at | TIMESTAMP | Sí | Fecha y hora de creación. |
| updated_at | TIMESTAMP | Sí | Fecha y hora de la última modificación. |
| deleted_at | TIMESTAMP NULL | No | Fecha y hora de eliminación lógica. |
| created_by | BIGINT UNSIGNED NULL | No | Usuario que creó el registro. |
| updated_by | BIGINT UNSIGNED NULL | No | Usuario que realizó la última modificación. |
| deleted_by | BIGINT UNSIGNED NULL | No | Usuario que realizó la eliminación lógica. |

---

## Auditoría

Los campos de auditoría permiten reconstruir el historial operativo del sistema.

Cuando un módulo no requiera auditoría de usuarios, los campos:

- created_by
- updated_by
- deleted_by

podrán permanecer NULL.

Sin embargo, la estructura deberá mantenerse para conservar uniformidad entre todas las tablas.

---

## Integridad Referencial

Los campos:

- created_by
- updated_by
- deleted_by

referenciarán:

```text
users.id
```

mediante claves foráneas cuando sean utilizados.

---

## Eliminación Lógica

El sistema no eliminará registros del dominio mediante operaciones DELETE.

En su lugar se actualizarán los campos:

```text
deleted_at
deleted_by
```

Un registro será considerado activo cuando:

```sql
deleted_at IS NULL
```

---

## Beneficios

Esta convención proporciona:

- auditoría uniforme;
- simplificación del código;
- menor cantidad de excepciones;
- facilidad para generar reportes;
- trazabilidad completa;
- soporte para restauración futura;
- consistencia entre todos los módulos.

---

## Excepciones

Únicamente podrán omitir esta estructura:

- tablas temporales;
- tablas de sesión;
- tablas de caché;
- tablas de migraciones;
- tablas estrictamente técnicas.

Toda excepción deberá documentarse y justificarse.

---

# 13. Principio de Estados

A partir de esta sección se describe el diseño detallado de cada tabla del sistema.

Cada definición incluirá:

- propósito;
- columnas;
- claves primarias;
- claves foráneas;
- restricciones;
- índices;
- reglas de integridad;
- observaciones de diseño.

Las tablas se documentarán siguiendo el mismo formato para garantizar consistencia en todo el proyecto.

---

# Principio de Estados

Todas las entidades cuyo ciclo de vida requiera representar un estado deberán utilizar un único campo:

```text
status_id
```

Este campo referenciará el catálogo `statuses`.

El catálogo `statuses` estará organizado mediante `status_types`, permitiendo reutilizar la misma estructura para todos los módulos del sistema.

No se utilizarán columnas booleanas para representar estados de negocio, tales como:

- is_active
- is_enabled
- is_locked
- is_deleted
- state

La evolución de los estados deberá realizarse mediante datos del catálogo y no mediante cambios en el esquema de la base de datos.

---

# 14. Diseño Físico de las Tablas

---

# Tabla: status_types

## Propósito

Agrupa los diferentes tipos de estados utilizados por el sistema.

Permite reutilizar una única estructura para administrar los estados de múltiples módulos sin mezclar estados de naturaleza diferente.

---

## Responsabilidad

Clasificar los estados disponibles para cada contexto funcional.

Ejemplos:

- USER_STATUS
- STUDENT_STATUS
- ENROLLMENT_STATUS
- FAMILY_STATUS
- TRANSPORT_STATUS

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| code | VARCHAR(50) | No | Código único del tipo de estado. |
| name | VARCHAR(100) | No | Nombre descriptivo. |
| description | VARCHAR(255) | Sí | Descripción opcional. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- code debe ser único.
- name debe ser único.

---

## Índices

- PK(id)
- UK(code)
- UK(name)

---

## Relaciones

Un StatusType puede tener múltiples Status.

Relación:

```text
status_types (1)
        │
        └──────< statuses (N)
```

---

## Observaciones

Los registros de esta tabla serán administrados exclusivamente por el sistema.

No deberán eliminarse cuando existan estados asociados.

---

# Tabla: statuses

## Propósito

Almacena los estados utilizados por las entidades del sistema.

---

## Responsabilidad

Representar el ciclo de vida de una entidad mediante un catálogo configurable.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| status_type_id | BIGINT UNSIGNED | No | Tipo de estado. |
| code | VARCHAR(50) | No | Código del estado. |
| name | VARCHAR(100) | No | Nombre visible. |
| description | VARCHAR(255) | Sí | Descripción. |
| display_order | SMALLINT UNSIGNED | No | Orden de presentación. |
| color | VARCHAR(20) | Sí | Color utilizado por la interfaz. |
| is_default | BOOLEAN | No | Estado inicial del tipo correspondiente. |
| is_terminal | BOOLEAN | No | Indica si representa un estado final. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
status_type_id → status_types.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- (status_type_id, code) debe ser único.
- Solo podrá existir un registro is_default = TRUE por cada status_type_id.
- display_order debe ser único dentro de cada status_type_id.

---

## Índices

- PK(id)
- IDX(status_type_id)
- UK(status_type_id, code)
- IDX(display_order)

---

## Relaciones

```text
status_types (1)
        │
        └──────< statuses (N)
```

Las entidades del dominio referenciarán esta tabla mediante:

```text
status_id
```

---

## Observaciones

No se crearán columnas como:

- is_active
- is_enabled
- state

en ninguna tabla del dominio.

Toda entidad con ciclo de vida utilizará un único campo:

```text
status_id
```

que referenciará esta tabla.

---

# Tabla: document_types

## Propósito

Definir los tipos de documento de identidad permitidos para las personas del sistema.

---

## Responsabilidad

Normalizar la clasificación de documentos utilizada por `persons`.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| code | VARCHAR(50) | No | Código único del tipo de documento. |
| name | VARCHAR(100) | No | Nombre visible. |
| description | VARCHAR(255) | Sí | Descripción opcional. |
| display_order | SMALLINT UNSIGNED | Sí | Orden de presentación. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- code debe ser único.
- name debe ser único.

---

## Índices

- PK(id)
- UK(code)
- UK(name)
- IDX(display_order)

---

## Relaciones

```text
document_types (1)
      │
      └──────< persons (N)
```

---

## Observaciones

Este catálogo se utiliza exclusivamente para clasificar el documento de identidad en `persons.document_type_id`.

---

# Tabla: genders

## Propósito

Definir las opciones de género disponibles para registro de personas.

---

## Responsabilidad

Normalizar el atributo de género utilizado por `persons`.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| code | VARCHAR(50) | No | Código único. |
| name | VARCHAR(100) | No | Nombre visible. |
| description | VARCHAR(255) | Sí | Descripción opcional. |
| display_order | SMALLINT UNSIGNED | Sí | Orden de presentación. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- code debe ser único.
- name debe ser único.

---

## Índices

- PK(id)
- UK(code)
- UK(name)
- IDX(display_order)

---

## Relaciones

```text
genders (1)
    │
    └──────< persons (N)
```

---

## Observaciones

La relación con `persons` es opcional a nivel físico mediante `persons.gender_id` nullable.

---

# Tabla: nationalities

## Propósito

Definir las nacionalidades disponibles para las personas registradas en el sistema.

---

## Responsabilidad

Normalizar el atributo de nacionalidad utilizado por `persons`.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| code | VARCHAR(50) | No | Código único. |
| name | VARCHAR(100) | No | Nombre visible. |
| description | VARCHAR(255) | Sí | Descripción opcional. |
| display_order | SMALLINT UNSIGNED | Sí | Orden de presentación. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- code debe ser único.
- name debe ser único.

---

## Índices

- PK(id)
- UK(code)
- UK(name)
- IDX(display_order)

---

## Relaciones

```text
nationalities (1)
      │
      └──────< persons (N)
```

---

## Observaciones

La relación con `persons` es opcional a nivel físico mediante `persons.nationality_id` nullable.

---

# Tabla: relationship_types

## Propósito

Definir los tipos de relación interpersonal usados en asociaciones familiares y del perfil del estudiante.

---

## Responsabilidad

Unificar la clasificación de parentescos y relaciones en:

- family_representatives
- student_emergency_contacts
- student_authorized_pickups

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| code | VARCHAR(50) | No | Código único. |
| name | VARCHAR(100) | No | Nombre visible. |
| description | VARCHAR(255) | Sí | Descripción opcional. |
| display_order | SMALLINT UNSIGNED | Sí | Orden de presentación. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- code debe ser único.
- name debe ser único.

---

## Índices

- PK(id)
- UK(code)
- UK(name)
- IDX(display_order)

---

## Relaciones

```text
relationship_types (1)
       │
       ├──────< family_representatives (N)
       ├──────< student_emergency_contacts (N)
       └──────< student_authorized_pickups (N)
```

---

## Observaciones

Las tres FK hacia `relationship_types` son opcionales a nivel físico (nullable) para permitir registros transitorios cuando el tipo de relación aún no se haya especificado.

---

# Tabla: blood_types

## Propósito

Definir los tipos de sangre disponibles para el expediente médico del estudiante.

---

## Responsabilidad

Normalizar el valor de tipo de sangre utilizado por `medical_records`.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| code | VARCHAR(50) | No | Código único. |
| name | VARCHAR(100) | No | Nombre visible. |
| description | VARCHAR(255) | Sí | Descripción opcional. |
| display_order | SMALLINT UNSIGNED | Sí | Orden de presentación. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- code debe ser único.
- name debe ser único.

---

## Índices

- PK(id)
- UK(code)
- UK(name)
- IDX(display_order)

---

## Relaciones

```text
blood_types (1)
     │
     └──────< medical_records (N)
```

---

## Observaciones

La relación con `medical_records` es opcional a nivel físico mediante `medical_records.blood_type_id` nullable.

---

# Tabla: transport_types

## Propósito

Definir los tipos de transporte escolar permitidos por el sistema.

---

## Responsabilidad

Normalizar el tipo de transporte utilizado por `transports`.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| code | VARCHAR(50) | No | Código único. |
| name | VARCHAR(100) | No | Nombre visible. |
| description | VARCHAR(255) | Sí | Descripción opcional. |
| display_order | SMALLINT UNSIGNED | Sí | Orden de presentación. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- code debe ser único.
- name debe ser único.

---

## Índices

- PK(id)
- UK(code)
- UK(name)
- IDX(display_order)

---

## Relaciones

```text
transport_types (1)
      │
      └──────< transports (N)
```

---

## Observaciones

Cada registro de `transports` debe referenciar exactamente un tipo de transporte mediante `transport_type_id`.

---

# Tabla: persons

## Propósito

Almacenar la identidad única de toda persona registrada en el sistema.

---

## Responsabilidad

Centralizar toda la información personal independiente de los roles que una persona pueda desempeñar.

Una Person puede posteriormente convertirse en:

- Representative
- Student
- Teacher
- Employee
- Alumni
- Applicant

sin duplicar información.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| status_id | BIGINT UNSIGNED | No | Estado de la persona. |
| document_type_id | BIGINT UNSIGNED | No | Tipo de documento. |
| document_number | VARCHAR(50) | No | Número de documento. |
| first_name | VARCHAR(100) | No | Primer nombre. |
| middle_name | VARCHAR(100) | Sí | Segundo nombre. |
| last_name | VARCHAR(100) | No | Primer apellido. |
| second_last_name | VARCHAR(100) | Sí | Segundo apellido. |
| preferred_name | VARCHAR(100) | Sí | Nombre preferido. |
| birth_date | DATE | Sí | Fecha de nacimiento. |
| gender_id | BIGINT UNSIGNED | Sí | Género. |
| nationality_id | BIGINT UNSIGNED | Sí | Nacionalidad. |
| email | VARCHAR(255) | Sí | Correo electrónico. |
| mobile_phone | VARCHAR(30) | Sí | Teléfono móvil. |
| home_phone | VARCHAR(30) | Sí | Teléfono convencional. |
| address | VARCHAR(255) | Sí | Dirección principal. |
| notes | TEXT | Sí | Observaciones. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
status_id → statuses.id

document_type_id → document_types.id

gender_id → genders.id

nationality_id → nationalities.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- document_number debe ser único junto con document_type_id.
- first_name es obligatorio.
- last_name es obligatorio.

---

## Índices

- PK(id)
- UK(document_type_id, document_number)
- IDX(last_name)
- IDX(email)
- IDX(status_id)

---

## Relaciones

```text
persons (1)
      │
      ├────── users
      ├────── representatives
      ├────── students
      ├────── student_emergency_contacts
      └────── student_authorized_pickups
```

---

## Observaciones

Toda información personal del sistema deberá almacenarse exclusivamente en esta tabla.

Ninguna otra entidad podrá duplicar datos personales.

---

# Tabla: users

## Propósito

Administrar las credenciales de autenticación y autorización del sistema.

---

## Responsabilidad

Representar una cuenta de acceso asociada a una Person.

No almacena información personal.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| person_id | BIGINT UNSIGNED | No | Persona asociada. |
| status_id | BIGINT UNSIGNED | No | Estado de la cuenta. |
| username | VARCHAR(100) | No | Nombre de usuario. |
| email | VARCHAR(255) | No | Correo utilizado para autenticación. |
| password_hash | VARCHAR(255) | No | Contraseña cifrada. |
| password_changed_at | TIMESTAMP | Sí | Último cambio de contraseña. |
| last_login_at | TIMESTAMP | Sí | Último acceso. |
| failed_login_attempts | SMALLINT UNSIGNED | No | Intentos fallidos. |
| locked_until | TIMESTAMP | Sí | Bloqueo temporal. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
person_id → persons.id

status_id → statuses.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- person_id debe ser único.
- username debe ser único.
- email debe ser único.

---

## Índices

- PK(id)
- UK(person_id)
- UK(username)
- UK(email)
- IDX(status_id)

---

## Relaciones

```text
persons (1)
      │
      └────── users (0..1)
```

---

## Observaciones

Una Person puede existir sin User.

Un User nunca puede existir sin Person.

Las contraseñas únicamente se almacenarán mediante algoritmos de hash seguros.

No se almacenarán contraseñas en texto plano bajo ninguna circunstancia.

---

# Tabla: families

## Propósito

Representar la unidad administrativa que agrupa representantes y estudiantes.

---

## Responsabilidad

Servir como Aggregate Root para la administración familiar.

No representa parentescos ni relaciones biológicas.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| status_id | BIGINT UNSIGNED | No | Estado de la familia. |
| family_code | VARCHAR(30) | No | Código interno único. |
| name | VARCHAR(150) | Sí | Nombre descriptivo de la familia. |
| notes | TEXT | Sí | Observaciones. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
status_id → statuses.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- family_code debe ser único.

---

## Índices

- PK(id)
- UK(family_code)
- IDX(status_id)

---

## Relaciones

```text
families (1)
      │
      ├────── family_representatives
      └────── family_students
```

---

## Observaciones

La composición de una familia se administra exclusivamente mediante las tablas de relación.

Nunca contendrá referencias directas a estudiantes o representantes.

---

# Tabla: representatives

## Propósito

Representar el Business Role de representante.

---

## Responsabilidad

Habilitar a una Person para participar como representante dentro del dominio.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| person_id | BIGINT UNSIGNED | No | Persona asociada. |
| status_id | BIGINT UNSIGNED | No | Estado del representante. |
| occupation | VARCHAR(150) | Sí | Ocupación. |
| company | VARCHAR(150) | Sí | Empresa. |
| work_phone | VARCHAR(30) | Sí | Teléfono laboral. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
person_id → persons.id

status_id → statuses.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- person_id debe ser único.

---

## Índices

- PK(id)
- UK(person_id)
- IDX(status_id)

---

## Relaciones

```text
persons (1)
      │
      └────── representatives (0..1)

representatives (1)
      │
      └────── family_representatives
```

---

## Observaciones

No almacena información personal.

Toda la información personal pertenece a `persons`.

---

# Tabla: family_representatives

## Propósito

Relacionar representantes con familias.

---

## Responsabilidad

Administrar la pertenencia de representantes a una familia.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| status_id | BIGINT UNSIGNED | No | Estado del registro. |
| family_id | BIGINT UNSIGNED | No | Familia. |
| representative_id | BIGINT UNSIGNED | No | Representante. |
| relationship_type_id | BIGINT UNSIGNED | Sí | Relación con el estudiante (padre, madre, tutor, etc.). |
| is_primary | BOOLEAN | No | Representante principal. |
| receives_notifications | BOOLEAN | No | Recibe comunicaciones institucionales. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
status_id → statuses.id

family_id → families.id

representative_id → representatives.id

relationship_type_id → relationship_types.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- (family_id, representative_id) debe ser único.

---

## Índices

- PK(id)
- IDX(status_id)
- UK(family_id, representative_id)
- IDX(representative_id)

---

## Relaciones

```text
families (1)
      │
      └────── family_representatives (N)

representatives (1)
      │
      └────── family_representatives (N)
```

---

## Observaciones

Una familia puede tener múltiples representantes.

Un representante puede pertenecer a múltiples familias.

La capa de aplicación deberá garantizar que toda Family posea al menos un Representative activo.

---

# Tabla: family_students

## Propósito

Relacionar estudiantes con familias.

---

## Responsabilidad

Determinar a qué unidad familiar pertenece cada estudiante.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| status_id | BIGINT UNSIGNED | No | Estado del registro. |
| family_id | BIGINT UNSIGNED | No | Familia. |
| student_id | BIGINT UNSIGNED | No | Estudiante. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
status_id → statuses.id

family_id → families.id

student_id → students.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- (family_id, student_id) debe ser único.

---

## Índices

- PK(id)
- IDX(status_id)
- UK(family_id, student_id)
- IDX(student_id)

---

## Relaciones

```text
families (1)
      │
      └────── family_students (N)

students (1)
      │
      └────── family_students (N)
```

---

## Observaciones

La pertenencia de un estudiante a una familia se administra exclusivamente mediante esta tabla.

No deberán existir referencias directas entre `students` y `families`.

La existencia de un Student sin una relación activa en `family_students` constituye un estado inválido del dominio.

Esta regla será garantizada por la capa de aplicación durante la creación y mantenimiento del estudiante.

---

# Tabla: students

## Propósito

Representar el Business Role de estudiante.

---

## Responsabilidad

Habilitar a una Person para participar como estudiante dentro del sistema.

Toda la información académica será administrada por módulos posteriores.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| person_id | BIGINT UNSIGNED | No | Persona asociada. |
| status_id | BIGINT UNSIGNED | No | Estado del estudiante. |
| student_code | VARCHAR(30) | No | Código institucional del estudiante. |
| admission_date | DATE | Sí | Fecha de ingreso. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
person_id → persons.id

status_id → statuses.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- person_id debe ser único.
- student_code debe ser único.

---

## Índices

- PK(id)
- UK(person_id)
- UK(student_code)
- IDX(status_id)

---

## Relaciones

```text
persons (1)
      │
      └────── students (0..1)

students (1)
      ├────── family_students
      ├────── medical_records
      ├────── student_emergency_contacts
      ├────── student_authorized_pickups
      └────── transports
```

---

## Observaciones

No almacena información personal.

Toda la información de identidad pertenece a `persons`.

---

# Tabla: medical_records

## Propósito

Almacenar la información médica relevante del estudiante.

---

## Responsabilidad

Centralizar la información necesaria para la atención y seguridad del estudiante.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| student_id | BIGINT UNSIGNED | No | Estudiante. |
| blood_type_id | BIGINT UNSIGNED | Sí | Tipo de sangre. |
| allergies | TEXT | Sí | Alergias conocidas. |
| medications | TEXT | Sí | Medicación permanente. |
| medical_conditions | TEXT | Sí | Condiciones médicas relevantes. |
| emergency_notes | TEXT | Sí | Observaciones médicas. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
student_id → students.id

blood_type_id → blood_types.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- student_id debe ser único.

---

## Índices

- PK(id)
- UK(student_id)

---

## Relaciones

```text
students (1)
      │
      └────── medical_records (0..1)
```

---

## Observaciones

Cada estudiante podrá tener un único expediente médico vigente.

---

# Tabla: student_emergency_contacts

## Propósito

Administrar los contactos de emergencia de un estudiante.

---

## Responsabilidad

Relacionar un estudiante con una Person autorizada para ser contactada en caso de emergencia.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| status_id | BIGINT UNSIGNED | No | Estado del registro. |
| student_id | BIGINT UNSIGNED | No | Estudiante. |
| person_id | BIGINT UNSIGNED | No | Persona de contacto. |
| relationship_type_id | BIGINT UNSIGNED | Sí | Parentesco o relación. |
| priority | SMALLINT UNSIGNED | No | Prioridad de contacto. |
| notes | TEXT | Sí | Observaciones sobre el contacto de emergencia. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
status_id → statuses.id

student_id → students.id

person_id → persons.id

relationship_type_id → relationship_types.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- (student_id, person_id) debe ser único.

---

## Índices

- PK(id)
- IDX(status_id)
- UK(student_id, person_id)
- IDX(person_id)

---

## Relaciones

```text
students (1)
      │
      └────── student_emergency_contacts (N)

persons (1)
      │
      └────── student_emergency_contacts (N)
```

---

## Observaciones

El contacto de emergencia puede o no ser representante del estudiante.

---

# Tabla: student_authorized_pickups

## Propósito

Administrar las personas autorizadas para retirar al estudiante.

---

## Responsabilidad

Relacionar estudiantes con personas autorizadas para su retiro.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| status_id | BIGINT UNSIGNED | No | Estado del registro. |
| student_id | BIGINT UNSIGNED | No | Estudiante. |
| person_id | BIGINT UNSIGNED | No | Persona autorizada. |
| relationship_type_id | BIGINT UNSIGNED | Sí | Relación con el estudiante. |
| notes | TEXT | Sí | Observaciones sobre el contacto de emergencia. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
status_id → statuses.id

student_id → students.id

person_id → persons.id

relationship_type_id → relationship_types.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- (student_id, person_id) debe ser único.

---

## Índices

- PK(id)
- IDX(status_id)
- UK(student_id, person_id)
- IDX(person_id)

---

## Relaciones

```text
students (1)
      │
      └────── student_authorized_pickups (N)

persons (1)
      │
      └────── student_authorized_pickups (N)
```

---

## Observaciones

Una persona puede estar autorizada para retirar a múltiples estudiantes.

---

# Tabla: transports

## Propósito

Administrar la información del servicio de transporte utilizado por un estudiante.

---

## Responsabilidad

Registrar si un estudiante utiliza transporte escolar y almacenar la información necesaria para su gestión.

---

## Columnas

| Campo | Tipo | Null | Descripción |
|--------|------|------|-------------|
| id | BIGINT UNSIGNED | No | Clave primaria. |
| student_id | BIGINT UNSIGNED | No | Estudiante. |
| status_id | BIGINT UNSIGNED | No | Estado del servicio. |
| transport_type_id | BIGINT UNSIGNED | No | Tipo de transporte. |
| provider_name | VARCHAR(150) | Sí | Nombre del proveedor o transportista. |
| driver_name | VARCHAR(150) | Sí | Nombre del conductor. |
| driver_phone | VARCHAR(30) | Sí | Teléfono del conductor. |
| vehicle_description | VARCHAR(150) | Sí | Descripción del vehículo. |
| vehicle_plate | VARCHAR(20) | Sí | Placa del vehículo. |
| notes | TEXT | Sí | Observaciones. |
| created_at | TIMESTAMP | No | Auditoría. |
| updated_at | TIMESTAMP | No | Auditoría. |
| deleted_at | TIMESTAMP | Sí | Soft Delete. |
| created_by | BIGINT UNSIGNED | Sí | Usuario creador. |
| updated_by | BIGINT UNSIGNED | Sí | Usuario modificador. |
| deleted_by | BIGINT UNSIGNED | Sí | Usuario eliminador. |

---

## Clave Primaria

```text
id
```

---

## Claves Foráneas

```text
student_id → students.id

status_id → statuses.id

transport_type_id → transport_types.id

created_by → users.id

updated_by → users.id

deleted_by → users.id
```

---

## Restricciones

- student_id debe ser único.

---

## Índices

- PK(id)
- UK(student_id)
- IDX(status_id)
- IDX(transport_type_id)

---

## Relaciones

```text
students (1)
      │
      └────── transports (0..1)
```

---

## Observaciones

Cada estudiante podrá tener un único registro de transporte vigente.

El detalle operativo del servicio (rutas, paradas, recorridos, control de abordaje, etc.) será administrado por módulos posteriores.

---

# 15. Resumen del Modelo Físico

El modelo físico definido para el módulo E002.1 está compuesto por las siguientes tablas:

## Catálogos Base

- status_types
- statuses

## Catálogos Generales

- document_types
- genders
- nationalities
- relationship_types
- blood_types

## Catálogos Funcionales

- transport_types

## Identidad y Autenticación

- persons
- users

## Familias

- families
- representatives
- family_representatives
- family_students

## Académico

- students

## Información del Estudiante

- medical_records
- student_emergency_contacts
- student_authorized_pickups
- transports

---

## Principios del Modelo

- Una única identidad por persona (`persons`).
- Los Business Roles se implementan mediante tablas independientes.
- Ninguna tabla duplica información personal.
- Todas las relaciones utilizan claves foráneas.
- Todas las tablas del dominio implementan auditoría y Soft Delete.
- Todas las entidades con ciclo de vida utilizan `status_id`.
- El modelo cumple Tercera Forma Normal (3NF).
- El modelo está preparado para crecimiento modular sin modificaciones estructurales significativas.


---

# 16. Convenciones de Implementación

Las decisiones descritas en este documento constituyen la referencia oficial para:

- generación del SQL;
- migraciones;
- modelos del dominio;
- repositorios;
- Entity Relationship Diagram (ERD);
- validaciones arquitectónicas;
- generación asistida por IA.

Toda implementación deberá respetar estas definiciones.

Las modificaciones al modelo físico deberán realizarse primero en este documento antes de implementarse en código.
