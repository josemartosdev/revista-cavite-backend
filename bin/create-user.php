<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Env;

Env::load(__DIR__ . '/../.env');

function prompt(string $label): string
{
    echo $label;

    return trim((string) fgets(STDIN));
}

$name = prompt('Nombre: ');
$email = prompt('Correo: ');
$password = prompt('Contraseña: ');
$role = prompt('Rol (admin/editor) [admin]: ');
$role = $role !== '' ? $role : 'admin';

if ($name === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Nombre, correo y contraseña son obligatorios.\n");
    exit(1);
}

if (!in_array($role, ['admin', 'editor'], true)) {
    fwrite(STDERR, "Rol no válido. Usa 'admin' o 'editor'.\n");
    exit(1);
}

$pdo = Database::connection();

$statement = $pdo->prepare(
    'INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)'
);

$statement->execute([
    'name' => $name,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role' => $role,
]);

echo "Usuario creado con id {$pdo->lastInsertId()}.\n";
