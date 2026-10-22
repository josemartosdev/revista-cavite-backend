<?php

namespace App;

final class Request
{
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);

        if (!is_array($payload)) {
            Response::error('El cuerpo de la solicitud debe ser JSON válido.', 400);
        }

        return $payload;
    }
}
