# DOMAIN MODEL

**Versión:** 2.0
**Estado:** Aprobado
**Última actualización:** Julio 2026

---

# 1. Propósito

Este documento define el modelo de dominio del Sistema de Información Escolar (SIS).

Su objetivo es establecer un lenguaje común entre el negocio, la arquitectura del sistema y las herramientas de desarrollo asistidas por IA (ChatGPT y GitHub Copilot).

Este documento constituye la fuente oficial para comprender:

- las entidades del negocio;
- las responsabilidades de cada entidad;
- las relaciones entre ellas;
- los límites del dominio;
- las reglas generales del negocio.

No describe la implementación física de la base de datos ni detalles específicos del framework. Esas decisiones se documentan en `DATABASE_DESIGN.md`.

---

# 2. Objetivos del modelo

El modelo de dominio debe cumplir los siguientes objetivos:

- representar fielmente la operación de una institución educativa;
- evitar duplicación de información;
- separar claramente identidad, autenticación y reglas del negocio;
- facilitar la evolución futura del producto;
- soportar múltiples instituciones mediante una única base de código;
- mantener independencia entre el dominio y la tecnología utilizada.

El dominio debe permanecer estable aunque cambien:

- la base de datos;
- el framework;
- el motor de autenticación;
- la interfaz de usuario.

---

# 3. Alcance

Este documento describe únicamente el dominio correspondiente al MVP.

Incluye:

- autenticación;
- representantes;
- estudiantes;
- familias;
- matrícula en línea;
- información médica;
- contactos de emergencia;
- personas autorizadas para retiro;
- transporte escolar;
- revisión administrativa.

No forma parte del dominio actual:

- facturación;
- contabilidad;
- pagos;
- nómina;
- biblioteca;
- calificaciones;
- asistencia;
- recursos humanos.

Estos módulos podrán incorporarse posteriormente sin alterar los principios definidos en este documento.

---

# 4. Principios del modelo

## 4.1 White Label

El sistema es un producto comercial para múltiples instituciones educativas.

Todo el código debe ser reutilizable.

Nunca se deben incorporar nombres, configuraciones o reglas específicas de una institución dentro del dominio.

---

## 4.2 Una base de datos por institución

Cada institución utiliza:

- una base de datos propia;
- un archivo `.env` propio;
- una configuración independiente.

Por esta razón el dominio **no** incluye una entidad `Institution`.

Tampoco existirán campos como:

- institution_id
- school_id
- tenant_id

en las tablas del negocio.

---

## 4.3 Separación de responsabilidades

El dominio distingue claramente entre:

- identidad;
- autenticación;
- roles;
- relaciones.

Estas responsabilidades nunca deben mezclarse dentro de una misma entidad.

---

## 4.4 Person como fuente única de identidad

Toda persona física existe una sola vez dentro del sistema.

Una persona puede desempeñar distintos roles durante su ciclo de vida sin duplicar su información personal.

Ejemplos:

- estudiante;
- representante;
- contacto de emergencia;
- autorizado para retiro;
- futuro docente;
- futuro empleado.

Toda la información personal reside exclusivamente en la entidad **Person**.

---

## 4.5 User no representa una persona

Una cuenta de usuario únicamente representa credenciales de acceso.

Las credenciales nunca contienen información personal.

Toda cuenta pertenece exactamente a una persona.

Una persona puede existir sin tener una cuenta de usuario.

---

## 4.6 Roles de negocio

Los roles representan responsabilidades dentro del sistema.

Un rol agrega comportamiento.

No representa identidad.

Los principales roles del MVP son:

- Representative
- Student

En futuras versiones podrán existir:

- Teacher
- Staff
- Administrator
- Applicant
- Alumni

---

## 4.7 Relaciones del dominio

No toda interacción entre personas constituye un rol.

Muchas representan únicamente una relación del negocio.

Ejemplos:

- contacto de emergencia;
- persona autorizada para retiro;
- parentesco;
- representante principal.

Estas relaciones se modelan mediante entidades asociativas y nunca duplican información personal.

---

# 5. Lenguaje Ubicuo (Ubiquitous Language)

Todos los desarrolladores, herramientas de IA y documentación utilizarán el mismo vocabulario.

| Concepto | Definición |
|----------|------------|
| User | Cuenta de acceso al sistema. |
| Person | Persona física identificable. |
| Representative | Rol que administra estudiantes y realiza la matrícula. |
| Student | Rol correspondiente al alumno matriculado. |
| Family | Unidad familiar utilizada para agrupar representantes y estudiantes. |
| Enrollment | Proceso de matrícula. |
| Emergency Contact | Persona que puede ser contactada ante una emergencia. |
| Authorized Pickup | Persona autorizada para retirar al estudiante. |
| Medical Record | Información médica del estudiante. |
| Transport | Información del transporte escolar. |
| Catalog | Lista configurable de valores del sistema. |

Todo el código, documentación y conversaciones técnicas deberán utilizar estos términos.

---

# 6. Bounded Contexts

El dominio se divide en contextos claramente separados.

## Identity

Responsable de la identidad y autenticación.

Entidades principales:

- User
- Person

---

## Family Management

Responsable de organizar la unidad familiar.

Entidades principales:

- Family
- Representative

---

## Academic Core

Responsable de representar a los estudiantes.

Entidades principales:

- Student

---

## Student Profile

Responsable de toda la información complementaria del estudiante.

Incluye:

- Medical Record
- Emergency Contacts
- Authorized Pickups
- Transport

---

## Enrollment

Responsable del proceso completo de matrícula.

Será desarrollado en entregables posteriores.

---

## Catalogs

Responsable de toda la información configurable.

Ningún módulo del sistema debe almacenar listas fijas cuando puedan representarse mediante catálogos.

---

# 7. Actores del dominio

## Secretaría

Responsabilidades:

- crear personas;
- crear representantes;
- crear estudiantes;
- crear familias;
- crear usuarios;
- asociar representantes con familias;
- asociar estudiantes con familias;
- iniciar procesos de matrícula;
- revisar información enviada;
- aprobar matrícula;
- solicitar correcciones;
- bloquear procesos cuando sea necesario.

La Secretaría es responsable de la creación inicial de la información.

---

## Representante

Responsabilidades:

- iniciar sesión;
- administrar su propia información;
- administrar la información permitida de los estudiantes asociados;
- actualizar contactos;
- actualizar información médica;
- actualizar transporte;
- aceptar documentos institucionales;
- cargar documentos;
- enviar la matrícula para revisión.

El representante nunca administra información de estudiantes ajenos a su familia.

---

## Estudiante

El estudiante representa al alumno matriculado.

No administra información dentro del MVP.

Toda la información del estudiante es gestionada por Secretaría o por sus representantes autorizados.

---

# 8. Aggregates

El dominio se organiza utilizando los principios de Domain-Driven Design (DDD).

Cada Aggregate representa un conjunto coherente de entidades que deben mantener consistencia transaccional.

Los Aggregates definidos para el MVP son:

- Identity
- Family
- Student
- Student Profile
- Enrollment (implementación posterior)
- Catalogs

Cada Aggregate posee un único Aggregate Root responsable de mantener la consistencia de sus entidades internas.

---

# 9. Aggregate: Identity

## Objetivo

Administrar la identidad de las personas y sus credenciales de acceso.

Este Aggregate es independiente del resto del sistema.

Toda autenticación comienza aquí.

---

## Aggregate Root

**Person**

---

## Entidades

### Person

Representa una persona física.

Toda persona registrada en el sistema existe una única vez.

Toda la información personal reside exclusivamente aquí.

Ejemplos:

- nombres
- apellidos
- documento de identidad
- fecha de nacimiento
- sexo
- nacionalidad
- teléfonos
- correo electrónico
- dirección

Una Person puede existir sin tener acceso al sistema.

---

### User

Representa una cuenta de acceso.

No representa una persona.

Responsabilidades:

- autenticación
- recuperación de contraseña
- bloqueo
- activación
- último acceso
- credenciales

Toda cuenta pertenece exactamente a una Person.

Una Person puede no tener User.

Dentro del MVP solamente los representantes tendrán User.

En futuras versiones existirán cuentas para:

- docentes
- administrativos
- directivos

---

## Reglas

- No puede existir un User sin Person.
- La información personal nunca se almacena en User.
- Una Person puede existir sin User.
- La autenticación depende únicamente de User.

---

# 10. Aggregate: Family

## Objetivo

Representar la unidad familiar.

Este Aggregate organiza las relaciones entre representantes y estudiantes.

No almacena información personal.

---

## Aggregate Root

**Family**

---

## Entidades

### Family

Representa una unidad familiar.

Una familia puede contener:

- uno o varios representantes;
- uno o varios estudiantes.

No representa parentescos.

Representa únicamente la agrupación administrativa utilizada por la institución.

---

### Representative

Representa el rol de negocio del representante.

Representative es un Business Role.

No representa una persona física ni constituye una especialización de Person.

Toda la información personal permanece en Person.

Representative únicamente encapsula comportamiento y reglas propias del representante dentro del dominio.

No contiene datos personales.

Toda su identidad proviene de Person.

Responsabilidades:

- administrar estudiantes;
- completar matrícula;
- aceptar contratos;
- cargar documentos;
- actualizar información permitida.

Un representante siempre está asociado a una Person.

---

## Relaciones

Family es el Aggregate Root de este contexto.

Las asociaciones entre Family y sus integrantes se implementan mediante entidades de relación que forman parte del Aggregate.

Conceptualmente existen dos tipos de asociación:

- Family ↔ Representative
- Family ↔ Student

Estas relaciones permiten:

- que una Family tenga uno o varios Representatives;
- que una Family tenga uno o varios Students;
- que un Representative pueda pertenecer a varias Family cuando las reglas institucionales lo permitan;
- que un Student pueda pertenecer a varias Family cuando las reglas institucionales lo permitan;
- que un Student pueda cambiar de Family cuando exista una razón administrativa.

La implementación física de estas asociaciones se documentará en `DATABASE_DESIGN.md`.

El dominio considera estas relaciones parte del Aggregate Family y no relaciones directas entre Representative y Student.

---

## Reglas

- Toda Family debe tener al menos un Representative activo.
- Un Representative pertenece a una Person.
- El rol Representative nunca duplica información personal.

---

# 11. Aggregate: Student

## Objetivo

Representar al alumno matriculado.

El estudiante constituye el núcleo funcional del sistema escolar.

---

## Aggregate Root

**Student**

---

## Entidades

### Student

Representa el rol académico de una Person.

No almacena datos personales.

Toda información de identidad pertenece a Person.

Información propia del estudiante:

- código institucional
- estado académico
- fecha de ingreso
- nivel
- grado
- paralelo
- observaciones académicas futuras

---

## Relaciones

Todo Student pertenece exactamente a una Person.

Todo Student pertenece al menos a una Family.

Un Student puede pertenecer a múltiples Family.

Un Student puede tener varios Representatives mediante la Family.

---

## Reglas

- No existe Student sin Person.
- No existe Student sin Family.
- Toda información personal permanece en Person.

---

# 12. Aggregate: Student Profile

## Objetivo

Administrar toda la información complementaria del estudiante.

Este Aggregate concentra la mayor parte del Portal del Representante.

---

## Aggregate Root

**Student**

---

## Entidades

### MedicalRecord

Información médica del estudiante.

Ejemplos:

- alergias
- enfermedades
- medicamentos
- restricciones
- seguro médico
- observaciones

---

### StudentEmergencyContact

Relaciona un Student con una Person.

No representa un rol.

Representa una relación del negocio.

Información propia de la relación:

- prioridad
- parentesco
- observaciones
- estado

Una misma Person puede ser contacto de emergencia de múltiples estudiantes.

---

### StudentAuthorizedPickup

Relaciona un Student con una Person autorizada para retirarlo.

Información propia:

- autorización permanente
- vigencia
- observaciones
- estado

Una Person puede estar autorizada para retirar varios estudiantes.

---

### Transport

Información del transporte escolar.

Ejemplos:

- utiliza transporte
- ruta
- empresa
- conductor
- observaciones

El modelo podrá ampliarse posteriormente.

---

## Reglas

- Los contactos nunca duplican información personal.
- Las personas autorizadas nunca duplican información personal.
- Todas las referencias apuntan hacia Person.
- Toda la información pertenece al Student.

---

# 13. Aggregate: Enrollment

Este Aggregate será desarrollado durante el módulo de matrícula.

Será responsable de administrar:

- borradores;
- envíos;
- revisión;
- observaciones;
- aprobación;
- correcciones;
- historial del proceso.

Su diseño completo se documentará en el entregable correspondiente.

---

# 14. Aggregate: Catalogs

Este Aggregate centraliza toda la información configurable.

El objetivo es evitar valores codificados ("hardcoded") dentro del dominio.

## Entidades del Aggregate

### StatusType

Propósito:

Clasificar los tipos de estado reutilizados por múltiples módulos.

Relaciones:

- un StatusType tiene múltiples Status.

Atributos funcionales:

- código
- nombre
- descripción

Restricciones:

- código único
- nombre único

Reglas de negocio:

- un StatusType no puede eliminarse cuando existan Status asociados.

---

### Status

Propósito:

Representar estados configurables para entidades del dominio.

Relaciones:

- todo Status pertenece a un StatusType.

Atributos funcionales:

- código
- nombre
- descripción
- orden de visualización
- estado por defecto
- estado terminal
- color de referencia

Restricciones:

- código único dentro de su StatusType
- orden único dentro de su StatusType
- máximo un estado por defecto por StatusType

Reglas de negocio:

- las entidades con ciclo de vida utilizan un único campo status_id.

---

### DocumentType

Propósito:

Clasificar los documentos de identidad aceptados para Person.

Relaciones:

- puede ser utilizado por múltiples Person.

Atributos funcionales:

- código
- nombre
- descripción
- orden de visualización

Restricciones:

- código único
- nombre único

Reglas de negocio:

- toda Person debe utilizar un DocumentType válido.

---

### Gender

Propósito:

Clasificar la información de género asociada a Person.

Relaciones:

- puede ser utilizado por múltiples Person.

Atributos funcionales:

- código
- nombre
- descripción
- orden de visualización

Restricciones:

- código único
- nombre único

Reglas de negocio:

- el valor de género pertenece a Person y nunca a sus roles.

---

### Nationality

Propósito:

Clasificar la nacionalidad asociada a Person.

Relaciones:

- puede ser utilizado por múltiples Person.

Atributos funcionales:

- código
- nombre
- descripción
- orden de visualización

Restricciones:

- código único
- nombre único

Reglas de negocio:

- la nacionalidad se administra como catálogo y no como texto libre.

---

### RelationshipType

Propósito:

Clasificar parentescos y relaciones interpersonales del dominio.

Relaciones:

- puede ser utilizado por FamilyRepresentative.
- puede ser utilizado por StudentEmergencyContact.
- puede ser utilizado por StudentAuthorizedPickup.

Atributos funcionales:

- código
- nombre
- descripción
- orden de visualización

Restricciones:

- código único
- nombre único

Reglas de negocio:

- el tipo de relación pertenece a la asociación, no a Person.

---

### BloodType

Propósito:

Clasificar tipos de sangre para MedicalRecord.

Relaciones:

- puede ser utilizado por múltiples MedicalRecord.

Atributos funcionales:

- código
- nombre
- descripción
- orden de visualización

Restricciones:

- código único
- nombre único

Reglas de negocio:

- el tipo de sangre pertenece al expediente médico del Student.

---

### TransportType

Propósito:

Clasificar los tipos de transporte escolar.

Relaciones:

- puede ser utilizado por múltiples Transport.

Atributos funcionales:

- código
- nombre
- descripción
- orden de visualización

Restricciones:

- código único
- nombre único

Reglas de negocio:

- todo Transport debe referenciar un TransportType válido.

---

La implementación física oficial de estos catálogos se encuentra en `DATABASE_DESIGN.md` y su representación gráfica en `ERD.md`.

---

# 15. Modelo Conceptual

```text
                    User
                     │
                     │
                     ▼
                  Person
                 /      \
                /        \
               ▼          ▼
      Representative    Student
              ▲             ▲
              │             │
              └──────┬──────┘
                     │
                  Family
                     │
      ┌──────────────┴──────────────┐
      │                             │
      ▼                             ▼
StudentEmergencyContact   StudentAuthorizedPickup
      │                             │
      ▼                             ▼
    Person                        Person

Student
   │
   ├──────────────► MedicalRecord
   │
   └──────────────► Transport
```

---

# 16. Dependencias entre Aggregates

| Aggregate | Depende de |
|-----------|------------|
| Identity | Ninguno |
| Family | Identity |
| Student | Identity, Family |
| Student Profile | Student, Identity |
| Enrollment | Student, Family |
| Catalogs | Ninguno |

Las dependencias siempre deben apuntar hacia Aggregates más estables.

Identity constituye el núcleo del dominio y no depende de ningún otro Aggregate.

---
# 17. Reglas Generales del Dominio

Las siguientes reglas son invariantes del modelo y deberán respetarse durante todo el ciclo de vida del sistema.

## Identidad

- Toda persona existe una única vez en el sistema.
- Ninguna entidad distinta de Person almacena información personal.
- El documento de identidad debe ser único cuando exista.
- Una Person puede desempeñar múltiples roles simultáneamente.

---

## Usuarios

- Un User siempre pertenece a una Person.
- Una Person puede existir sin User.
- Las credenciales nunca se almacenan fuera de User.
- El bloqueo de una cuenta no elimina la Person.

---

## Representantes

- Todo Representative pertenece a una Person.
- Todo Representative debe pertenecer al menos a una Family.
- Un Representative puede pertenecer a múltiples Family cuando la institución lo permita.
- El acceso al Portal del Representante depende exclusivamente de un User activo.

---

## Estudiantes

- Todo Student pertenece a una Person.
- Todo Student pertenece al menos a una Family.
- Un Student puede pertenecer a múltiples Family mediante entidades asociativas.
- Un Student puede estar asociado a varios Representatives a través de su Family.
- Ningún dato personal del estudiante debe duplicarse fuera de Person.

---

## Familias

- Una Family representa una unidad administrativa.
- Una Family no representa parentescos.
- Los parentescos se modelan mediante relaciones y catálogos.
- Una Family debe tener al menos un Representative activo.

---

## Contactos de emergencia

- Todo contacto de emergencia es una Person existente.
- Una Person puede ser contacto de múltiples estudiantes.
- Un Student puede tener múltiples contactos de emergencia.
- La prioridad de contacto pertenece a la relación y no a la Person.

---

## Personas autorizadas para retiro

- Toda persona autorizada es una Person existente.
- Una autorización pertenece a un Student.
- Una Person puede estar autorizada para retirar a varios estudiantes.
- Las autorizaciones podrán tener vigencia y estado.

---

## Información médica

- Todo MedicalRecord pertenece a un único Student.
- Durante el MVP existirá un único expediente médico vigente por estudiante. La administración de historial médico se incorporará en futuras versiones.
- Los documentos médicos se almacenarán fuera del dominio (infraestructura de archivos).

---

## Transporte

- La información de transporte pertenece al Student.
- El modelo deberá permitir futuras ampliaciones sin romper compatibilidad.

---

# 18. Reglas de Acceso

El dominio define responsabilidades; la autorización será implementada por la capa de aplicación.

## Secretaría

Puede:

- crear personas;
- crear representantes;
- crear estudiantes;
- crear familias;
- crear usuarios;
- iniciar matrículas;
- revisar información;
- aprobar;
- rechazar;
- solicitar correcciones.

---

## Representante

Puede:

- actualizar su información;
- actualizar información autorizada de sus estudiantes;
- administrar contactos;
- administrar autorizaciones de retiro;
- administrar información médica;
- administrar transporte;
- aceptar documentos;
- enviar matrícula.

No puede:

- modificar estudiantes ajenos;
- modificar información académica;
- modificar configuraciones institucionales.

---

## Estudiante

Durante el MVP no posee acceso al sistema.

---

# 19. Invariantes

Las siguientes condiciones siempre deben cumplirse.

## Identity

- User → Person es obligatorio.
- Person existe una sola vez.

---

## Family

- Toda Family tiene al menos un Representative.
- Todo Representative pertenece a una Person.

---

## Student

- Todo Student pertenece a una Person.
- Todo Student pertenece a una Family.

---

## Student Profile

- Todo contacto referencia una Person.
- Toda autorización referencia una Person.
- Todo registro médico pertenece a un Student.

---

# 20. Eventos de Dominio

Aunque inicialmente no se implementará Event Sourcing, el dominio reconoce los siguientes eventos.

## Identity

- PersonCreated
- PersonUpdated
- UserCreated
- UserActivated
- UserDisabled

---

## Family

- FamilyCreated
- RepresentativeAssigned
- RepresentativeRemoved

---

## Student

- StudentCreated
- StudentActivated
- StudentInactive

---

## Student Profile

- MedicalInformationUpdated
- EmergencyContactAdded
- EmergencyContactRemoved
- AuthorizedPickupAdded
- AuthorizedPickupRemoved
- TransportUpdated

---

## Enrollment

- EnrollmentStarted
- EnrollmentUpdated
- EnrollmentSubmitted
- EnrollmentReturned
- EnrollmentApproved
- EnrollmentRejected

---

# 21. Principios de Evolución

El modelo deberá evolucionar respetando las siguientes reglas.

## Nuevos roles

Los nuevos roles deberán derivar de Person.

Ejemplos:

- Teacher
- Staff
- Alumni
- Applicant

Nunca deberán duplicar información personal.

---

## Nuevos módulos

Los nuevos módulos deberán consumir el dominio existente.

No deberán crear entidades paralelas para representar personas.

---

## Nuevas funcionalidades

Toda nueva funcionalidad deberá responder primero a las siguientes preguntas:

1. ¿Existe ya una entidad que represente este concepto?
2. ¿Es un nuevo rol?
3. ¿Es una relación?
4. ¿Es simplemente un atributo?
5. ¿Debe convertirse en un nuevo Aggregate?

---

# 22. Principios para el Desarrollo

Todo desarrollo futuro deberá respetar las siguientes reglas.

## No duplicar información

La duplicación de datos personales está prohibida.

---

## No mezclar responsabilidades

Cada Aggregate posee una única responsabilidad principal.

---

## No acoplar módulos

Los módulos deben comunicarse mediante el dominio y nunca mediante dependencias innecesarias.

---

## Mantener independencia tecnológica

El dominio no depende de:

- MySQL;
- PHP;
- Bootstrap;
- Alpine.js;
- Framework MVC;
- infraestructura.

La implementación puede cambiar sin modificar este documento.

---

# 23. Resumen del Modelo

El modelo del dominio se construye sobre seis conceptos fundamentales.

| Concepto | Responsabilidad |
|----------|-----------------|
| Person | Identidad de toda persona. |
| User | Acceso y autenticación. |
| Representative | Business Role que habilita a una Person para administrar estudiantes y procesos de matrícula. |
| Student | Business Role que representa al alumno dentro del dominio académico. |
| Family | Unidad administrativa que agrupa representantes y estudiantes. |
| Student Profile | Información complementaria del estudiante. |

Toda la información del sistema deriva de estos conceptos.

---

# 24. Estado del Documento

Versión: **2.0**

Estado: **Aprobado**

Este documento constituye la referencia oficial para el diseño funcional del Sistema de Información Escolar (SIS).

Toda modificación futura del dominio deberá reflejarse primero en este documento antes de implementarse en código o en la base de datos.
