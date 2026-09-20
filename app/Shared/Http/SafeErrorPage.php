<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Core\View\View;

final class SafeErrorPage
{
    public static function render(int $status, string $message, string $returnUrl = '/', string $returnLabel = 'Volver al inicio'): string
    {
        return View::render('components.safe-error-page', [
            'title' => match ($status) {
                403 => 'Acceso no permitido',
                404 => 'Página no encontrada',
                default => 'No se pudo completar la solicitud',
            },
            'errorStatus' => $status,
            'errorMessage' => $message,
            'returnUrl' => $returnUrl,
            'returnLabel' => $returnLabel,
        ]);
    }
}
