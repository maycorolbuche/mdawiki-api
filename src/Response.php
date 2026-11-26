<?php
declare(strict_types=1);

namespace MDAWiki;

final class Response
{
    public function __construct(public readonly int $status, public readonly array $body = [], public readonly array $headers = [], public readonly ?string $rawBody = null)
    {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->status !== 204) {
            echo $this->rawBody ?? json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
