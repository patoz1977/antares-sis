<?php

declare(strict_types=1);

namespace Core\Http;

class Response
{
    private int $statusCode = 200;
    private string $content = '';

    public function status(int $code): self
    {
        $this->statusCode = $code;

        return $this;
    }

    public function content(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        echo $this->content;
    }
}
