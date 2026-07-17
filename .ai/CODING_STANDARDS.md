# CODING_STANDARDS

**Proyecto:** Antares SIS  
**Versión:** 1.0

---

# Propósito

Este documento define los estándares de programación del proyecto Antares SIS.

Todo código incorporado al repositorio deberá cumplir estas normas, independientemente de si fue escrito por una persona o generado mediante inteligencia artificial.

El objetivo es mantener un código consistente, legible y fácil de mantener.

---

# Principios generales

Todo desarrollo deberá priorizar:

- Simplicidad.
- Legibilidad.
- Consistencia.
- Bajo acoplamiento.
- Alta cohesión.
- Mantenibilidad.

Cuando existan varias soluciones válidas, se elegirá siempre la más simple.

---

# Estándares oficiales

Todo el proyecto seguirá:

- PSR-1
- PSR-4
- PSR-12

---

# PHP

Versión oficial:

PHP 8.2

Todas las nuevas funcionalidades deberán utilizar características compatibles con esta versión.

---

# Strict Types

Todos los archivos PHP deberán comenzar con:

```php
<?php

declare(strict_types=1);
```

---

# Namespaces

Toda clase deberá pertenecer a un namespace.

Ejemplos:

```text
App\Controllers

App\Services

App\Repositories

App\Models

App\Core
```

No se utilizarán clases en el espacio global.

---

# Autoload

Todo el proyecto utilizará Composer con PSR-4.

Nunca deberán cargarse archivos mediante:

```php
require
require_once
include
include_once
```

excepto en el bootstrap inicial.

---

# Nombres de clases

Las clases utilizarán PascalCase.

Correcto:

```text
StudentService

EnrollmentRepository

LoginController
```

Incorrecto:

```text
studentService

student_service

studentservice
```

---

# Métodos

Los métodos utilizarán camelCase.

Ejemplos:

```php
findStudent()

createEnrollment()

calculateBalance()

approveEnrollment()
```

---

# Variables

Las variables utilizarán camelCase.

Ejemplos:

```php
$student

$academicYear

$familyId
```

---

# Constantes

Las constantes utilizarán MAYÚSCULAS.

Ejemplo:

```php
MAX_LOGIN_ATTEMPTS
```

---

# Archivos

El nombre del archivo deberá coincidir exactamente con el nombre de la clase.

Ejemplo:

```text
Student.php

StudentService.php

EnrollmentRepository.php
```

---

# Controladores

Los controladores:

- reciben la petición;
- validan parámetros básicos;
- invocan servicios;
- devuelven respuestas.

No deberán contener reglas del negocio.

---

# Servicios

Toda regla del negocio pertenece a la capa Service.

Ejemplos:

Correcto:

```text
EnrollmentService

AuthenticationService

InvoiceService
```

Incorrecto:

Controladores con cientos de líneas de lógica.

---

# Repositories

Los Repositories serán responsables únicamente del acceso a datos.

No deberán:

- validar reglas;
- calcular información;
- tomar decisiones del negocio.

---

# Modelos

Los modelos representan entidades.

No deberán:

- ejecutar SQL;
- generar HTML;
- conocer HTTP.

---

# Views

Las vistas contendrán únicamente lógica de presentación.

No deberán:

- consultar la base de datos;
- ejecutar reglas del negocio.

---

# Métodos

Los métodos deberán tener una única responsabilidad.

Si un método supera aproximadamente 40–50 líneas, deberá evaluarse su división.

---

# Funciones

Las funciones deberán tener nombres descriptivos.

Incorrecto:

```php
run()

execute()

process()
```

cuando el propósito no sea evidente.

Preferir:

```php
approveEnrollment()

sendRecoveryEmail()

calculateInvoiceTotal()
```

---

# Dependencias

Toda dependencia deberá inyectarse mediante el constructor.

Correcto:

```php
class StudentService
{
    public function __construct(
        private StudentRepository $repository
    ) {
    }
}
```

No utilizar instanciación directa dentro de los métodos cuando pueda evitarse.

---

# Base de datos

Todo acceso deberá realizarse mediante PDO.

Siempre utilizar:

Prepared Statements.

Nunca concatenar variables en SQL.

Incorrecto:

```php
SELECT * FROM students WHERE id = $id
```

Correcto:

consultas parametrizadas.

---

# Validaciones

Las validaciones deberán realizarse antes de ejecutar reglas del negocio.

Las validaciones complejas pertenecerán a clases específicas cuando sea necesario.

---

# Manejo de excepciones

Las excepciones deberán propagarse hasta el manejador correspondiente.

No utilizar:

```php
die();

exit();

echo "Error";
```

como mecanismo de control.

---

# Comentarios

El código deberá ser suficientemente claro para minimizar la necesidad de comentarios.

Se utilizarán comentarios únicamente cuando expliquen:

- decisiones importantes;
- algoritmos complejos;
- restricciones del negocio.

No comentar código evidente.

Incorrecto:

```php
$i++;
```

```php
// Incrementa i
```

---

# Duplicación

No duplicar lógica.

Si una funcionalidad comienza a repetirse, deberá extraerse a una clase reutilizable.

---

# Longitud de clases

Como regla general:

- Controllers pequeños.
- Services medianos.
- Repositories especializados.

Las clases extremadamente grandes deberán refactorizarse.

---

# Longitud de métodos

Preferiblemente:

Menos de 40 líneas.

No es una regla absoluta, pero sí un objetivo.

---

# Convenciones SQL

Tablas:

snake_case

Ejemplo:

```text
academic_years

emergency_contacts

authorized_pickups
```

Columnas:

snake_case

Ejemplo:

```text
first_name

created_at

deleted_at
```

---

# Claves primarias

Todas las tablas utilizarán:

```text
id
```

como clave primaria.

---

# Claves foráneas

Utilizar:

```text
student_id

family_id

institution_id
```

---

# Fechas

Las columnas estándar serán:

```text
created_at

updated_at

deleted_at
```

---

# Soft Delete

Cuando corresponda utilizar eliminación lógica mediante:

```text
deleted_at
```

---

# HTML

El HTML deberá ser limpio y correctamente indentado.

Evitar:

- estilos inline;
- JavaScript inline;
- HTML duplicado.

---

# JavaScript

Utilizar JavaScript Vanilla.

Alpine.js únicamente cuando simplifique la interacción de la interfaz.

No incorporar frameworks adicionales sin aprobación.

---

# CSS

Bootstrap será el framework principal.

Los estilos propios deberán mantenerse organizados y reutilizables.

---

# Seguridad

Todo dato recibido desde el usuario deberá considerarse no confiable.

Siempre validar.

Siempre escapar la salida cuando corresponda.

Nunca confiar en datos provenientes del navegador.

---

# Registro de errores

Los errores deberán registrarse mediante el sistema centralizado del proyecto.

No imprimir errores directamente al usuario.

---

# Refactorización

Antes de refactorizar deberá verificarse que no cambie el comportamiento funcional.

La prioridad será mantener el sistema estable.

---

# Principios de diseño

Todo el código deberá respetar:

- SOLID
- DRY
- KISS
- YAGNI

---

# Revisión de código

Antes de aceptar cualquier cambio verificar:

- ¿Es legible?
- ¿Es consistente?
- ¿Respeta la arquitectura?
- ¿Duplica lógica?
- ¿Puede simplificarse?
- ¿Cumple PSR-12?
- ¿Es fácil de mantener?

Si alguna respuesta es negativa, el cambio deberá revisarse antes de incorporarse.

---

# Estado del documento

Versión 1.0

Este documento constituye la referencia oficial de las convenciones de programación del proyecto Antares SIS.