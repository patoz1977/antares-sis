<?php

declare(strict_types=1);

namespace Core\View;

use RuntimeException;

class View
{
    public static function render(string $view, array $data = []): string
    {
        $basePath = dirname(__DIR__, 2);
        $viewPath = $basePath . '/resources/views/' . str_replace('.', '/', $view) . '.php';

        if (!is_file($viewPath)) {
            throw new RuntimeException(sprintf('View "%s" not found.', $view));
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $viewPath;

        return (string) ob_get_clean();
    }
}
