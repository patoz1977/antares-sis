# Reglas para IA sobre Base de Datos (AI_DATABASE_RULES)

**Proyecto:** Antares SIS
**Versión:** 1.0
**Estado:** Aprobado
**Última actualización:** Julio 2026

---

# 1. Objetivo

Este documento establece las reglas que deberán seguir los asistentes de Inteligencia Artificial (ChatGPT, GitHub Copilot Agent y herramientas equivalentes) al analizar, diseñar o modificar la base de datos de Antares SIS.

Su finalidad es garantizar que toda propuesta sea consistente con la arquitectura oficial del proyecto y evitar la introducción de cambios incompatibles, redundantes o no documentados.

Este documento complementa:

- `ARCHITECTURE.md`
- `DOMAIN_MODEL.md`
- `DATABASE_DESIGN.md`
- `ERD.md`
- `CATALOGS.md`
- `SECURITY_GUIDELINES.md`
- `CODING_STANDARDS.md`

---

# 2. Alcance

Estas reglas aplican a cualquier actividad realizada por una IA relacionada con:

- diseño del modelo de datos;
- creación de nuevas tablas;
- modificación de tablas existentes;
- definición de relaciones;
- creación de índices;
- generación de migraciones;
- generación de modelos;
- generación de repositories;
- generación de consultas SQL;
- generación de seeders.

---

# 3. Jerarquía Documental

Antes de proponer cualquier modificación a la base de datos, la IA deberá consultar la documentación del proyecto en el siguiente orden:

1. `ARCHITECTURE.md`
2. `DOMAIN_MODEL.md`
3. `DATABASE_DESIGN.md`
4. `ERD.md`
5. `CATALOGS.md`
6. `DECISIONS.md`

Si existe una discrepancia entre documentos, prevalecerá el siguiente orden:

1. ARCHITECTURE
2. DOMAIN_MODEL
3. DATABASE_DESIGN
4. ERD
5. CATALOGS

La IA nunca deberá inventar reglas cuando la documentación oficial ya las define.

---

# 4. Principios Generales

Toda propuesta deberá respetar los siguientes principios:

- simplicidad;
- consistencia;
- normalización;
- reutilización;
- mantenibilidad;
- trazabilidad;
- compatibilidad con la arquitectura existente.

Las decisiones deberán favorecer siempre la evolución del sistema a largo plazo.


---

# 4.1 Prohibición de Supuestos

La IA nunca deberá asumir:

- nombres de tablas;
- nombres de columnas;
- relaciones;
- índices;
- catálogos;
- restricciones;
- reglas de negocio.

Toda propuesta deberá basarse exclusivamente en la documentación oficial del proyecto.

Cuando exista incertidumbre, la IA deberá solicitar aclaración antes de generar cambios.

---

# 5. Flujo Obligatorio para Cambios

La IA nunca deberá modificar el esquema directamente.

Todo cambio seguirá obligatoriamente el siguiente flujo:

1. actualizar la documentación correspondiente;
2. revisar consistencia con el dominio;
3. actualizar `DATABASE_DESIGN.md`;
4. actualizar `ERD.md` cuando existan nuevas relaciones;
5. actualizar `CATALOGS.md` cuando corresponda;
6. generar la implementación;
7. validar consistencia;
8. actualizar `ROADMAP.md` cuando corresponda.

La implementación nunca será el primer paso.

---

# 6. Reglas para Nuevas Tablas

Antes de proponer una nueva tabla, la IA deberá verificar que:

- no exista una tabla equivalente;
- no pueda reutilizarse una existente;
- la responsabilidad sea única;
- exista una necesidad funcional clara.

No deberán crearse tablas para almacenar información temporal, derivada o duplicada salvo que exista una justificación arquitectónica documentada.

---

# 7. Reglas para Columnas

Toda nueva columna deberá:

- tener un propósito claramente definido;
- seguir la nomenclatura oficial (`snake_case`);
- utilizar tipos de datos apropiados;
- evitar abreviaturas;
- evitar información redundante;
- respetar las convenciones del proyecto.

La IA nunca deberá proponer columnas cuyo significado ya esté representado por otra existente.

---

# 8. Claves Primarias y Foráneas

La IA deberá respetar las siguientes reglas:

- todas las tablas utilizan `id` como clave primaria;
- las claves foráneas utilizan el formato `<tabla_singular>_id`;
- toda relación deberá documentarse;
- las restricciones de integridad deberán mantenerse.

No deberán proponerse relaciones implícitas.

---

# 9. Catálogos

Antes de crear un nuevo catálogo la IA deberá comprobar:

- que no exista uno equivalente;
- que realmente requiera administración;
- que no sea suficiente una regla interna del sistema.

Todo catálogo nuevo deberá:

1. documentarse en `CATALOGS.md`;
2. incorporarse a `DATABASE_DESIGN.md`;
3. representarse en `ERD.md` cuando genere relaciones.

---

# 10. Auditoría y Soft Delete

La IA deberá respetar las convenciones definidas para auditoría.

No deberá modificar la estrategia de auditoría ni de eliminación lógica sin una decisión arquitectónica documentada.

Las columnas de auditoría nunca deberán redefinirse de forma distinta entre tablas equivalentes.

---

# 11. Índices

Antes de proponer un índice la IA deberá evaluar:

- claves primarias;
- claves foráneas;
- consultas frecuentes;
- restricciones de unicidad.

No deberán generarse índices innecesarios ni duplicados.

---

# 12. Consultas SQL

Toda consulta generada por la IA deberá:

- utilizar consultas parametrizadas;
- evitar concatenación de variables;
- respetar las relaciones oficiales;
- utilizar nombres definidos en `DATABASE_DESIGN.md`.

No deberán generarse consultas basadas en nombres inventados.

---

# 13. Migraciones

Toda migración propuesta deberá ser consistente con la documentación vigente.

La IA nunca deberá:

- crear tablas no documentadas;
- eliminar tablas sin justificación;
- modificar relaciones sin actualizar la documentación;
- romper compatibilidad con módulos existentes.

---

# 14. Restricciones Arquitectónicas

La IA deberá respetar permanentemente las siguientes decisiones:

- una base de datos por institución;
- arquitectura White Label;
- arquitectura Multi-Institution;
- ausencia de `institution_id` en todas las tablas;
- Person como identidad central;
- Family como Aggregate Root;
- Status como mecanismo oficial de estados;
- una única fuente de verdad para el modelo físico (`DATABASE_DESIGN.md`);
- una única representación oficial del modelo (`ERD.md`).

Estas decisiones no deberán modificarse sin una actualización previa de `DECISIONS.md`.

---

# 15. Validaciones Obligatorias

Antes de proponer cualquier modificación la IA deberá comprobar:

- ¿Existe ya esta entidad?
- ¿Existe ya esta relación?
- ¿Se reutiliza un catálogo existente?
- ¿Se mantiene la normalización?
- ¿Respeta las convenciones?
- ¿Es consistente con el dominio?
- ¿Está documentado?

Si alguna respuesta es negativa, deberá revisarse la propuesta antes de implementarla.

---

# 16. Lista de Comprobación para IA

Antes de generar código relacionado con base de datos, la IA deberá verificar:

- Arquitectura revisada.
- Modelo de dominio revisado.
- Diseño físico revisado.
- ERD revisado.
- Catálogos revisados.
- Restricciones arquitectónicas respetadas.
- Relaciones validadas.
- Convenciones respetadas.
- Seguridad considerada.
- Compatibilidad preservada.
- Documentación sincronizada.
- No existen duplicidades funcionales.

---

# 17. Evolución del Documento

Este documento constituye la referencia oficial para cualquier IA que participe en el desarrollo de Antares SIS.

Toda nueva regla relacionada con el diseño o modificación de la base de datos deberá incorporarse aquí antes de aplicarse al proyecto.

Su objetivo es garantizar que todas las herramientas de IA produzcan propuestas consistentes, repetibles y alineadas con la arquitectura oficial del sistema.


Este documento está dirigido exclusivamente a herramientas de Inteligencia Artificial y complementa, pero no sustituye, las normas de desarrollo aplicables a los desarrolladores del proyecto.
