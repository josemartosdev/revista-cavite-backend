<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $requestedFile = __DIR__ . $requestedPath;
    if ($requestedPath !== '/' && is_file($requestedFile)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\ArticleController;
use App\Controllers\AuthController;
use App\Controllers\MediaController;
use App\Controllers\SiteConfigController;
use App\Env;
use App\Response;

Env::load(__DIR__ . '/../.env');

ini_set('display_errors', '0');

// CORS_ALLOWED_ORIGIN admite varios orígenes separados por comas. Cada entrada
// puede ser un origen exacto (https://ejemplo.com) o un patrón con un único
// comodín "*" (https://*.vercel.app) para permitir despliegues de Vercel
// preview, cuya URL cambia en cada deploy.
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGIN', 'http://localhost:5173')))));
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isOriginAllowed = false;
foreach ($allowedOrigins as $pattern) {
    if ($pattern === $requestOrigin) {
        $isOriginAllowed = true;
        break;
    }
    if (str_contains($pattern, '*')) {
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
        if (preg_match($regex, $requestOrigin) === 1) {
            $isOriginAllowed = true;
            break;
        }
    }
}
$allowedOrigin = $isOriginAllowed ? $requestOrigin : ($allowedOrigins[0] ?? '');
header("Access-Control-Allow-Origin: {$allowedOrigin}");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(static function (Throwable $exception): void {
    error_log($exception->getMessage());
    $debug = Env::get('APP_DEBUG', 'false') === 'true';
    Response::error($debug ? $exception->getMessage() : 'Error interno del servidor.', 500);
});

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = preg_replace('#^/api/v1#', '', $path) ?: '/';
$path = '/' . trim($path, '/');

$routes = [
    ['POST', '#^/auth/login$#', [AuthController::class, 'login']],
    ['GET', '#^/auth/me$#', [AuthController::class, 'me']],
    ['POST', '#^/auth/logout$#', [AuthController::class, 'logout']],
    ['GET', '#^/articles/mine$#', [ArticleController::class, 'listMine']],
    ['GET', '#^/articles$#', [ArticleController::class, 'listPublished']],
    ['POST', '#^/articles$#', [ArticleController::class, 'create']],
    ['PUT', '#^/articles/([^/]+)$#', [ArticleController::class, 'update']],
    ['DELETE', '#^/articles/([^/]+)$#', [ArticleController::class, 'delete']],
    ['GET', '#^/site-config$#', [SiteConfigController::class, 'show']],
    ['PUT', '#^/site-config$#', [SiteConfigController::class, 'update']],
    ['POST', '#^/media$#', [MediaController::class, 'upload']],
];

foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if ($routeMethod !== $method || !preg_match($pattern, $path, $matches)) {
        continue;
    }

    [$class, $action] = $handler;
    $controller = new $class();
    $controller->$action(...array_slice($matches, 1));
    exit;
}

Response::error('Ruta no encontrada.', 404);
