<?php

declare(strict_types=1);

namespace Core\View;

use Closure;
use RuntimeException;

class View
{
    private static ?Closure $sharedDataResolver = null;

    public static function setSharedDataResolver(?Closure $resolver): void
    {
        self::$sharedDataResolver = $resolver;
    }

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
        $layoutData = [];
        if (self::$sharedDataResolver !== null) {
            $layoutData = (self::$sharedDataResolver)();
            if (!is_array($layoutData)) {
                throw new RuntimeException('The shared View data resolver must return an array.');
            }
        }

        $layoutPath = $basePath . '/resources/views/layouts/app.php';

        if (is_file($layoutPath)) {
            ob_start();
            include $layoutPath;

            return (string) ob_get_clean();
        }

        return $content;
    }
}
