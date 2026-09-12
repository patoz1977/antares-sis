# Antares SIS

Sistema de Información Escolar desarrollado en PHP 8.2 bajo arquitectura MVC con capas de Services y Repositories.

## Desarrollo local

- PHP 8.2+
- Composer 2.x
- MariaDB 10.4+ o MySQL 8+
- XAMPP como entorno local admitido para desarrollo y validación desechable de MariaDB
- Git

Preparación del entorno local:

```bash
composer install
cp .env.example .env
composer dump-autoload
```

Después de copiar `.env.example`, configura `APP_ENV=local`, `APP_DEBUG=true` y credenciales exclusivas del entorno local.

## Instalación productiva manual

El hosting compartido no necesita SSH, terminal ni Composer. `vendor/` se prepara localmente con:

```bash
composer install --no-dev --optimize-autoloader
```

La aplicación se copia mediante FTP/File Manager, el dominio o subdominio debe apuntar a `<application-root>/public` y la base vacía se instala importando desde el equipo local el `install.sql` institucional mediante phpMyAdmin.

Consulta el procedimiento y los requisitos completos en [Manual Shared Hosting Deployment Baseline](.ai/18.DEPLOYMENT_BASELINE.md).

## Documentación

Toda la documentación del proyecto se encuentra en la carpeta `.ai`.
