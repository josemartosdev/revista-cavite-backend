<?php

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Env;
use App\Response;

final class MediaController
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const MAX_SIZE_BYTES = 8 * 1024 * 1024;

    public function upload(): never
    {
        $pdo = Database::connection();
        $user = Auth::requireUser($pdo);

        $file = $_FILES['file'] ?? null;

        if (!$file || !is_array($file)) {
            Response::error('No se ha recibido ningún archivo.', 422);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Error al subir el archivo (código ' . $file['error'] . ').', 422);
        }

        if ($file['size'] <= 0 || $file['size'] > self::MAX_SIZE_BYTES) {
            Response::error('El archivo debe pesar entre 1 byte y 8 MB.', 422);
        }

        $mimeType = (string) mime_content_type($file['tmp_name']);
        $extension = self::ALLOWED_MIME_TYPES[$mimeType] ?? null;

        if ($extension === null) {
            Response::error('Formato de imagen no permitido. Usa JPG, PNG, WEBP o GIF.', 422);
        }

        $uploadsDir = __DIR__ . '/../../public/uploads';
        if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
            Response::error('No se pudo preparar el almacenamiento de archivos.', 500);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $uploadsDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            Response::error('No se pudo guardar el archivo subido.', 500);
        }

        $originalName = mb_substr((string) ($file['name'] ?? $filename), 0, 255);

        $statement = $pdo->prepare(
            'INSERT INTO media (filename, original_name, mime_type, size_bytes, uploaded_by)
             VALUES (:filename, :original_name, :mime_type, :size_bytes, :uploaded_by)'
        );
        $statement->execute([
            'filename' => $filename,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => $file['size'],
            'uploaded_by' => $user['id'],
        ]);

        $baseUrl = rtrim(Env::get('APP_URL', 'http://localhost:8000'), '/');

        Response::json([
            'media' => [
                'id' => (string) $pdo->lastInsertId(),
                'url' => "{$baseUrl}/uploads/{$filename}",
                'mimeType' => $mimeType,
                'size' => (int) $file['size'],
            ],
        ], 201);
    }
}
