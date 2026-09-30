<?php

namespace Tests\Integration\Support;

class HttpResponse
{
    public function __construct(
        private readonly string $body,
        private readonly int $statusCode,
        private readonly array $headers,
        private readonly string $stderr = '',
    ) {}

    public function body(): string
    {
        return $this->body;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function bodyLength(): int
    {
        return strlen($this->body);
    }

    public function contains(string $needle): bool
    {
        return $needle !== '' && str_contains($this->body, $needle);
    }

    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * First value of a response header (case-insensitive name), or null when it was not sent.
     */
    public function header(string $name): ?string
    {
        $prefix = mb_strtolower($name) . ':';

        foreach ($this->headers as $header) {
            if (mb_strpos(mb_strtolower($header), $prefix) === 0) {
                return trim(mb_substr($header, mb_strlen($prefix)));
            }
        }

        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    public function redirectUrl(): string
    {
        foreach ($this->headers as $header) {
            if (mb_stripos($header, 'Location:') === 0) {
                return trim(mb_substr($header, 9));
            }
        }

        return '';
    }
}
