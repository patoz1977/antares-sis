<?php

declare(strict_types=1);

namespace Core\Foundation;

use Core\Http\Request;
use Core\Routing\Router;

class Kernel
{
    private Request $request;
    private Router $router;

    public function __construct(Request $request, Router $router)
    {
        $this->request = $request;
        $this->router = $router;
    }

    public function handle(): void
    {
        $this->router->dispatch(
            $this->request->method(),
            $this->request->uri()
        );
    }
}
