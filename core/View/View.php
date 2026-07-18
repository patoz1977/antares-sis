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

        $content = (string) ob_get_clean();

        $title = $data['title'] ?? 'School Information System';

        $layoutPath = $basePath . '/resources/views/layouts/app.php';

        if (is_file($layoutPath)) {
            ob_start();
            include $layoutPath;

            return (string) ob_get_clean();
        }

        return $content;
    }
}
