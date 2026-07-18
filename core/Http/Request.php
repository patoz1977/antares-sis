<?php

declare(strict_types=1);

namespace Core\Http;

class Request
{
    private string $method;
    private string $uri;
    private array $query;
    private array $input;
    private array $server;

    public function __construct()
    {
        $this->server = $_SERVER;
        $this->method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        $this->query = $_GET;
        $this->input = $this->method === 'GET' ? $_GET : $_POST;

        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $this->uri = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function query(): array
    {
        return $this->query;
    }

    public function input(): array
    {
        return $this->input;
    }

    public function server(): array
    {
        return $this->server;
    }
}
