<?php

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Request;
use App\Response;

final class SiteConfigController
{
    private const ALLOWED_FONTS = ['Inter', 'Georgia', 'Arial'];
    private const ALLOWED_WIDTHS = ['full', 'wide', 'half'];
    private const BUILTIN_PAGES = ['home', 'notebook', 'project'];
    private const SECTIONS = ['magazine', 'notebook', 'project'];
    private const MAX_CUSTOM_MODULES_PER_PAGE = 12;
    private const MAX_NAV_ITEMS = 8;
    private const MAX_CATEGORIES_PER_SECTION = 20;
    private const RESERVED_NAV_IDS = ['home', 'notebook', 'project', 'portfolio', 'editor', 'magazine'];

    private const DEFAULT_CATEGORIES = [
        'magazine' => ['TECNOLOGÍA DE MEZCLAS', 'SEGURIDAD EN OBRA', 'SALIDAS PROFESIONALES'],
        'notebook' => ['TALLER PRÁCTICO', 'SEGURIDAD Y SALUD', 'TOPOGRAFÍA'],
        'project' => ['AVANCE DE OBRA', 'MATERIALES', 'RESULTADO FINAL'],
    ];

    private const DEFAULT_CONFIG = [
        'version' => 1,
        'theme' => [
            'primary' => '#ef4444',
            'secondary' => '#facc15',
            'accent' => '#2563eb',
            'background' => '#ffffff',
            'borderWidth' => 3,
            'fontFamily' => 'Inter',
        ],
        'content' => [],
        'images' => [],
        'modules' => [],
        'categories' => self::DEFAULT_CATEGORIES,
        'navItems' => [],
        'customModules' => [
            'home' => [],
            'notebook' => [],
            'project' => [],
        ],
    ];

    public function show(): never
    {
        $pdo = Database::connection();
        $statement = $pdo->query('SELECT config FROM site_config WHERE id = 1 LIMIT 1');
        $row = $statement->fetch();

        $config = $row ? json_decode($row['config'], true) : self::DEFAULT_CONFIG;

        foreach (self::DEFAULT_CONFIG as $key => $defaultValue) {
            if (!array_key_exists($key, $config)) {
                $config[$key] = $defaultValue;
            }
        }

        Response::json(['config' => self::forObjectMaps($config)]);
    }

    public function update(): never
    {
        $pdo = Database::connection();
        $admin = Auth::requireAdmin($pdo);
        $payload = Request::jsonBody();

        $config = self::validate($payload['config'] ?? null);

        $statement = $pdo->prepare(
            'INSERT INTO site_config (id, config, updated_by)
             VALUES (1, :config, :updated_by)
             ON DUPLICATE KEY UPDATE config = :config2, updated_by = :updated_by2'
        );
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $statement->execute([
            'config' => $json,
            'updated_by' => $admin['id'],
            'config2' => $json,
            'updated_by2' => $admin['id'],
        ]);

        Response::json(['config' => self::forObjectMaps($config)]);
    }

    private static function forObjectMaps(array $config): array
    {
        foreach (['content', 'images', 'modules'] as $key) {
            if (empty($config[$key])) {
                $config[$key] = new \stdClass();
            }
        }

        return $config;
    }

    private static function validate(mixed $config): array
    {
        if (!is_array($config)) {
            Response::error('La configuración debe ser un objeto.', 422);
        }

        $theme = $config['theme'] ?? [];
        if (!is_array($theme)) {
            Response::error('El tema no es válido.', 422);
        }

        $cleanTheme = [];
        foreach (['primary', 'secondary', 'accent', 'background'] as $key) {
            $value = (string) ($theme[$key] ?? '');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                Response::error("El color '{$key}' debe ser un hexadecimal válido (#rrggbb).", 422);
            }
            $cleanTheme[$key] = $value;
        }

        $borderWidth = $theme['borderWidth'] ?? null;
        if (!is_numeric($borderWidth) || $borderWidth < 0 || $borderWidth > 12) {
            Response::error('El grosor de borde debe ser un número entre 0 y 12.', 422);
        }
        $cleanTheme['borderWidth'] = (int) $borderWidth;

        $fontFamily = $theme['fontFamily'] ?? null;
        if (!in_array($fontFamily, self::ALLOWED_FONTS, true)) {
            Response::error('La tipografía indicada no está permitida.', 422);
        }
        $cleanTheme['fontFamily'] = $fontFamily;

        $navItems = self::validateNavItems($config['navItems'] ?? []);
        $navItemIds = array_map(static fn (array $item) => $item['id'], $navItems);

        return [
            'version' => 1,
            'theme' => $cleanTheme,
            'content' => self::stringMap($config['content'] ?? [], 'content'),
            'images' => self::stringMap($config['images'] ?? [], 'images', true),
            'modules' => self::validateModules($config['modules'] ?? []),
            'categories' => self::validateCategories($config['categories'] ?? []),
            'navItems' => $navItems,
            'customModules' => self::validateCustomModules($config['customModules'] ?? [], $navItemIds),
        ];
    }

    private static function validateCategories(mixed $categories): array
    {
        if (!is_array($categories)) {
            Response::error('Las categorías deben ser un objeto.', 422);
        }

        $clean = [];
        foreach (self::SECTIONS as $section) {
            $list = $categories[$section] ?? self::DEFAULT_CATEGORIES[$section];

            if (!is_array($list) || count($list) > self::MAX_CATEGORIES_PER_SECTION) {
                Response::error("Las categorías de '{$section}' no son válidas o superan el máximo permitido.", 422);
            }

            $cleanList = [];
            foreach ($list as $category) {
                $value = trim((string) $category);
                if ($value === '' || mb_strlen($value) > 80) {
                    Response::error("Una categoría de '{$section}' no es válida.", 422);
                }
                if (!in_array($value, $cleanList, true)) {
                    $cleanList[] = $value;
                }
            }

            if (count($cleanList) === 0) {
                Response::error("La sección '{$section}' necesita al menos una categoría.", 422);
            }

            $clean[$section] = $cleanList;
        }

        return $clean;
    }

    private static function validateNavItems(mixed $navItems): array
    {
        if (!is_array($navItems) || count($navItems) > self::MAX_NAV_ITEMS) {
            Response::error('Los apartados personalizados no son válidos o superan el máximo permitido.', 422);
        }

        $clean = [];
        $seenIds = [];

        foreach ($navItems as $item) {
            if (!is_array($item)) {
                Response::error('Cada apartado personalizado debe ser un objeto.', 422);
            }

            $id = trim((string) ($item['id'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));

            if (!preg_match('/^[a-z0-9-]{2,40}$/', $id) || in_array($id, self::RESERVED_NAV_IDS, true)) {
                Response::error("El identificador de apartado '{$id}' no es válido.", 422);
            }

            if (in_array($id, $seenIds, true)) {
                Response::error("El identificador de apartado '{$id}' está duplicado.", 422);
            }

            if ($label === '' || mb_strlen($label) > 60) {
                Response::error("El nombre del apartado '{$id}' no es válido.", 422);
            }

            $seenIds[] = $id;
            $clean[] = ['id' => $id, 'label' => $label];
        }

        return $clean;
    }

    private static function stringMap(mixed $map, string $label, bool $requireUrl = false): array
    {
        if (!is_array($map)) {
            Response::error("El campo '{$label}' debe ser un objeto.", 422);
        }

        $clean = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || mb_strlen($key) > 160) {
                Response::error("Las claves de '{$label}' no son válidas.", 422);
            }

            $value = (string) $value;

            if ($requireUrl && $value !== '' && !preg_match('#^https?://#i', $value)) {
                Response::error("Las URLs de '{$label}' deben ser http(s) válidas.", 422);
            }

            if (mb_strlen($value) > 5000) {
                Response::error("El valor de '{$label}.{$key}' es demasiado largo.", 422);
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private static function validateModules(mixed $modules): array
    {
        if (!is_array($modules)) {
            Response::error('El campo de módulos debe ser un objeto.', 422);
        }

        $clean = [];
        foreach ($modules as $key => $settings) {
            if (!is_string($key) || !is_array($settings)) {
                Response::error('Cada módulo necesita una clave y ajustes válidos.', 422);
            }

            $order = $settings['order'] ?? null;
            $hidden = $settings['hidden'] ?? null;
            $width = $settings['width'] ?? null;

            if (!is_numeric($order)) {
                Response::error("El orden del módulo '{$key}' no es válido.", 422);
            }

            if (!is_bool($hidden)) {
                Response::error("La visibilidad del módulo '{$key}' no es válida.", 422);
            }

            if (!in_array($width, self::ALLOWED_WIDTHS, true)) {
                Response::error("El ancho del módulo '{$key}' no es válido.", 422);
            }

            $clean[$key] = [
                'order' => (int) $order,
                'hidden' => $hidden,
                'width' => $width,
            ];
        }

        return $clean;
    }

    private static function validateCustomModules(mixed $customModules, array $navItemIds): array
    {
        if (!is_array($customModules)) {
            Response::error('Los módulos personalizados deben ser un objeto.', 422);
        }

        $allowedPages = [...self::BUILTIN_PAGES, ...$navItemIds];

        foreach (array_keys($customModules) as $key) {
            if (!in_array($key, $allowedPages, true)) {
                Response::error("El apartado '{$key}' no existe o fue eliminado.", 422);
            }
        }

        $clean = [];
        foreach (['home', 'notebook', 'project'] as $page) {
            $clean[$page] = [];
        }

        foreach ($allowedPages as $page) {
            $items = $customModules[$page] ?? [];

            if (!is_array($items) || count($items) > self::MAX_CUSTOM_MODULES_PER_PAGE) {
                Response::error("Los módulos personalizados de '{$page}' no son válidos o superan el máximo permitido.", 422);
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    Response::error("Cada módulo personalizado de '{$page}' debe ser un objeto.", 422);
                }

                $id = trim((string) ($item['id'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                $body = trim((string) ($item['body'] ?? ''));
                $imageUrl = trim((string) ($item['imageUrl'] ?? ''));

                if ($id === '' || mb_strlen($title) > 160 || mb_strlen($body) > 5000) {
                    Response::error("Un módulo personalizado de '{$page}' tiene datos inválidos.", 422);
                }

                if ($imageUrl !== '' && !preg_match('#^https?://#i', $imageUrl)) {
                    Response::error("La imagen de un módulo personalizado de '{$page}' debe ser una URL http(s) válida.", 422);
                }

                $module = [
                    'id' => $id,
                    'title' => $title,
                    'body' => $body,
                ];

                if ($imageUrl !== '') {
                    $module['imageUrl'] = $imageUrl;
                }

                $clean[$page][] = $module;
            }
        }

        return $clean;
    }
}
