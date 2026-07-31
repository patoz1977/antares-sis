# AUDIT-001 | Auditoría de fuentes y reconstrucción documental del dominio de matrícula

**Proyecto:** Antares SIS  
**Repositorio:** `patoz1977/antares-sis`  
**Rama auditada:** `docs/enrollment-domain-clean-rebuild`  
**Fecha:** 2026-07-30  
**Estado:** Propuesta de corrección lista para ejecución ordenada

---

## 1. Objetivo

Auditar las fuentes y documentos derivados del dominio de matrícula, identificar inconsistencias, definir la corrección concreta de cada una y establecer el orden de reconstrucción que permita volver a una documentación oficial coherente, implementable y sin parches acumulativos.

## 2. Documentos auditados

- `.ai/00.INDEX.md`
- `.ai/01.PROJECT_CONTEXT.md`
- `.ai/10.ARCHITECTURE.md`
- `.ai/15.ENROLLMENT_DOMAIN_DISCOVERY.md`
- `.ai/rebuild/11.DOMAIN_MODEL.md`
- `.ai/14.CATALOGS.md`
- `.ai/20.DECISIONS.md`
- `.ai/rebuild/12.DATABASE_DESIGN.md`
- `.ai/rebuild/13.ERD.md`

También se evaluaron las carpetas temporales:

- `.ai/rebuild/`
- `.ai/prompts/` o `.ai/promts/`, si existe con esa grafía

---

# 3. Conclusión ejecutiva

El núcleo conceptual del workshop es válido y aprovechable. No corresponde reiniciar todo el proyecto.

Sin embargo:

1. `15.ENROLLMENT_DOMAIN_DISCOVERY.md` mezcla descubrimiento de negocio con instrucciones operativas, decisiones técnicas y criterios de sincronización.
2. `11.DOMAIN_MODEL.md` mejora sustancialmente el modelo anterior, pero contiene fronteras de Aggregate y Bounded Context inconsistentes.
3. `10.ARCHITECTURE.md` todavía expresa una arquitectura centrada en Services y Models anémicos, incompatible con el modelo táctico DDD aprobado.
4. `14.CATALOGS.md` está desactualizado y mezcla catálogos simples con conceptos académicos que requieren semántica propia.
5. `12.DATABASE_DESIGN.md` y `13.ERD.md` no deben corregirse incrementalmente: deben descartarse y reconstruirse desde fuentes aprobadas.
6. ADR-0010 es válida como infraestructura de estados, pero debe complementarse con la asignación explícita de tipos de estado y con una política que evite añadir `status_id` indiscriminadamente.

La estrategia correcta es:

```text
limpiar y cerrar Discovery
→ corregir Architecture
→ aprobar Domain Model
→ completar ADR
→ reconstruir Catalogs
→ reconstruir Database Design
→ regenerar ERD
→ sincronizar estado, roadmap y log
```

---

# 4. Correcciones por documento

## 4.1 `.ai/00.INDEX.md`

### Diagnóstico

El índice no registra `15.ENROLLMENT_DOMAIN_DISCOVERY.md`, aunque ese documento se utiliza como fuente canónica del dominio de matrícula.

### Corrección

Agregarlo en **Analysis & Design**, después de `14.CATALOGS.md`, con esta responsabilidad:

```markdown
| 15.ENROLLMENT_DOMAIN_DISCOVERY.md | Registro aprobado del descubrimiento funcional y táctico del dominio de matrícula; fuente de negocio para sincronizar los documentos oficiales derivados. | Cuando cambien decisiones aprobadas del workshop de matrícula. |
```

Agregar el orden de lectura específico para cambios del dominio de matrícula:

```text
00.INDEX
→ 01.PROJECT_CONTEXT
→ 10.ARCHITECTURE
→ 15.ENROLLMENT_DOMAIN_DISCOVERY
→ 11.DOMAIN_MODEL
→ 20.DECISIONS
→ 14.CATALOGS
→ 12.DATABASE_DESIGN
→ 13.ERD
```

No convertir este orden en una dependencia circular: `20.DECISIONS.md` se consulta durante todo el proceso y se actualiza antes de implementar decisiones nuevas.

---

## 4.2 `.ai/01.PROJECT_CONTEXT.md`

### Diagnóstico

El documento cumple correctamente su responsabilidad. No contiene reglas detalladas ni decisiones físicas.

La única precisión necesaria es evitar que “soporte para múltiples instituciones” se interprete automáticamente como una base compartida multi-tenant. El Domain Model reconstruido afirma “una base de datos por institución”, pero esa decisión no aparece aún como decisión arquitectónica oficial.

### Corrección

No reconstruir el documento.

Agregar en **Multi-Institution Strategy** una frase neutral:

```markdown
The product supports multiple institutions through a shared codebase. The data-isolation and deployment strategy is defined exclusively in `10.ARCHITECTURE.md` and the corresponding ADRs.
```

No escribir aquí “una base de datos por institución”; esa es una decisión arquitectónica.

---

## 4.3 `.ai/10.ARCHITECTURE.md`

### Diagnóstico

Requiere una actualización estructural, no un parche menor.

Inconsistencias principales:

- afirma que toda regla de negocio vive exclusivamente en `Service`;
- define `Model` como representación pasiva de estado;
- no reconoce Aggregates, Value Objects, Domain Services ni Application Services;
- no define los Bounded Contexts aprobados;
- propone organización genérica por `Controllers/Services/Repositories/Models`, incompatible con modularidad por contexto;
- describe autorización por roles, insuficiente para la cadena `User → Person → Representative → FamilyRepresentative → Family`;
- no documenta la estrategia “una base de datos por institución” que aparece en el modelo reconstruido;
- mezcla arquitectura permanente con ejemplos funcionales específicos.

### Corrección

Mantener la arquitectura MVC, PHP, PDO, hosting compartido y framework propio, pero reemplazar el modelo de capas por:

```text
HTTP / UI
→ Application
→ Domain
→ Infrastructure
```

Con MVC en la capa de entrega:

- Controllers: adaptación HTTP y coordinación de casos de uso.
- Application Services: orquestación de casos de uso, transacciones y autorización.
- Domain: Aggregates, Entities, Value Objects, Domain Services, invariantes y Domain Events.
- Repositories: contratos definidos cerca del dominio o aplicación; implementaciones en Infrastructure.
- Infrastructure: PDO, persistencia, sesiones, hashing, email, archivos y adaptadores externos.
- Views: presentación exclusivamente.

Reemplazar “Toda regla de negocio deberá implementarse en Services” por:

```markdown
Business invariants belong to Aggregates, Entities and Value Objects. Cross-Aggregate business rules belong to Domain Services. Use-case orchestration, authorization and transaction coordination belong to Application Services. Controllers and Repositories do not contain business rules.
```

Definir el sistema como **modular monolith** con módulos lógicos:

```text
identity_access
family_management
academic_core
enrollment
catalogs
institutional_documents
```

La estructura física exacta puede evolucionar incrementalmente; no obligar todavía a una reorganización masiva del código existente.

Definir autorización contextual:

```text
Authentication establishes User identity.
Authorization validates the requested institutional role, active membership and selected Family on the server for every protected operation.
```

Registrar la estrategia de aislamiento:

```text
Una instancia desplegada utiliza una base de datos por institución.
El producto conserva un único código fuente y configuración independiente por institución.
No se añade institution_id a todas las tablas del MVP.
La evolución futura hacia otra estrategia de tenancy requerirá una ADR.
```

Actualizar el apartado de seguridad para referenciar `31.SECURITY_GUIDELINES.md` y evitar duplicar detalles.

---

## 4.4 `.ai/15.ENROLLMENT_DOMAIN_DISCOVERY.md`

### Diagnóstico

El contenido funcional principal es sólido, pero el documento contiene responsabilidades ajenas al discovery y varias ambigüedades que bloquearon el diseño físico.

### Correcciones estructurales

#### A. Agregar criterios de cierre

Después de `Purpose`, agregar:

```markdown
## Discovery completion criteria

The discovery is complete when:

- every business concept has one approved meaning;
- data ownership is explicit;
- actors, workflows, invariants and lifecycle transitions are approved;
- Aggregate and Bounded Context boundaries are sufficiently defined;
- unresolved business questions are listed explicitly;
- no downstream document must invent a business rule.

The discovery does not define SQL tables, column types, indexes, foreign-key strategies, framework paths or deployment mechanisms.
```

#### B. Agregar clasificación de decisiones pendientes

Agregar una sección final `Open Business Decisions`.

Debe estar vacía cuando el documento pase a estado aprobado.

Las decisiones técnicas no se registran allí; se registran en `20.DECISIONS.md`.

#### C. Eliminar contenido operativo

Eliminar del Discovery:

- sección `Required Documentation Corrections`;
- sección `Document Responsibilities`;
- criterios de aceptación de sincronización documental;
- instrucciones dirigidas a Códex;
- referencias a commits, rutas, migraciones o archivos que debe modificar;
- estado que anuncia el siguiente entregable técnico.

Esos contenidos pertenecen a `AUDIT-001`, `40.WORKFLOW.md`, `02.CURRENT_STATUS.md`, `03.ROADMAP.md` o `04.DEVELOPMENT_LOG.md`.

#### D. Eliminar decisiones físicas

Sustituir:

```text
The physical model must avoid unsafe polymorphic foreign keys...
```

por una regla conceptual:

```text
Representative and Student address assignments are distinct responsibilities and must preserve referential integrity within the same Family.
```

Eliminar referencias a “repository existente” o a si debe crearse físicamente un `UserRepository`. El Discovery puede identificar que `User` pertenece a Identity & Access, pero no decidir su mecanismo de persistencia.

#### E. Corregir la frontera de `User`

`User` no debe ser una Entity interna del Aggregate `Person`.

Definición corregida:

```text
Person is the institutional human identity.
User is an independent Aggregate Root in Identity & Access that references one Person.
A Person may exist without User.
A User cannot exist without Person.
```

Motivo: credenciales, bloqueo, acceso y ciclo de vida de cuenta cambian independientemente de los datos personales y no deben compartir el mismo límite transaccional.

Clasificación:

```text
Person: Aggregate Root
User: Aggregate Root
```

Agregar `UserRepository` como contrato conceptual de Identity & Access. La implementación física se define después.

#### F. Corregir `InstitutionalDocument`

Mantener `InstitutionalDocument` como Aggregate Root y sus versiones como entidades internas.

`DocumentRequirement` debe representar la aplicabilidad de una versión a un AcademicPeriod. Su persistencia puede ser una relación, pero conceptualmente pertenece al contexto Institutional Documents.

#### G. Precisar catálogos y Academic Core

Reemplazar la lista única de catálogos por esta clasificación conceptual:

**Reference catalogs**

- DocumentType
- Sex
- MaritalStatus
- EducationLevel
- RelationshipType
- Province
- Canton
- Parish

**Academic reference/configuration concepts**

- Grade
- Section
- AcademicPeriod

`Grade`, `Section` y `AcademicPeriod` pertenecen a Academic Core. Pueden persistirse mediante tablas de referencia, pero no deben clasificarse automáticamente como catálogos homogéneos administrados por Catalogs.

#### H. Precisar parentesco en recursos familiares

Definir en recursos vivos:

```text
FamilyEmergencyContact.RelationshipTypeId
FamilyAuthorizedPickup.RelationshipTypeId
```

No almacenar parentesco como texto libre en los recursos vivos.

En snapshots almacenar:

```text
RelationshipTypeCode
RelationshipTypeName
```

Esto conserva el significado histórico aunque el nombre del catálogo cambie.

#### I. Precisar prioridad de contactos de emergencia

Definir:

```text
EmergencyContactAssignment.Priority?
```

Reglas:

- puede ser nula;
- cuando se informa, debe ser un entero positivo;
- no puede repetirse entre asignaciones activas del mismo Student;
- el orden de atención usa primero Priority y luego un orden estable de creación;
- la matrícula exige al menos una asignación activa, pero no exige que todas tengan prioridad.

#### J. Precisar documentos de autorizados para retiro

Cambiar `DocumentNumber` por:

```text
DocumentTypeId
DocumentNumber
```

Ambos obligatorios para un autorizado activo utilizado en Submission.

El snapshot conserva:

```text
DocumentTypeCode
DocumentTypeName
DocumentNumber
```

#### K. Precisar snapshots

El snapshot conserva únicamente los recursos seleccionados en el envío:

- una dirección del Student;
- contactos de emergencia activos asignados al Student;
- autorizados activos asignados al Student.

Debe copiar códigos y nombres visibles de catálogos necesarios para lectura histórica, no FKs mutables.

No debe copiar recursos no asignados.

#### L. Precisar estados

El Discovery define únicamente el ciclo completo de `Enrollment`.

Para los demás conceptos, usar semántica explícita:

- membresías y asignaciones históricas: `StartedAt` / `EndedAt`;
- bloqueo de User: `LockedAt?`;
- disponibilidad de recursos y referencias: activo/inactivo;
- no introducir nuevos estados de negocio no descubiertos.

La asignación física de `status_id` se documenta en ADR y Database Design.

#### M. Ajustar Domain Events

Mover `UserCreated`, `UserActivated` y `UserDisabled` al Aggregate `User`, no a `Person`.

No aprobar eventos potenciales sin consumidor real. Mantener como esenciales únicamente los eventos con significado y consumidor del MVP.

---

## 4.5 `.ai/rebuild/11.DOMAIN_MODEL.md`

### Diagnóstico

Debe utilizarse como base, pero no promoverse directamente al documento oficial.

Correcciones requeridas:

1. `User` debe ser Aggregate Root, no Entity interna de `Person`.
2. `UserRepository` debe existir como contrato conceptual.
3. Los eventos de User deben originarse en User.
4. `AcademicPeriod`, `Grade` y `Section` deben pertenecer a Academic Core.
5. Catalogs no debe declararse propietario de las jerarquías académicas.
6. `InstitutionalDocument` debe conservarse como Aggregate Root independiente.
7. `Relationship` de contactos y autorizados debe reemplazarse por `RelationshipTypeId` en datos vivos y valores históricos en snapshot.
8. `FamilyAuthorizedPickup` debe incluir `DocumentTypeId`.
9. Debe documentarse `Priority?` con sus reglas exactas.
10. Debe eliminar afirmaciones físicas o de framework.
11. Debe incorporar un apartado de “reglas no definidas” para impedir inferencias posteriores.
12. La frase “una base de datos por institución” debe referenciar la ADR arquitectónica y no presentarse como regla del dominio.
13. Debe mantenerse la separación entre información anual de Enrollment y recursos reutilizables de Family.
14. Debe conservarse BillingAddress como texto anual independiente, de acuerdo con la decisión ya aprobada.
15. Debe corregirse el Context Map para incluir:
    - User como Aggregate de Identity & Access;
    - Academic Core como propietario de AcademicPeriod, Grade y Section;
    - Institutional Documents consumiendo AcademicPeriod;
    - Enrollment consumiendo referencias, no entidades mutables.

### Acción

Reconstruir el documento completo conservando sus secciones útiles, no aplicar reemplazos aislados.

Cuando quede aprobado, mover su contenido final a:

```text
.ai/11.DOMAIN_MODEL.md
```

El archivo temporal de `.ai/rebuild/` se elimina después de verificar equivalencia y commit.

---

## 4.6 `.ai/20.DECISIONS.md`

### Diagnóstico

ADR-0005 contradice el modelo DDD porque establece que toda regla vive exclusivamente en Services.

ADR-0004 es demasiado general para resolver tenancy.

ADR-0010 define correctamente la infraestructura base de estados, pero su consecuencia puede interpretarse como obligación de añadir `status_id` a toda tabla.

### Correcciones

#### ADR-0005

No editar su significado histórico. Marcarla `Reemplazada`.

Crear una nueva ADR:

```text
ADR-0011 — Distribución de reglas de negocio
```

Decisión:

- invariantes en Aggregates, Entities y Value Objects;
- reglas cross-Aggregate en Domain Services;
- orquestación, transacción y autorización en Application Services;
- Controllers y Repositories sin reglas de negocio.

#### Nueva ADR de tenancy

Crear:

```text
ADR-0012 — Una base de datos por institución
```

Decisión:

- una base por institución en el MVP;
- mismo código fuente;
- configuración y despliegue independientes;
- no usar `institution_id` en cada tabla;
- cualquier cambio futuro requiere nueva ADR.

#### Nueva ADR de Identity & Access

Crear:

```text
ADR-0013 — User como Aggregate Root y credencial local
```

Decisión:

- User referencia exactamente una Person;
- User es Aggregate Root independiente;
- autenticación local en el MVP;
- persistir identificador normalizado único y `password_hash`;
- nunca persistir contraseña reversible;
- `locked_at` representa bloqueo temporal;
- estado de cuenta usa `USER_STATUS`;
- estudiantes no tienen User en el MVP.

#### Complemento de estados

Crear:

```text
ADR-0014 — Aplicación de estados y vigencia
```

Decisión:

- ADR-0010 define infraestructura, no obliga a toda tabla a usar `status_id`;
- `ENROLLMENT_STATUS`: DRAFT, SUBMITTED, COMPLETED, CANCELLED;
- `USER_STATUS`: ACTIVE, DISABLED;
- `GENERAL_STATUS`: ACTIVE, INACTIVE para Aggregate Roots y recursos configurables que requieren desactivación;
- membresías y asignaciones históricas usan `started_at` / `ended_at`, sin `status_id`;
- bloqueo de User usa `locked_at`, no un estado LOCKED;
- tablas snapshot no usan `status_id`.

#### Nueva ADR de snapshots

Crear:

```text
ADR-0015 — Snapshots históricos desacoplados de catálogos
```

Decisión:

- snapshots copian valores visibles y códigos estables necesarios;
- no dependen de FKs a recursos familiares ni catálogos mutables;
- un snapshot vigente por Enrollment;
- resubmission reemplaza el snapshot;
- hijos internos se eliminan y recrean atómicamente dentro del Aggregate.

---

## 4.7 `.ai/14.CATALOGS.md`

### Diagnóstico

Debe reconstruirse.

Problemas:

- contiene `genders`, `nationalities`, `blood_types` y `transport_types`, no respaldados por el discovery vigente;
- no contiene `marital_statuses` ni `education_levels`;
- trata `status_types` y `statuses` con ejemplos genéricos que contradicen ADR-0010;
- clasifica `Grade`, `Section` y `AcademicPeriod` como catálogos sin respetar Academic Core;
- afirma que todos los catálogos mantienen estructura física homogénea, lo cual es falso para jerarquías geográficas y estados;
- mezcla arquitectura de catálogos con diseño físico parcial.

### Corrección

Reconstruir con estas categorías:

1. **Infrastructure catalogs**
   - status_types
   - statuses

2. **General reference catalogs**
   - document_types
   - sexes
   - marital_statuses
   - education_levels
   - relationship_types

3. **Geographic reference hierarchy**
   - provinces
   - cantons
   - parishes

4. **Academic Core references — documented by reference only**
   - grades
   - sections
   - academic_periods

Aclarar que el detalle físico definitivo vive en `12.DATABASE_DESIGN.md`.

Eliminar:

- genders
- nationalities
- blood_types
- transport_types
- enrollment_statuses como tabla separada
- cualquier valor oficial no aprobado
- afirmación de estructura física idéntica para todos los catálogos

Documentar códigos de status aprobados por ADR, sin inventar otros valores de negocio.

---

## 4.8 `.ai/rebuild/12.DATABASE_DESIGN.md`

### Diagnóstico

El documento actual no es corregible de manera segura.

Evidencias:

- repite una plantilla genérica en todas las tablas;
- `status_types` contiene erróneamente `status_id`;
- `statuses` contiene erróneamente `status_id` en vez de `status_type_id`;
- no define columnas reales de la mayoría de tablas;
- no define FKs específicas;
- no documenta constraints implementables;
- aplica estado indiscriminadamente;
- no representa Value Objects completos;
- aparenta estar completo sin ser implementable.

### Acción

Eliminar el borrador actual y reconstruirlo desde cero después de aprobar:

1. Architecture;
2. Discovery;
3. Domain Model;
4. ADR-0011 a ADR-0015;
5. Catalogs.

La reconstrucción debe definir, tabla por tabla:

- propósito;
- Aggregate propietario;
- columnas completas;
- tipos y longitudes;
- nullability;
- defaults;
- PK;
- FKs y acciones;
- UNIQUE;
- CHECK;
- índices;
- reglas de eliminación;
- reglas transaccionales no expresables por SQL;
- mapeo de Value Objects;
- lifecycle e historia.

No usar texto genérico repetido.

---

## 4.9 `.ai/rebuild/13.ERD.md`

### Diagnóstico

Debe descartarse y regenerarse.

Problemas:

- `status_types.status_id` es inválido;
- `statuses.status_id` es inválido;
- casi todas las entidades solo muestran `id` y `status_id`;
- faltan columnas y FKs esenciales;
- snapshots reciben estado sin justificación;
- cardinalidades dependen de un Database Design incompleto;
- el diagrama aparenta precisión física sin tenerla.

### Acción

Regenerar únicamente desde el `12.DATABASE_DESIGN.md` aprobado.

El ERD:

- no toma decisiones;
- no agrega tablas;
- no cambia cardinalidades;
- no omite FKs relevantes;
- puede dividirse en diagramas por Bounded Context más un diagrama integrado;
- debe renderizar correctamente en Mermaid.

---

# 5. Carpetas temporales

## `.ai/rebuild/`

### Decisión

Mantenerla únicamente durante la reconstrucción.

Reglas:

1. contiene borradores completos que sustituyen documentos oficiales;
2. no se considera fuente oficial;
3. cada borrador aprobado reemplaza su archivo oficial;
4. después del reemplazo, validación y commit, se elimina el borrador correspondiente;
5. cuando no queden borradores pendientes, eliminar la carpeta completa.

No dejar duplicados permanentes entre `.ai/` y `.ai/rebuild/`.

## `.ai/prompts/`

### Decisión

Mantenerla solo si contiene prompts reutilizables, generales y vigentes.

Mover o conservar allí `DOCUMENT_REBUILD.md` si realmente existe y corregir cualquier grafía `.ai/promts` a `.ai/prompts`.

Agregar la carpeta al índice documental únicamente como **herramientas de trabajo**, no como fuente de arquitectura o negocio.

Eliminar prompts:

- específicos de una ejecución ya terminada;
- que contengan decisiones de negocio;
- que dupliquen `40.WORKFLOW.md`;
- que contradigan la documentación oficial.

Los prompts nunca prevalecen sobre `.ai/*.md`.

---

# 6. Estrategia de ramas

## Rama actual: `docs/enrollment-domain-clean-rebuild`

Mantenerla hasta completar la reconstrucción documental.

No crear una rama nueva por cada documento.

Trabajar en commits atómicos y ordenados dentro de la misma rama:

1. `docs: close enrollment domain discovery`
2. `docs: align architecture with domain model`
3. `docs: finalize enrollment domain model`
4. `docs: record enrollment architecture decisions`
5. `docs: rebuild system catalogs`
6. `docs: rebuild enrollment database design`
7. `docs: regenerate enrollment ERD`
8. `docs: synchronize project documentation`
9. `chore: remove completed rebuild drafts`

Cuando todo esté validado:

- abrir un Pull Request hacia la rama principal de integración;
- revisar diff completo;
- hacer merge;
- eliminar la rama remota `docs/enrollment-domain-clean-rebuild`;
- eliminar la rama local después de actualizar la rama principal.

## Otras ramas creadas durante intentos anteriores

Para cada rama:

- si tiene commits no presentes en la rama actual o principal, compararla antes de eliminar;
- si solo contiene intentos superseded por esta reconstrucción, eliminarla;
- si contiene cambios útiles, integrarlos explícitamente mediante cherry-pick o una nueva implementación; no fusionar la rama completa sin revisión;
- no conservar ramas históricas como mecanismo de documentación: Git y los commits ya preservan el historial.

---

# 7. Orden obligatorio de ejecución

```text
1. 00.INDEX
2. 01.PROJECT_CONTEXT
3. 15.ENROLLMENT_DOMAIN_DISCOVERY
4. 10.ARCHITECTURE
5. 11.DOMAIN_MODEL
6. 20.DECISIONS
7. 14.CATALOGS
8. 12.DATABASE_DESIGN
9. 13.ERD
10. 02.CURRENT_STATUS
11. 03.ROADMAP
12. 04.DEVELOPMENT_LOG
13. cleanup de .ai/rebuild
```

La secuencia operativa pone Discovery antes de Architecture porque primero se limpia la fuente auditada; Architecture se corrige antes de promover el Domain Model final para que sus límites técnicos sean compatibles.

---

# 8. Validaciones obligatorias

Antes de finalizar:

- no existe `Student Profile`;
- no existe `EnrollmentProcess` ni `StudentEnrollment`;
- User es Aggregate Root independiente;
- los eventos de User se originan en User;
- Grade, Section y AcademicPeriod pertenecen a Academic Core;
- Catalogs no contiene valores fuera del alcance aprobado;
- `Occupation` es texto libre;
- no hay `genders` si el término oficial es `sexes`;
- no hay `nationalities`, `blood_types` ni `transport_types` sin nueva decisión;
- `status_types` no contiene `status_id`;
- `statuses` contiene `status_type_id`;
- snapshots no contienen `status_id`;
- membresías y asignaciones históricas usan vigencia temporal;
- BillingAddress sigue siendo texto anual independiente;
- Database Design define todas las columnas de forma implementable;
- ERD coincide exactamente con Database Design;
- no quedan duplicados oficiales en `.ai/rebuild`;
- Mermaid y enlaces Markdown renderizan;
- `git status` queda limpio al finalizar cada commit;
- no se modifica código de producción ni migraciones.

---

# 9. Resultado esperado

Al terminar:

- Discovery queda cerrado como fuente de negocio;
- Architecture y Domain Model son compatibles;
- ADR conserva las decisiones permanentes;
- Catalogs refleja el alcance real;
- Database Design es implementable;
- ERD es una representación fiel;
- la documentación oficial vive únicamente en `.ai/`;
- `.ai/rebuild/` desaparece;
- `.ai/prompts/` queda solo como biblioteca reutilizable;
- la rama de reconstrucción queda lista para Pull Request y posterior eliminación.
