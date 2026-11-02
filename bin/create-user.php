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

$username = prompt('Usuario: ');
$password = prompt('Contraseña: ');

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usuario y contraseña son obligatorios.\n");
    exit(1);
}

$pdo = Database::connection();

$statement = $pdo->prepare(
    "INSERT INTO users (username, name, password_hash, role)
     VALUES (:username, :name, :password_hash, 'admin')"
);

$statement->execute([
    'username' => $username,
    'name' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

echo "Usuario creado con id {$pdo->lastInsertId()}.\n";
