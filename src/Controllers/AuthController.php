<?php

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Request;
use App\Response;

final class AuthController
{
    public function login(): never
    {
        $pdo = Database::connection();
        $payload = Request::jsonBody();

        $username = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::error('Introduce usuario y contraseña.', 422);
        }

        $statement = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('Credenciales no válidas.', 401);
        }

        $token = Auth::createToken($pdo, $user['id']);

        Response::json([
            'token' => $token,
            'user' => self::formatUser($user),
        ]);
    }

    public function me(): never
    {
        $pdo = Database::connection();
        $user = Auth::requireUser($pdo);

        Response::json(['user' => self::formatUser($user)]);
    }

    public function logout(): never
    {
        $pdo = Database::connection();
        Auth::requireUser($pdo);
        $token = Auth::bearerToken();

        if ($token) {
            Auth::revokeToken($pdo, $token);
        }

        Response::json(null);
    }

    private static function formatUser(array $user): array
    {
        return [
            'id' => (string) $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }
}
