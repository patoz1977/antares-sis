<?php

declare(strict_types=1);

namespace Core\Container;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

final class Container implements ContainerInterface
{
    private array $bindings = [];

    private array $singletons = [];

    private array $instances = [];

    public function bind(string $id, callable|string $concrete): void
    {
        $this->bindings[$id] = $concrete;
    }

    public function singleton(string $id, callable|string $concrete): void
    {
        $this->singletons[$id] = $concrete;
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function make(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->singletons[$id])) {
            $object = $this->resolve($this->singletons[$id], $id);
            $this->instances[$id] = $object;

            return $object;
        }

        if (isset($this->bindings[$id])) {
            return $this->resolve($this->bindings[$id], $id);
        }

        if (class_exists($id)) {
            return $this->autowire($id);
        }

        throw new NotFoundException(sprintf('Service not found: %s', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->singletons[$id])
            || isset($this->bindings[$id])
            || class_exists($id);
    }

    private function resolve(callable|string $concrete, ?string $id = null): object
    {
        if (is_string($concrete)) {
            if ($id !== null && $concrete === $id) {
                return $this->autowire($concrete);
            }

            return $this->make($concrete);
        }

        $result = $concrete($this);

        if (!is_object($result)) {
            throw new ContainerException('The resolver must return an object.');
        }

        return $result;
    }

    private function autowire(string $id): object
    {
        try {
            $reflection = new ReflectionClass($id);
        } catch (ReflectionException $exception) {
            throw new ContainerException(sprintf('Unable to reflect class %s.', $id), previous: $exception);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $id();
        }

        $parameters = $constructor->getParameters();
        if ($parameters === []) {
            return new $id();
        }

        $dependencies = [];

        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                throw new ContainerException(sprintf('Cannot autowire variadic parameter $%s of class %s.', $parameter->getName(), $id));
            }

            $type = $parameter->getType();
            if ($type === null) {
                throw new ContainerException(sprintf('Cannot autowire untyped parameter $%s of class %s.', $parameter->getName(), $id));
            }

            if (! $type instanceof ReflectionNamedType) {
                throw new ContainerException(sprintf('Cannot autowire parameter $%s of class %s.', $parameter->getName(), $id));
            }

            if ($type->isBuiltin()) {
                throw new ContainerException(sprintf('Cannot autowire built-in parameter $%s of class %s.', $parameter->getName(), $id));
            }

            $dependencyClass = $type->getName();
            $dependencies[] = $this->make($dependencyClass);
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}
