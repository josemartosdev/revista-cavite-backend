<?php

namespace App;

use PDO;

final class Auth
{
    public static function tokenTtlHours(): int
    {
        return (int) Env::get('TOKEN_TTL_HOURS', '720');
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function createToken(PDO $pdo, string $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::tokenTtlHours() . ' hours')
            ->format('Y-m-d H:i:s');

        $statement = $pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public static function revokeToken(PDO $pdo, string $token): void
    {
        $hash = hash('sha256', $token);
        $statement = $pdo->prepare('DELETE FROM auth_tokens WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $hash]);
    }

    public static function userFromToken(PDO $pdo, ?string $token): ?array
    {
        if (!$token) {
            return null;
        }

        $hash = hash('sha256', $token);

        $statement = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.role
             FROM auth_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash AND t.expires_at > NOW()
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $hash]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public static function requireUser(PDO $pdo): array
    {
        $user = self::userFromToken($pdo, self::bearerToken());

        if (!$user) {
            Response::error('No autenticado.', 401);
        }

        return $user;
    }

    public static function requireAdmin(PDO $pdo): array
    {
        $user = self::requireUser($pdo);

        if ($user['role'] !== 'admin') {
            Response::error('No autorizado.', 403);
        }

        return $user;
    }
}
