<?php

namespace Sudare;

/**
 * The parts of the HTTP request Sudare looks at, read once from $_SERVER.
 * Kirby-free value object. Written by the architect (SPEC.md §3.2),
 * verified in tests/RequestTest.php.
 *
 * Credentials come from PHP_AUTH_USER/PHP_AUTH_PW, or — on FastCGI servers
 * that do not fill those — from the Authorization header the site's
 * .htaccess passes through as HTTP_AUTHORIZATION (or
 * REDIRECT_HTTP_AUTHORIZATION after a rewrite).
 *
 * path() is normalised: query and fragment removed, percent-decoded ONCE,
 * dot segments resolved, duplicate slashes collapsed, no trailing slash
 * (except "/"). Exemptions and media lookups use this path, so
 * "/.well-known/acme-challenge/../../panel" is "/panel" and not exempt.
 */
final class Request
{
    public const TOKEN_HEADER = 'X-Sudare-Token';

    /** The methods Kirby accepts in X-HTTP-Method-Override (Kirby\Http\Request::detectRequestMethod()). */
    public const KIRBY_METHODS = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'];

    /** The $_SERVER key of TOKEN_HEADER. */
    public const TOKEN_SERVER_KEY = 'HTTP_X_SUDARE_TOKEN';

    private function __construct(
        private string $method,
        private string $path,
        private string $rawPath,
        private ?string $user,
        private ?string $password,
        private ?string $token,
        private ?string $range = null,
        private array $conditions = [],
        private string $routed = 'GET',
        private string $sent = 'GET',
    ) {
    }

    public static function fromServer(array $server): self
    {
        $method = strtoupper(trim((string) ($server['REQUEST_METHOD'] ?? 'GET')));
        if ($method === '' || preg_match('/^[A-Z]+$/', $method) !== 1) {
            $method = 'GET';
        }

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $rawPath = explode('#', explode('?', $uri, 2)[0], 2)[0];
        if ($rawPath === '' || $rawPath[0] !== '/') {
            $rawPath = '/' . $rawPath;
        }

        [$user, $password] = self::credentialsFrom($server);

        $token = $server[self::TOKEN_SERVER_KEY] ?? null;
        $token = is_string($token) && $token !== '' ? $token : null;

        // Ranges are defined for GET only (RFC 9110 §14.2).
        $range = $server['HTTP_RANGE'] ?? null;
        $range = $method === 'GET' && is_string($range) && $range !== '' ? $range : null;

        // The conditional headers Media answers (304, If-Range).
        $conditions = [];
        foreach (['If-None-Match' => 'HTTP_IF_NONE_MATCH', 'If-Modified-Since' => 'HTTP_IF_MODIFIED_SINCE', 'If-Range' => 'HTTP_IF_RANGE'] as $name => $key) {
            if (is_string($server[$key] ?? null) && trim($server[$key]) !== '') {
                $conditions[$name] = trim($server[$key]);
            }
        }

        return new self($method, self::normalisePath($rawPath), $rawPath, $user, $password, $token, $range, $conditions, self::kirbyMethod($server), (string) ($server['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * The method Kirby routes, exactly as Kirby\Http\Request::detectRequestMethod()
     * finds it: X-HTTP-Method-Override when it is one of KIRBY_METHODS after
     * strtoupper() — no trim, " POST " is no override there — else
     * REQUEST_METHOD, and GET for anything Kirby does not know.
     */
    public static function kirbyMethod(array $server): string
    {
        $override = $server['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '';
        $override = is_string($override) ? strtoupper($override) : '';
        if (in_array($override, self::KIRBY_METHODS, true)) {
            return $override;
        }
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($method) ? strtoupper($method) : 'GET';
        return in_array($method, self::KIRBY_METHODS, true) ? $method : 'GET';
    }

    /**
     * Decodes once and resolves "." and ".." segments. Always starts with
     * "/", never ends with "/" unless it is the root. NUL bytes are removed.
     */
    public static function normalisePath(string $path): string
    {
        $path = str_replace("\0", '', rawurldecode($path));
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function credentialsFrom(array $server): array
    {
        $user = $server['PHP_AUTH_USER'] ?? null;
        if (is_string($user)) {
            $password = $server['PHP_AUTH_PW'] ?? '';
            return [$user, is_string($password) ? $password : ''];
        }
        $raw = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($raw) || stripos($raw, 'basic ') !== 0) {
            return [null, null];
        }
        $decoded = base64_decode(trim(substr($raw, 6)), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return [null, null];
        }
        [$user, $password] = explode(':', $decoded, 2);
        return [$user, $password];
    }

    public function method(): string
    {
        return $this->method;
    }

    /**
     * REQUEST_METHOD exactly as sent, not trimmed or upper-cased. Only an
     * exact "HEAD" makes Apache and PHP drop the body; Kirby routes "head"
     * as HEAD and renders the page with its body.
     */
    public function sentMethod(): string
    {
        return $this->sent;
    }

    /** The method Kirby will route (kirbyMethod()); method-scoped exemptions compare this one. */
    public function routedMethod(): string
    {
        return $this->routed;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The path as sent (without query), undecoded. For logging-free diagnostics only. */
    public function rawPath(): string
    {
        return $this->rawPath;
    }

    /**
     * Is the path as sent already in normal form? Exemptions are decided on
     * path(), but Kirby routes the raw REQUEST_URI: "/panel/../api/hook"
     * would be exempt here and the Panel there, "/%61pi/hook" would be exempt
     * here and Kirby's error page there. Only a path that needs neither
     * decoding nor resolving may be exempt. A trailing slash is fine (Kirby
     * ignores it); any percent sign, dot segment or empty segment is not.
     */
    public function isCanonical(): bool
    {
        if (str_contains($this->rawPath, '%')) {
            return false;
        }
        $decoded = rawurldecode($this->rawPath);
        if ($decoded !== '/' && str_ends_with($decoded, '/')) {
            $decoded = substr($decoded, 0, -1);
        }
        return self::normalisePath($this->rawPath) === $decoded;
    }

    /** @return array{user: string, password: string}|null */
    public function credentials(): ?array
    {
        if ($this->user === null) {
            return null;
        }
        return ['user' => $this->user, 'password' => (string) $this->password];
    }

    /** The Range header of a GET, unparsed (Media::range() reads it), or null. */
    public function range(): ?string
    {
        return $this->range;
    }

    /** If-None-Match, If-Modified-Since or If-Range as sent (trimmed), or null. */
    public function condition(string $name): ?string
    {
        return $this->conditions[$name] ?? null;
    }

    /** The plain machine token from the X-Sudare-Token header, or null. */
    public function token(): ?string
    {
        return $this->token;
    }

    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    public function isOptions(): bool
    {
        return $this->method === 'OPTIONS';
    }

    /** GET or HEAD: the only methods a file answers. */
    public function isRead(): bool
    {
        return $this->method === 'GET' || $this->method === 'HEAD';
    }

    /** HEAD and OPTIONS responses carry headers only. */
    public function wantsBody(): bool
    {
        return !$this->isHead() && !$this->isOptions();
    }
}
