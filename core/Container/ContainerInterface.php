<?php

declare(strict_types=1);

namespace Core\Container;

interface ContainerInterface
{
    public function bind(string $id, callable|string $concrete): void;

    public function singleton(string $id, callable|string $concrete): void;

    public function instance(string $id, object $instance): void;

    public function make(string $id): object;

    public function has(string $id): bool;
}
