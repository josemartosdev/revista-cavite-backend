# Revista Cavite — Backend PHP

API en PHP plano (sin framework) con PDO, que implementa el contrato descrito en
[BACKEND_PHP.md](../revista-cavite-front/BACKEND_PHP.md) del frontend.

## Requisitos

- PHP 8.1+ (en este equipo: XAMPP, `C:\xampp\php\php.exe`)
- MySQL/MariaDB (en este equipo: `C:\xampp\mysql`)
- Composer (solo para el autoload PSR-4, no hay dependencias externas)

## Puesta en marcha

1. Copia el archivo de entorno y ajusta credenciales si hace falta:

   ```bash
   cp .env.example .env
   ```

2. Instala el autoload de Composer:

   ```bash
   composer install
   ```

3. Arranca MySQL (XAMPP Control Panel o `mysql_start.bat`) y crea el esquema:

   ```bash
   mysql -u root < sql/schema.sql
   ```

   (o importa `sql/schema.sql` desde phpMyAdmin)

4. Crea el usuario inicial de redacción/administración:

   ```bash
   php bin/create-user.php
   ```

5. Arranca el servidor de desarrollo PHP:

   ```bash
   php -S localhost:8000 -t public public/index.php
   ```

6. En el frontend, configura en `.env.local`:

   ```
   VITE_API_BASE_URL=http://localhost:8000/api/v1
   ```

## Notas

- CORS solo permite el origen configurado en `CORS_ALLOWED_ORIGIN` (por defecto `http://localhost:5173`, el de Vite).
- Los tokens de sesión son aleatorios (32 bytes), se guardan solo como SHA-256 en `auth_tokens` y caducan según `TOKEN_TTL_HOURS`.
- No hay endpoint público de registro: los usuarios se crean con `bin/create-user.php`.
- El esquema y las reglas de validación siguen exactamente `BACKEND_PHP.md` del frontend.
