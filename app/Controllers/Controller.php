<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\View\View;

abstract class Controller
{
    protected function view(string $view, array $data = []): string
    {
        return View::render($view, $data);
    }
}

class HomeController extends Controller
{
    public function index(): string
    {
        return $this->view(
            'pages.home',
            [
                'title' => 'School Information System',
            ]
        );
    }
}
