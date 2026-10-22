<?php

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Request;
use App\Response;
use PDO;

final class ArticleController
{
    private const SECTIONS = ['magazine', 'notebook', 'project'];

    private const DEFAULT_CATEGORIES = [
        'magazine' => ['TECNOLOGÍA DE MEZCLAS', 'SEGURIDAD EN OBRA', 'SALIDAS PROFESIONALES'],
        'notebook' => ['TALLER PRÁCTICO', 'SEGURIDAD Y SALUD', 'TOPOGRAFÍA'],
        'project' => ['AVANCE DE OBRA', 'MATERIALES', 'RESULTADO FINAL'],
    ];

    private const BLOCK_TYPES = ['text', 'image', 'heading1', 'heading2', 'html'];

    public function listPublished(): never
    {
        $pdo = Database::connection();
        $statement = $pdo->query(
            "SELECT a.*, u.name AS author_name
             FROM articles a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.status = 'published'
             ORDER BY a.published_at DESC"
        );

        Response::json(['articles' => array_map([self::class, 'format'], $statement->fetchAll())]);
    }

    public function listMine(): never
    {
        $pdo = Database::connection();
        $user = Auth::requireUser($pdo);

        $statement = $pdo->prepare(
            "SELECT a.*, u.name AS author_name
             FROM articles a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.user_id = :user_id
             ORDER BY a.updated_at DESC"
        );
        $statement->execute(['user_id' => $user['id']]);

        Response::json(['articles' => array_map([self::class, 'format'], $statement->fetchAll())]);
    }

    public function create(): never
    {
        $pdo = Database::connection();
        $user = Auth::requireUser($pdo);
        $fields = self::validate(Request::jsonBody(), $pdo);

        $statement = $pdo->prepare(
            'INSERT INTO articles (user_id, section, title, category, excerpt, hero_image, blocks, status, published_at)
             VALUES (:user_id, :section, :title, :category, :excerpt, :hero_image, :blocks, "published", NOW())'
        );
        $statement->execute([
            'user_id' => $user['id'],
            'section' => $fields['section'],
            'title' => $fields['title'],
            'category' => $fields['category'],
            'excerpt' => $fields['excerpt'],
            'hero_image' => $fields['heroImage'],
            'blocks' => json_encode($fields['blocks'], JSON_UNESCAPED_UNICODE),
        ]);

        Response::json(['article' => self::find($pdo, (string) $pdo->lastInsertId())], 201);
    }

    public function update(string $id): never
    {
        $pdo = Database::connection();
        $user = Auth::requireUser($pdo);
        $fields = self::validate(Request::jsonBody(), $pdo);
        $existing = self::ownedArticle($pdo, $id, $user);

        $statement = $pdo->prepare(
            'UPDATE articles
             SET section = :section, title = :title, category = :category,
                 excerpt = :excerpt, hero_image = :hero_image, blocks = :blocks
             WHERE id = :id'
        );
        $statement->execute([
            'section' => $fields['section'],
            'title' => $fields['title'],
            'category' => $fields['category'],
            'excerpt' => $fields['excerpt'],
            'hero_image' => $fields['heroImage'],
            'blocks' => json_encode($fields['blocks'], JSON_UNESCAPED_UNICODE),
            'id' => $existing['id'],
        ]);

        Response::json(['article' => self::find($pdo, (string) $existing['id'])]);
    }

    public function delete(string $id): never
    {
        $pdo = Database::connection();
        $user = Auth::requireUser($pdo);
        $existing = self::ownedArticle($pdo, $id, $user);

        $statement = $pdo->prepare('DELETE FROM articles WHERE id = :id');
        $statement->execute(['id' => $existing['id']]);

        Response::json(null);
    }

    private static function ownedArticle(PDO $pdo, string $id, array $user): array
    {
        $statement = $pdo->prepare('SELECT * FROM articles WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $article = $statement->fetch();

        if (!$article) {
            Response::error('Publicación no encontrada.', 404);
        }

        if ((string) $article['user_id'] !== (string) $user['id'] && $user['role'] !== 'admin') {
            Response::error('No autorizado.', 403);
        }

        return $article;
    }

    private static function sectionCategories(PDO $pdo, mixed $section): array
    {
        if (!in_array($section, self::SECTIONS, true)) {
            return [];
        }

        $statement = $pdo->query('SELECT config FROM site_config WHERE id = 1 LIMIT 1');
        $row = $statement->fetch();
        $config = $row ? json_decode($row['config'], true) : null;

        $categories = $config['categories'][$section] ?? null;

        return is_array($categories) && count($categories) > 0
            ? $categories
            : self::DEFAULT_CATEGORIES[$section];
    }

    private static function find(PDO $pdo, string $id): array
    {
        $statement = $pdo->prepare(
            "SELECT a.*, u.name AS author_name
             FROM articles a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $id]);

        return self::format($statement->fetch());
    }

    private static function validate(array $payload, PDO $pdo): array
    {
        $section = $payload['section'] ?? null;
        $title = trim((string) ($payload['title'] ?? ''));
        $category = $payload['category'] ?? null;
        $excerpt = trim((string) ($payload['excerpt'] ?? ''));
        $heroImage = trim((string) ($payload['heroImage'] ?? ''));
        $blocks = $payload['blocks'] ?? null;

        if (!in_array($section, self::SECTIONS, true)) {
            Response::error('La sección indicada no es válida.', 422);
        }

        if (!in_array($category, self::sectionCategories($pdo, $section), true)) {
            Response::error('La categoría no es válida para esta sección.', 422);
        }

        if ($title === '' || mb_strlen($title) > 180) {
            Response::error('El título es obligatorio y debe tener como máximo 180 caracteres.', 422);
        }

        if ($excerpt === '' || mb_strlen($excerpt) > 500) {
            Response::error('El resumen es obligatorio y debe tener como máximo 500 caracteres.', 422);
        }

        if ($heroImage !== '' && !preg_match('#^https?://#i', $heroImage)) {
            Response::error('La imagen de portada debe ser una URL http(s) válida.', 422);
        }

        if (!is_array($blocks) || count($blocks) === 0) {
            Response::error('La publicación necesita al menos un bloque de contenido.', 422);
        }

        $cleanBlocks = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                Response::error('Cada bloque debe ser un objeto válido.', 422);
            }

            $blockId = trim((string) ($block['id'] ?? ''));
            $blockType = $block['type'] ?? null;
            $blockContent = trim((string) ($block['content'] ?? ''));

            if ($blockId === '' || !in_array($blockType, self::BLOCK_TYPES, true) || $blockContent === '') {
                Response::error('Cada bloque necesita id, tipo válido y contenido.', 422);
            }

            $cleanBlocks[] = [
                'id' => $blockId,
                'type' => $blockType,
                'content' => $blockContent,
            ];
        }

        return [
            'section' => $section,
            'title' => $title,
            'category' => $category,
            'excerpt' => $excerpt,
            'heroImage' => $heroImage,
            'blocks' => $cleanBlocks,
        ];
    }

    private static function format(array $article): array
    {
        $blocks = json_decode($article['blocks'], true) ?: [];
        $wordCount = 0;

        foreach ($blocks as $block) {
            $wordCount += str_word_count((string) ($block['content'] ?? ''));
        }

        $minutes = max(1, (int) ceil($wordCount / 200));

        return [
            'id' => (string) $article['id'],
            'section' => $article['section'],
            'tag' => $article['category'],
            'category' => $article['category'],
            'title' => $article['title'],
            'excerpt' => $article['excerpt'],
            'author' => $article['author_name'],
            'readTime' => "{$minutes} MIN LECTURA",
            'heroImage' => $article['hero_image'] ?? '',
            'blocks' => $blocks,
            'publishedAt' => self::toIso($article['published_at']),
        ];
    }

    private static function toIso(?string $datetime): ?string
    {
        if (!$datetime) {
            return null;
        }

        return (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
