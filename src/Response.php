<?php

namespace Sudare;

/**
 * What Sudare decided for a request: let it through to Kirby (pass), or
 * answer it here (status, headers, body or file). Kirby-free value object,
 * immutable. Written by the architect (SPEC.md §3.3), verified in
 * tests/RequestTest.php.
 *
 * Header names keep the spelling they were given; lookups are
 * case-insensitive and a header set twice keeps the last value.
 *
 * A "pass" response may still carry headers: Sudare::guard() sends them
 * before Kirby runs (X-Robots-Tag, Cache-Control, Vary on a protected site)
 * and seals them again when PHP sends the response (Sudare::seal()), so what
 * Kirby or a plugin sets in between cannot replace them.
 *
 * PHP 8.2: `(new self(...))->withHeaders()` needs the parentheses; the
 * bare `new self(...)->` form is PHP 8.4 syntax.
 */
final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        private int $status,
        private array $headers,
        private string $body,
        private ?string $file,
        private bool $pass,
        private ?array $range = null,
    ) {
    }

    /** Continue to Kirby, sending these headers first. */
    public static function pass(array $headers = []): self
    {
        return (new self(200, [], '', null, true))->withHeaders($headers);
    }

    /** text/plain; charset=utf-8 */
    public static function text(int $status, string $body, array $headers = []): self
    {
        return (new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body, null, false))->withHeaders($headers);
    }

    /** text/html; charset=utf-8 */
    public static function html(int $status, string $body, array $headers = []): self
    {
        return (new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body, null, false))->withHeaders($headers);
    }

    /** Status only (OPTIONS, HEAD on a page that has no body here). */
    public static function empty(int $status, array $headers = []): self
    {
        return (new self($status, [], '', null, false))->withHeaders($headers);
    }

    /** Streams a file from disk; Content-Length is filled in from the file. */
    public static function file(string $path, string $contentType, array $headers = []): self
    {
        $size = @filesize($path);
        $base = [
            'Content-Type'   => $contentType,
            'Content-Length' => (string) ($size === false ? 0 : $size),
        ];
        return (new self(200, $base, '', $path, false))->withHeaders($headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,string> in insertion order */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Case-insensitive lookup. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Absolute path of the file to stream, or null. */
    public function filePath(): ?string
    {
        return $this->file;
    }

    /** @return array{0: int, 1: int}|null first and last byte of the file to stream (inclusive), or null for all of it */
    public function range(): ?array
    {
        return $this->range;
    }

    /** true = continue to Kirby after sending the headers. */
    public function passes(): bool
    {
        return $this->pass;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        foreach ($clone->headers as $key => $existing) {
            if (strcasecmp($key, $name) === 0) {
                unset($clone->headers[$key]);
            }
        }
        $clone->headers[$name] = $value;
        return $clone;
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        $response = $this;
        foreach ($headers as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }
        return $response;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    /**
     * A file response cut to bytes $first–$last (inclusive): 206 with
     * Content-Range and the length of the part. The caller has checked the
     * range against the file size (Media::range()).
     */
    public function withRange(int $first, int $last, int $size): self
    {
        $clone = $this->withStatus(206)->withHeaders([
            'Content-Range'  => 'bytes ' . $first . '-' . $last . '/' . $size,
            'Content-Length' => (string) ($last - $first + 1),
        ]);
        $clone->range = [$first, $last];
        return $clone;
    }

    /** For HEAD/OPTIONS: keeps status and headers (Content-Length included), drops body and file. */
    public function withoutBody(): self
    {
        $clone = clone $this;
        $clone->body = '';
        $clone->file = null;
        $clone->range = null;
        return $clone;
    }

    /** @return array{status: int, headers: array<string,string>, body: string, file: ?string, range: ?array, pass: bool} */
    public function toArray(): array
    {
        return [
            'status'  => $this->status,
            'headers' => $this->headers,
            'body'    => $this->body,
            'file'    => $this->file,
            'range'   => $this->range,
            'pass'    => $this->pass,
        ];
    }
}
