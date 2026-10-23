# Sudare — build specification

Sudare (簾, the bamboo blind: those inside see out, those outside cannot see
in; it is rolled up on launch day) is **preview protection for Kirby sites
before launch**: HTTP Basic Auth in front of Kirby, `noindex` on every
response, files under `public/media/` and `public/assets/` served through PHP
so they are behind the password too, a machine token for CI, a hash CLI and a
deploy gate CLI.

Package `sayamaapps/kirby-sudare`, Composer type **`library`** (not
`kirby-plugin`, see §12), namespace `Sudare`, option prefix `sudare.`,
environment prefix `SUDARE_`, directory: this repository.

It replaces the per-site preview guard that Kirby sites carried before 1.0.0
(`site/config/vorschauschutz.php` and two helper scripts, copied into each site;
the copies had drifted apart, one of them with a themed 401 page).

This document is the contract. Section numbers are referenced from code
comments and task prompts.

---

## 0. Background

Before 1.0.0 the preview guard lived as a copy in each Kirby site: `site/config/vorschauschutz.php`, plus two helper scripts for the hash and the deploy gate. The copies drifted apart. This package replaces them; the migration map is in the CHANGELOG (1.0.0).

---

## 1. Ground rules for every builder

- **PHP 8.2 compatible**: no typed class constants, no `new X()->method()`
  chaining without parentheses (8.4), no `#[\Override]`, no 8.3+ functions.
  Run with `ASDF_PHP_VERSION=8.5.3 php …` (asdf otherwise says "No version is
  set"). Composer: `php ~/Applications/PHP_Composer/composer.phar`.
- Code, identifiers, comments, README in **English**. German only in
  `translations/de.php`.
- **Kirby-free everywhere.** Sudare runs before Kirby boots. No `kirby()`,
  no `option()`, no `App` in `src/` or `bootstrap.php`. Tests do not use Kirby
  either, with one exception: `tests/KirbyHttpTest.php` (§10.9) boots a real
  Kirby (a dev dependency) to prove what the seal (§6.1) exists for.
- Every user-facing string comes from `I18n::t()` (keys in §5). Need a key
  that does not exist? Do NOT add it — write it into `NOTES-<task>.md` and use
  the closest existing key.
- **Secrets never reach output or logs**: no hash, no token, no password in a
  message, exception, header or test output. The 503 body is a fixed sentence;
  the reason stays in `Config::reason()` for the CLI.
- **Fail closed**: whenever a configuration source exists and is not valid,
  every request is 503 — never "no config, so let it through".
- Only edit files your task owns (§2). Need a change in a shared file (a bug in
  Config.php, a missing translation, a helper)? Write it into `NOTES-<task>.md`
  in the package root: file, problem, proposed change. Do not work around it
  with a copy of the logic.
- Tests: standalone PHP scripts `tests/<Name>Test.php`, exit code 0 = pass,
  no PHPUnit. Use `tests/helpers.php` (§10.0). Every test file starts with
  `require_once __DIR__ . '/helpers.php'; require_once __DIR__ . '/../bootstrap.php';`
  — bootstrap loads every src file once; never `require` a src file directly.
- Do not push, do not create GitHub repos, do not touch any other repository.
  Never delete files outside the package directory. Do not run `git commit`
  (the architect commits).
- Throwaway probe scripts go to a scratch directory outside the repository,
  never into a repo.

---

## 2. File tree and owners

`architect` = written before the build, shared, **builders never edit**.
`architect → <task>` = written by the architect as working code; from now on
owned by that task, which verifies it with tests and may fix it.
`stub → <task>` = a class skeleton whose methods throw `LogicException`; the
task replaces the file.

```
kirby-sudare/
├── composer.json                  architect   (type library, autoload.files bootstrap.php, bin)
├── composer.lock, vendor/         generated (composer install), not committed
├── .gitignore                     architect
├── LICENSE                        architect   (MIT)
├── .github/workflows/ci.yml       architect   (PHP 8.2–8.4, fork guard, CLI smoke steps)
├── SPEC.md                        architect
├── bootstrap.php                  architect   (entry point = the plugin family's index.php, §3.1)
├── src/
│   ├── I18n.php                   architect   (§3.2)
│   ├── Options.php                architect   (§3.2, §4)
│   ├── Request.php                architect → config   (§3.3)
│   ├── Response.php               architect → config   (§3.3)
│   ├── Config.php                 architect → config   (§3.4, §4)
│   ├── Guard.php                  stub → guard         (§3.5, §6)
│   ├── Page.php                   stub → guard         (§3.6, §7)
│   ├── Media.php                  stub → media         (§3.7, §8)
│   ├── Cli.php                    stub → cli           (§3.8, §9)
│   └── Sudare.php                 architect → docs-http (§3.9)
├── bin/
│   ├── sudare-hash                architect → cli      (§9.1)
│   └── sudare-check               architect → cli      (§9.2)
├── translations/en.php, de.php    architect   (§5)
├── examples/
│   ├── sudare.php                 config      (§4, the new config template)
│   ├── credentials.php            config      (legacy template, what the sites have today)
│   ├── index.php                  docs-http   (§11.3, a complete public/index.php)
│   └── htaccess.txt               docs-http   (§11.3, the media/assets rewrite lines)
├── tests/
│   ├── run.sh                     architect
│   ├── helpers.php                architect   (§10.0)
│   ├── I18nTest.php               architect
│   ├── OptionsTest.php            architect
│   ├── RequestTest.php            config      (§10.1, Request + Response)
│   ├── ConfigTest.php             config      (§10.2)
│   ├── GuardTest.php              guard       (§10.3)
│   ├── PageTest.php               guard       (§10.4)
│   ├── MediaTest.php              media       (§10.5)
│   ├── CliTest.php                cli         (§10.6)
│   ├── SudareTest.php             docs-http   (§10.7, subprocess; sealed() pure)
│   ├── HttpTest.php               docs-http   (§10.8, PHP built-in server)
│   └── KirbyHttpTest.php          docs-http   (§10.9, built-in server with a real Kirby; skipped without vendor/)
├── README.md                      docs-http   (§11)
└── CHANGELOG.md                   docs-http
```

Run everything: `cd <this repository> && ASDF_PHP_VERSION=8.5.3 sh tests/run.sh`.
One file: `ASDF_PHP_VERSION=8.5.3 php tests/GuardTest.php`.

---

## 3. Architecture and public API

```
 public/index.php
   │ require vendor/autoload.php      (bootstrap.php declares the classes, runs nothing)
   │ Sudare\Sudare::guard($base)
   ▼
 Sudare (facade)  ── Config::load($base, $env) ──▶ Config  (state none|off|broken|on)
   │                 Request::fromServer($_SERVER) ──▶ Request
   │
   ├─ Guard::handle(Config, Request) ──▶ Response   pass | 401 | 503 | 204
   │        └─ Page::denied() / Page::unavailable() / Page::realmHeader()
   │
   ├─ if pass: Media::respond($public, Request, Config) ──▶ Response(file) | null
   │
   └─ emit(Response): headers; if not pass → status + body/file, exit
 ▼
 Kirby boots (only when guard() returned)
```

Everything is Kirby-free. `Guard`, `Page`, `Config`, `Request`, `Response`,
`Options`, `I18n` touch neither `$_SERVER`, `header()`, `exit` nor the file
system (Config reads its config file, Media reads the file it serves —
nothing else). Only `Sudare::guard()`/`emit()`/`seal()` and the `bin/` wrappers
have side effects.

### 3.1 `bootstrap.php` (architect) — the entry point

Equivalent of a plugin's `index.php`: `require_once` of every `src/*.php`
in dependency order, plus the global helper:

```php
function sudare_option(string $key, mixed $default = null): mixed
// = Sudare\Sudare::config()?->option($key, $default) ?? $default
// Before guard() ran or without a configuration: the default. hash/token: always null.
```

Loaded by Composer through `autoload.files`, or directly:
`require $base . '/vendor/sayamaapps/kirby-sudare/bootstrap.php';`

### 3.2 `Sudare\I18n`, `Sudare\Options` (architect)

`I18n` is identical in behaviour to Noren's: `LANGUAGES = ['en','de']`,
`setLanguage()`, `language()`, `table($language)`, `t($key, $values)` with
`{name}` placeholders, English fallback, key fallback.
**Guard/Page/Cli set the language per call from `Config::language()`**
(`I18n::setLanguage($config->language())`) before producing text.

`Options` reads a raw array (three spellings) and the environment:

```php
Options::PREFIX = 'sudare.';  Options::ENV_PREFIX = 'SUDARE_';
Options::KEYS        = ['active','user','hash','realm','token','exempt','language','theme','media','assets'];
Options::SECRET_KEYS = ['hash','token'];
Options::ENV_KEYS    = KEYS without 'theme';
Options::get(array $raw, string $key, mixed $default = null): mixed   // flat > nested > bare; null = unset
Options::has(array $raw, string $key): bool                           // present in any spelling
Options::envKey(string $key): string                                  // 'user' → 'SUDARE_USER'
Options::env(array $env, string $key, mixed $default = null): mixed   // '' counts as unset
Options::envPresent(array $env): bool                                 // any ENV_KEYS set?
Options::mergeEnv(array $raw, array $env): array                      // env wins per key (bare spelling)
Options::bool(mixed $value): ?bool    // true/false, 'true','1','yes','on' / 'false','0','no','off'; else null
Options::list(mixed $value): ?array   // array or "a, b" → trimmed list<string>; else null
```

### 3.3 `Sudare\Request`, `Sudare\Response` (architect → config)

```php
final class Request
  const TOKEN_HEADER = 'X-Sudare-Token';  const TOKEN_SERVER_KEY = 'HTTP_X_SUDARE_TOKEN';
  static fromServer(array $server): self
  static normalisePath(string $path): string   // rawurldecode ONCE, NUL removed, "."/".." resolved,
                                               // duplicate slashes collapsed, leading "/", no trailing "/" (except "/")
  static kirbyMethod(array $server): string     // the method Kirby routes (copy of detectRequestMethod(), §4.3 exempt)
  method(): string          // upper-case, 'GET' when missing/garbage
  sentMethod(): string      // REQUEST_METHOD exactly as sent
  routedMethod(): string    // kirbyMethod(): X-HTTP-Method-Override honoured as Kirby does
  path(): string            // normalised, query/fragment removed
  rawPath(): string         // as sent, without query
  isCanonical(): bool       // rawPath already in normal form: no "%", no dot/empty segment
  credentials(): ?array     // ['user' => string, 'password' => string] from PHP_AUTH_USER/PW,
                            // else from HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION "Basic base64(user:pass)";
                            // null when absent or malformed (bad base64, no colon)
  token(): ?string          // plain value of X-Sudare-Token, null when absent/empty
  range(): ?string          // Range header, GET only
  condition(string $name): ?string   // If-None-Match, If-Modified-Since, If-Range (trimmed)
  isHead(): bool  isOptions(): bool  isRead(): bool  wantsBody(): bool   // wantsBody = !HEAD && !OPTIONS

final class Response   // immutable
  static pass(array $headers = []): self                       // continue to Kirby, send these headers first
  static text(int $status, string $body, array $headers = []): self   // Content-Type text/plain; charset=utf-8
  static html(int $status, string $body, array $headers = []): self   // text/html; charset=utf-8
  static empty(int $status, array $headers = []): self
  static file(string $path, string $contentType, array $headers = []): self  // Content-Length from filesize
  status(): int  headers(): array  header(string $name): ?string (case-insensitive)
  body(): string  filePath(): ?string  passes(): bool
  withHeader(string, string): self  withHeaders(array): self  withStatus(int): self  withoutBody(): self
  withRange(int $first, int $last, int $size): self   // 206 + Content-Range/Content-Length; range(): ?array{0,1}
  toArray(): array{status, headers, body, file, pass, range}
```

Example `toArray()` of a 401:

```php
['status' => 401,
 'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex, nofollow, noarchive',
               'Cache-Control' => 'private, no-store', 'Vary' => 'Authorization, X-Sudare-Token',
               'WWW-Authenticate' => 'Basic realm="Preview", charset="UTF-8"'],
 'body' => '<!doctype html>…', 'file' => null, 'pass' => false]
```

### 3.4 `Sudare\Config` (architect → config) — see §4 for semantics

```php
final class Config   // immutable
  const STATE_NONE='none', STATE_OFF='off', STATE_BROKEN='broken', STATE_ON='on';
  const FILE = 'site/config/sudare.php';  const LEGACY_FILE = 'site/config/credentials.php';
  const ACME = '/.well-known/acme-challenge';
  const EXAMPLE_MARKERS = ['ERSETZEN', 'REPLACE'];
  const THEME_KEYS = ['chrome','paper','ink','deep','text'];
  const DEFAULT_THEME = ['chrome'=>'#1E3648','paper'=>'#FFFFFF','ink'=>'#15222B','deep'=>'#1E3648','text'=>'#41505A'];

  static load(string $base, array $env = []): self            // §4.1 source order
  static fromFile(string $file, array $env = []): self
  static readFile(string $file): array|string                 // raw array, or reason 'unreadable'|'syntax'|'notArray'
  static fromArray(array $raw, array $env = [], string $source = 'array'): self
  static none(): self
  static isLegacy(array $raw): bool                           // has 'vorschau' array, no English keys
  static fromLegacy(array $raw): array                        // benutzer→user, hash→hash, realm→realm
  static isHash(string $hash): bool                           // syntax only: full bcrypt shape for any $2[abxy]$ (cost 04–31, 60 chars), Argon2 encoded form, else password_get_info
  static startsWithSegment(string $path, string $prefix): bool  // $path === $prefix || starts with "$prefix/"

  state(): string   protects(): bool (on)   enabled(): bool (on|broken)
  source(): ?string   // 'sudare.php' | 'credentials.php' | 'environment' | 'array' | null
  reason(): ?string   // reason code while broken (§4.4), else null
  user(): ?string  hash(): ?string  token(): ?string  hasToken(): bool
  realm(): string  exempt(): array  isExempt(string $path, string $method = 'GET', ?string $sent = null): bool  (ACME always true; §6.2 rows 4)
  language(): string ('en'|'de')  theme(): array  media(): bool  assets(): bool
  algorithm(): ?string          // 'bcrypt', 'argon2id', … of the password hash
  option(string $key, mixed $default = null): mixed   // non-secret raw option; hash/token → null
  toArray(): array{state, source, reason, user, algorithm, realm, token: bool, exempt, language, media, assets}
```

### 3.5 `Sudare\Guard` (stub → guard) — see §6

```php
final class Guard
  const ROBOTS = 'noindex, nofollow, noarchive';
  const RETRY_AFTER = '300';
  const ALLOW = 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS';
  const VARY = 'Authorization, ' . Request::TOKEN_HEADER;   // 'Authorization, X-Sudare-Token'
  static headers(): array        // ['X-Robots-Tag' => ROBOTS, 'Cache-Control' => 'private, no-store', 'Vary' => VARY]
  static handle(Config $config, Request $request): Response
  static sameUser(string $expected, string $given): bool   // hash_equals(hash('sha256',…), hash('sha256',…))
```

### 3.6 `Sudare\Page` (stub → guard) — see §7

```php
final class Page
  static denied(Config $config): string        // full HTML document, 401 page
  static unavailable(Config $config): string   // one sentence, 503
  static realmHeader(string $realm): string    // 'Basic realm="<clean>", charset="UTF-8"'
```

### 3.7 `Sudare\Media` (stub → media) — see §8

```php
final class Media
  const MEDIA_TYPES, CODE_TYPES, ASSET_TYPES (ext => content type, see stub), CODE_DIRS = ['panel','plugins'], MAX_AGE = '31536000'
  static resolve(string $public, string $path, bool $media = true, bool $assets = true): ?array  // ['file' => realpath, 'type' => string] | null
  static respond(string $public, Request $request, Config $config): ?Response
```

### 3.8 `Sudare\Cli` (stub → cli) — see §9

```php
final class Cli
  const EXIT_OK = 0, EXIT_FAIL = 1, EXIT_BROKEN = 2, EXIT_USAGE = 3;
  const MIN_PASSWORD_LENGTH = 8;  const DEFAULT_USER = 'preview';
  static hash(array $argv, $in, $out, $err, array $env = []): int
  static check(array $argv, $out, $err, array $env, string $cwd): int
  static arguments(array $argv): array   // ['--user=x' → 'user'=>'x', '--legacy' → 'legacy'=>true, positionals → '_' => [...]]
```

### 3.9 `Sudare\Sudare` (architect → docs-http) — the facade and the contract

```php
final class Sudare
  static guard(string $base, ?string $public = null, ?array $server = null, ?array $env = null): void
      // handle() + emit(); exit unless the response passes. $public defaults to "$base/public".
  static handle(string $base, ?string $public, array $server, array $env): Response   // pure, remembers Config
  static emit(Response $response): void      // headers; status+body/file when not a pass; never exits;
                                             // a pass WITH headers also registers the seal (header_register_callback)
  static seal(array $protective): void       // sets the protective headers again right before PHP sends the response (§6.1)
  static sealed(array $protective, array $sent): array   // PURE: name => value to set again; $sent = headers_list() lines
  static active(): bool                      // THE CONTRACT: Config of this request protects(); false before guard()
  static config(): ?Config
  static protects(string $base, ?array $env = null): bool   // stateless re-evaluation
  static environment(?array $server = null): array          // getenv() + SUDARE_* keys of $server
  static translations(): array               // ['en' => ['sudare.<key>' => …], 'de' => …] for a site plugin
  static reset(): void                       // tests
```

Annai (and any site code) asks:
`class_exists(\Sudare\Sudare::class) && \Sudare\Sudare::active()`.
A site's `vorschauAktiv($basis)` call becomes `\Sudare\Sudare::active()` (after
`guard()`) or `\Sudare\Sudare::protects($base)` (anywhere).

---

## 4. Configuration

### 4.1 Sources, in this order

1. `<base>/site/config/sudare.php` — returns an array with English keys. Bare
   (`'user' => …`), nested (`'sudare' => ['user' => …]`) and flat
   (`'sudare.user' => …`) spellings all work; flat beats nested beats bare.
2. `<base>/site/config/credentials.php` — **the legacy file** of all sites:
   `['vorschau' => ['benutzer' => 'x', 'hash' => '$2y$…', 'realm' => '…']]`.
   Read only when `sudare.php` does not exist. Mapped to `user`/`hash`/`realm`.
   A `credentials.php` that already uses English keys is read as new format.
3. `SUDARE_*` environment variables, laid over the file **per key**
   (`SUDARE_USER`, `SUDARE_HASH`, `SUDARE_REALM`, `SUDARE_TOKEN`,
   `SUDARE_EXEMPT` (comma list), `SUDARE_LANGUAGE`, `SUDARE_ACTIVE`,
   `SUDARE_MEDIA`, `SUDARE_ASSETS`; never `theme`). The environment alone (no
   file) is a source too — except `SUDARE_LANGUAGE` on its own, which also
   picks the CLIs' language (`Options::envPresent()` skips it).
   `Sudare::environment()` = `getenv()` + the `SUDARE_*` keys of `$_SERVER`
   (Apache `SetEnv` lands there).

A file that exists but is a dangling symlink counts as present (→ broken,
`unreadable`). The same for the directories: `site/` or `site/config/` as a
dangling link or without the search bit for PHP → broken, `unreadable`
(source = that directory) — otherwise both files would look absent and the
site would open. A file is included with output buffering and every `Throwable`
caught; `ParseError` → `syntax`, non-array → `notArray`. A file that ends the
script instead (`exit`, `die`, a fatal error) is caught by a shutdown function:
output discarded, 503 (in a CLI: `cli.check.broken` with `reason.exit` on
STDERR, exit 2). Each file runs once per request (memoised by real path and
content hash), so `guard()` + `protects()` never declare a function twice.

### 4.2 States

| State | When | Guard does |
|---|---|---|
| `none` | no file, no `SUDARE_*` variable | nothing: pass, no headers (local development, public site) |
| `off` | a source exists and `active` is `false` | nothing: pass, no headers |
| `broken` | a source exists and does not validate (§4.4) | **503 on every request** (ACME path excepted), protective headers |
| `on` | valid | Basic Auth / token, protective headers |

`active` defaults to **true when a source exists** (deviation from the
family's `active => true` rule, see §12/§13 — the legacy file has no such key
and the presence of the file has always been the switch).

### 4.3 Options (all optional except `user` + `hash` while on)

| Key | Env | Type | Default | Meaning |
|---|---|---|---|---|
| `active` | `SUDARE_ACTIVE` | bool | `true` | `false` parks the configuration: site open, no headers. |
| `user` | `SUDARE_USER` | string | — | Basic Auth user. Non-empty, no `:` (Basic Auth splits there), no control characters. Trimmed. |
| `hash` | `SUDARE_HASH` | string | — | `password_hash()` output (bcrypt or argon2), or a bcrypt hash with the `$2a$`/`$2b$`/`$2x$` prefix (python-bcrypt, htpasswd; `password_verify()` takes them, `password_get_info()` does not). Checked by syntax only, never by verifying: every bcrypt prefix (`$2a$`/`$2b$`/`$2x$`/`$2y$`) must have the full 60-character shape with cost 04–31 and the bcrypt alphabet, an Argon2 hash its well-formed encoded form (and a PHP that knows Argon2). `password_get_info()` looks only at prefix and length, so a damaged `$2y$` hash would pass it. A damaged hash is `broken` (reason `hash`, or `token` for the token), never `on`. Never a bare prefix test. Trimmed (a pasted trailing newline is the same hash). Never a plain password. Must not contain `ERSETZEN`/`REPLACE` (the templates' example). |
| `realm` | `SUDARE_REALM` | string | translated `realm` (`Preview` / `Vorschau`) | Text in the browser dialog. Sanitised at output (§7.3). |
| `token` | `SUDARE_TOKEN` | string | `null` | **Hash** of a machine token (`password_hash()` of the random token). Requests with header `X-Sudare-Token: <plain token>` pass without Basic Auth (CI smoke tests, uptime checks). |
| `exempt` | `SUDARE_EXEMPT` | list of entries | `[]` | `[METHOD ]/path[/*]` (`Config::exemptEntry()`): `/p` matches the normalised path `p` only, `/p/*` also below it, a method only that method (GET includes HEAD). Exact by default: an exempt request reaches Kirby, whose error page (with navigation) answers every unrouted sub-path or method. Entries must already be in normal form (only `[A-Za-z0-9._/-]`, no dot/empty segment, ≤ 1 trailing slash; Kirby drops `name:value` segments — `;` on Windows — from the routed path, so `/lang:de/*` opened `/lang:de/panel`): decoding after the check would turn `/a/%2A` into `/a/*`. An entry with a method needs it twice — `Request::routedMethod()` (as Kirby routes it) and `Request::sentMethod()` (REQUEST_METHOD as sent); `HEAD` entries an exact `HEAD`; `OPTIONS` entries are broken. `routedMethod()` = `Request::kirbyMethod()`, a copy of `Kirby\Http\Request::detectRequestMethod()`: override header `strtoupper()`ed without trim, only Kirby's 9 methods, unknown → GET (RequestTest checks it against Kirby itself). Entry methods outside those 9 → broken. `/`, `/*`, a `*` elsewhere → broken. `/.well-known/acme-challenge` is always exempt. |
| `language` | `SUDARE_LANGUAGE` | `en`\|`de` | `en`; **`de` when the source is the legacy file** | Language of the 401 page, the 503 text, the default realm. |
| `theme` | — | array | `Config::DEFAULT_THEME` | Colours of the 401 page: `chrome` (browser `theme-color`), `paper` (background), `ink` (body text), `deep` (heading), `text` (paragraph). Each `#rgb`, `#rrggbb` or `#rrggbbaa`; missing keys keep the default. (A site computes the array itself in `sudare.php`.) |
| `media` | `SUDARE_MEDIA` | bool | `true` | Serve `public/media/**` through PHP (§8). `false`: leave every media request to Kirby. |
| `assets` | `SUDARE_ASSETS` | bool | `true` | Serve `public/assets/**` through PHP when the request reaches PHP (§8). |

### 4.4 Reason codes (`Config::reason()`, translation `reason.<code>`)

In validation order: `unreadable`, `syntax`, `notArray`, `active`, `user`,
`example`, `hash`, `token`, `realm`, `exempt`, `language`, `media`, `assets`,
`theme`. (The check order inside Config.php: active → user → example marker →
hash recognised → token → realm → exempt → language → media → assets → theme.)
`reason.exit` has a text but is no `reason()` code: only the shutdown function
of §4.1 prints it.

### 4.5 `examples/sudare.php` (task config) — the new template

```php
<?php

/**
 * Sudare — preview protection. Template for site/config/sudare.php
 * (gitignored; on the server it is written from the deploy secret SUDARE_PHP).
 * Generate: vendor/bin/sudare-hash --user=preview > site/config/sudare.php
 */
return [
    'user'  => 'preview',
    'hash'  => '$2y$12$REPLACE.WITH.YOUR.OWN.HASH.OR.NOBODY.GETS.IN.AT.ALL',
    // 'realm'    => 'Preview — Project name',
    // 'token'    => '$2y$12$…',                 // vendor/bin/sudare-hash --token
    // 'exempt'   => ['/api/webhook'],
    // 'language' => 'de',
    // 'theme'    => ['chrome' => '#16242F', 'paper' => '#FCFAF8', 'ink' => '#15222B', 'deep' => '#1E3648', 'text' => '#41505A'],
];
```

`examples/credentials.php`: the legacy format as in the template
`site/config/credentials.example.php`, with an English header
comment saying "still read; migrate with `sudare-hash`".

---

## 5. Translation keys (complete; `translations/en.php` is the reference)

| Key | en | de |
|---|---|---|
| `realm` | Preview | Vorschau |
| `denied.pageTitle` | Preview | Vorschau |
| `denied.heading` | This site is not public yet | Diese Seite ist noch nicht öffentlich |
| `denied.text` | It is under construction and can only be reached with access credentials. | Sie befindet sich im Aufbau und ist nur mit Zugangsdaten erreichbar. |
| `unavailable.text` | The preview is temporarily unavailable. | Die Vorschau ist vorübergehend nicht verfügbar. |
| `cli.hash.prompt` | Password for the preview:  | Passwort für die Vorschau:  |
| `cli.hash.visible` | Warning: shell_exec is disabled, the password will be visible … | Achtung: shell_exec ist gesperrt, das Passwort ist beim Tippen sichtbar … |
| `cli.hash.empty` | No password entered, aborting. | Kein Passwort eingegeben, Abbruch. |
| `cli.hash.short` | The password is shorter than {min} characters. Please choose something longer. | Das Passwort ist kürzer als {min} Zeichen. Bitte etwas Längeres. |
| `cli.hash.long` | The password is longer than {max} bytes or contains a NUL character: bcrypt would ignore part of it. … | Das Passwort ist länger als {max} Byte oder enthält ein NUL-Zeichen … |
| `cli.hash.mismatch` | The generated hash does not confirm the password. This must not happen. | Der erzeugte Hash bestätigt das Passwort nicht. Das darf nicht passieren. |
| `cli.hash.info` | Algorithm: {algo}, cost {cost} | Verfahren: {algo}, Kosten {cost} |
| `cli.hash.output` | Content for the secret {secret}: | Inhalt für das Secret {secret}: |
| `cli.hash.token` | Machine token (shown once — store it in your CI secrets): {token} | Maschinen-Token (wird nur einmal gezeigt — in den CI-Secrets ablegen): {token} |
| `cli.hash.usage` | Usage: sudare-hash [--user=NAME] [--realm=TEXT] [--legacy] [--token] [--cost=4-31] [--language=en\|de] | Aufruf: … |
| `cli.check.none` | No Sudare configuration at {path}. The site is NOT protected. | Keine Sudare-Konfiguration unter {path}. Die Seite ist NICHT geschützt. |
| `cli.check.off` | Sudare is switched off (active => false). The site is NOT protected. | Sudare ist ausgeschaltet … |
| `cli.check.allowOff` | Not protected — accepted because of --allow-off (public deploy). | Nicht geschützt — wegen --allow-off akzeptiert (öffentlicher Deploy). |
| `cli.check.broken` | The Sudare configuration is broken: {reason}. Every request would be answered with 503. | Die Sudare-Konfiguration ist beschädigt: {reason}. Jede Anfrage würde mit 503 beantwortet. |
| `cli.check.ok` | Sudare configuration valid ({source}): user "{user}", {algo} hash, realm "{realm}". | Sudare-Konfiguration gültig ({source}): Benutzer „{user}“, {algo}-Hash, Realm „{realm}“. |
| `cli.check.token` | Machine token: configured. | Maschinen-Token: eingerichtet. |
| `cli.check.exempt` | Exempt paths: {paths} | Ausgenommene Pfade: {paths} |
| `cli.check.files` | Files served through PHP: media/ {media}, assets/ {assets}. | Dateien über PHP: media/ {media}, assets/ {assets}. |
| `cli.check.style` | Colours of the 401 page under a Content-Security-Policy: add {source} to style-src. | Farben der 401-Seite unter einer Content-Security-Policy: {source} in style-src aufnehmen. |
| `cli.check.env` | Counted from the environment (--env): {keys}. | Aus der Umgebung mitgezählt (--env): {keys}. |
| `cli.check.opcache` | opcache.restrict_api is set in this PHP: Sudare cannot drop the cached copy of the file … Add {file} to opcache.blacklist_filename. | opcache.restrict_api ist in diesem PHP gesetzt … {file} in opcache.blacklist_filename aufnehmen. |
| `cli.check.usage` | Usage: sudare-check [PROJECT-ROOT\|CONFIG-FILE] [--allow-off] [--env] [--language=en\|de] | Aufruf: … |
| `reason.unreadable` … `reason.theme`, `reason.exit` | one sentence per code of §4.4 (see the files) | … |
| `word.yes` / `word.no` | yes / no | ja / nein |

Placeholders: `{min}`, `{max}`, `{algo}`, `{cost}`, `{secret}`, `{token}`, `{path}`,
`{reason}`, `{source}`, `{user}`, `{realm}`, `{paths}`, `{media}`, `{assets}`,
`{keys}`, `{file}`. `tests/I18nTest.php` enforces identical keys and
placeholders in both files.

---

## 6. Guard — the decision (task guard)

### 6.1 Protective headers

`Guard::headers()` =
`['X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Cache-Control' => 'private, no-store', 'Vary' => 'Authorization, X-Sudare-Token']`.
They are on **every** response while the state is `on` or `broken` — pass,
401, 503, 204 — and Media adds its own variant for files (§8.3). While `none`
or `off`, Sudare sends **no header at all**. `Vary` names `X-Sudare-Token`
(`Request::TOKEN_HEADER`) too: a request authenticated by the token header is
otherwise the same cache variant as an anonymous one.

**The seal.** A pass leaves the response to Kirby, and `Kirby\Http\Response::send()`
sets its own headers with `header($key . ': ' . $value)`, which replaces
Sudare's by name — a plugin route answering `Cache-Control: public,
max-age=2592000` would leave the preview cacheable by a shared cache. So
`Sudare::emit()` registers `header_register_callback(static fn () =>
Sudare::seal($headers))` for a response that **passes and has headers**.
PHP runs the callback right before it sends the headers; `headers_list()`
then shows everything set so far, Kirby's included. `seal()` calls the pure
`Sudare::sealed($protective, $sent)` and for every name in the result runs
`header_remove($name)` and `header($name . ': ' . $value, true)`. Nothing
else: no output, no exit, no status change.

- Every protective header is set again with its own value, replacing
  whatever was sent under that name (header names compare case-insensitively).
- **`Vary` is the exception: it only grows.** All values of all sent `Vary`
  lines come first (split on `,`, trimmed, empty ones dropped, in order), then
  the protective values not yet present. Values compare case-insensitively,
  the first spelling stays; if any value is `*`, the result is `*`. The
  Panel's `Vary: Cookie` becomes `Cookie, Authorization, X-Sudare-Token`.
- Headers that are not protective (`Content-Type`, `X-Content-Type-Options`,
  …) are not touched.
- No seal for Sudare's own answers (401, 503, 204, files: they end the
  request and keep their own headers), and none without headers (`none`,
  `off`).
- PHP keeps **one** such callback per request: code that registers its own
  after `guard()` replaces the seal, and that code has to set the headers
  itself.

### 6.2 Decision table (`Guard::handle($config, $request)`), first match wins

| # | Condition | Response |
|---|---|---|
| 1 | state `none` or `off` | `Response::pass([])` |
| 2 | `isCanonical()` AND `Config::startsWithSegment(path, Config::ACME)` | `Response::pass(headers())` — even while broken: certificate renewal must not depend on the config. `Media::respond()` then answers it (file or 404, §8.2); Kirby never sees it |
| 3 | state `broken` | `Response::text(503, Page::unavailable($config), headers() + ['Retry-After' => '300'])` |
| 4 | `isCanonical()` AND `$config->isExempt(path, routedMethod(), sentMethod())` | `Response::pass(headers())` — an entry with a method needs it as routed AND as sent; a HEAD entry an exact `HEAD` |
| 5 | method `OPTIONS` | `Response::empty(204, headers() + ['Allow' => Guard::ALLOW])` — no authentication needed, nothing is revealed |
| 6 | `token()` given AND `$config->hasToken()` AND `password_verify(token, $config->token())` | `Response::pass(headers())` |
| 7 | `credentials()` given AND `sameUser($config->user(), user)` AND `password_verify(password, $config->hash())` | `Response::pass(headers())` |
| 8 | otherwise | `Response::html(401, Page::denied($config), headers() + ['WWW-Authenticate' => Page::realmHeader($config->realm())])` |

Notes:
- Evaluate `sameUser` **and** `password_verify` always both (no short-circuit
  on the user) so a wrong user costs the same time as a wrong password:
  `$userOk = sameUser(...); $passOk = password_verify(...); if ($userOk && $passOk)`.
  When no credentials are given, verify against a dummy is not required.
- Panel (`/panel`, `/panel/...`), API (`/api/...`), `/media/...`, `/assets/...`
  and every other path go through the same rows — there is no special case;
  tests prove it (§10.3).
- HEAD is **not** treated here: `Sudare::handle()` strips the body afterwards
  (`Response::withoutBody()`), so a HEAD to a protected page is a 401 with
  headers and no body, and a HEAD to a media file keeps Content-Length.
- Sudare never redirects (no `Location` header anywhere) — there is no open
  redirect by construction.
- `I18n::setLanguage($config->language())` before any `Page::` call.
- Rows 2 and 4 decide on the normalised path, but Kirby routes the raw
  `REQUEST_URI`: `/panel/../api/hook` is `/api/hook` here and the Panel there.
  `Request::isCanonical()` (no dot or empty segment, no `%` at all — any
  percent-encoding, e.g. `/%61pi/hook`, decodes to an exempt path here and
  stays a different one for Kirby; a trailing slash is fine) gates both rows;
  anything else falls through to rows 5–8.

---

## 7. Page — bodies (task guard)

### 7.1 `Page::denied(Config)` — 401 HTML

Structure (port of the original preview page, plus viewport and theme-color meta tags):

```html
<!doctype html><html lang="{language}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="theme-color" content="{chrome}">
<title>{denied.pageTitle}</title>
<style>body{font:16px/1.6 -apple-system,system-ui,sans-serif;max-width:34rem;margin:18vh auto;padding:0 1.5rem;background:{paper};color:{ink}}h1{font:600 26px/1.2 -apple-system,system-ui,sans-serif;color:{deep};margin:0 0 .6rem}p{color:{text}}</style>
</head><body><h1>{denied.heading}</h1><p>{denied.text}</p></body></html>
```

- Texts via `I18n::t()` in `$config->language()`, HTML-escaped with
  `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- Theme values come validated from Config (hex only) — still escape them.
- No external resource, no script, no reference to the realm or the user.
- Deterministic: same Config → same string (tests compare substrings).

### 7.2 `Page::unavailable(Config)` — 503 text

`I18n::t('unavailable.text')` in the config language, followed by `"\n"`.
Never the reason, never a path.

### 7.3 `Page::realmHeader(string $realm)`

Remove `"`, `\`, `\r`, `\n` from the realm (as the original did), collapse to
`Preview` when the result is empty, then
`'Basic realm="' . $clean . '", charset="UTF-8"'`.

---

## 8. Media — files through PHP (task media)

Why: `.htaccess` sends `media/**` to `index.php` so generated thumbnails and
uploads are not served by Apache past the password. The same rule can be
added for `assets/**` (§11.3). Sudare serves these files itself — fast, no
Kirby boot — **whether or not the site is protected** (a public site with
the rewrite still needs its images). Only the cache headers differ.

### 8.1 `Media::resolve($public, $path, $media, $assets): ?array`

1. `$path` is already normalised (Request::path()). It must start with
   `/media/` (and `$media`) or `/assets/` (and `$assets`); else `null`.
2. `$root = realpath($public . '/media')` (or `/assets`); `$file = realpath($public . $path)`.
   `null` when either is `false`, when `$file` is not `str_starts_with($file, $root . DIRECTORY_SEPARATOR)`
   (traversal: `..`, `%2e%2e`, symlink out), or when `!is_file($file)`.
3. Extension = `strtolower(pathinfo($file, PATHINFO_EXTENSION))`. Allowed:
   - under `media/`: `MEDIA_TYPES`; **plus** `CODE_TYPES` only when the path
     relative to the media root starts with `panel/` or `plugins/`
     (`CODE_DIRS`) — checked on the **resolved** relative path, never the URL;
   - under `assets/`: `MEDIA_TYPES + CODE_TYPES + ASSET_TYPES`.
   Anything else (`.php`, `.phtml`, `.html`, no extension, `.js` under
   `media/pages/`) → `null` (Kirby decides; usually 404).
4. Return `['file' => $file, 'type' => $contentType]`.

Dotfiles (`/.htaccess`, `/assets/.env`) → `null` always.

### 8.2 `Media::respond($public, Request $request, Config $config): ?Response`

While Sudare is enabled (`on` or `broken`), a path under `Config::ACME` is
answered here and never reaches Kirby: the file
`public/.well-known/acme-challenge/<token>` (token `[A-Za-z0-9_-]+`, real path
inside that directory) as `text/plain` with `Guard::headers()` and `nosniff`,
else an empty 404 with the same headers.

Files answer GET and HEAD only (`Request::isRead()`); any other method →
`null` (Kirby), except an ACME path → empty 404.

Otherwise `null` unless `resolve()` finds a file (with `$config->media()`,
`$config->assets()`). Then the validators `ETag` (`"<size hex>-<mtime hex>"`)
and `Last-Modified`; `If-None-Match` (weak comparison, `*`, lists) or else
`If-Modified-Since` (strict HTTP date) → 304 with the cache headers, no body.
Otherwise `Response::file($file, $type, $headers)` with the headers of §8.3,
and a byte range from `Request::range()` (GET only; with `If-Range` only when
it equals the ETag or Last-Modified): `Media::range()` → `[first, last]` → 206
with `Content-Range` (`Response::withRange()`); a start beyond the file → 416
with `Content-Range: bytes */size` and no body; several ranges, an empty file
or an unreadable header → the whole file. Safari plays video/audio only with
ranges. `Sudare::emit()` streams every file in 64 KB chunks after ending all
output buffers (an active buffer would hold the whole file in memory).

### 8.3 Headers of a served file

| | `$config->protects()` (blind down) | `none` / `off` |
|---|---|---|
| `Cache-Control`, `media/` | `private, max-age=31536000` | `public, max-age=31536000` |
| `Cache-Control`, `assets/` | `private, no-cache` | `public, no-cache` |
| `ETag`, `Last-Modified` | size + mtime | same |
| `X-Robots-Tag` | `noindex, nofollow, noarchive` | — |
| `Vary` | `Authorization, X-Sudare-Token` | — |
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `Accept-Ranges` | `bytes` | `bytes` |
| `Content-Type`, `Content-Length` | from `Response::file()` | same |

(`broken` never reaches Media except for ACME: Guard answered 503.) Kirby's
`media/` URLs carry a hash that changes with the file, so the browser may keep
them a year; `assets/` URLs do not change on deploy, so they are revalidated
(304). Shared caches keep nothing while protected (`private`).

---

## 9. CLI (task cli) — `Cli::hash`, `Cli::check`, `Cli::arguments`

Both read `--language=en|de` and set `I18n` before printing. `sudare-hash`:
default `en`, `SUDARE_LANGUAGE` from `$env` as fallback. `sudare-check`:
`--language` wins, else the language of the configuration it found (a legacy
file: `de`; `SUDARE_LANGUAGE` is part of it only with `--env`), else — no
configuration — `SUDARE_LANGUAGE`, else `en`. Every message except the generated
file goes to `$err`. `--help`/`-h` prints the usage to `$out`, exit 0.
Unknown `--option` → usage to `$err`, exit 3.

### 9.1 `sudare-hash` — port of `scripts/hash-erzeugen.php`

Options: `--user=NAME` (default `preview`), `--realm=TEXT`, `--legacy`
(credentials.php format), `--token` (also generate a machine token),
`--language=`.

1. Prompt `cli.hash.prompt` on `$err`. Read one line from `$in`. If `$in`
   is a TTY (`stream_isatty`), switch echo off with `stty -echo` around the
   read and print `\n` afterwards (as the original); in a pipe read plainly.
2. Empty → `cli.hash.empty`, exit 1. `mb_strlen < 8` → `cli.hash.short`
   (`{min}`), exit 1. More than 72 bytes or a NUL byte → `cli.hash.long`
   (`{max}`), exit 1 — bcrypt ignores the rest, and `password_hash()` throws
   on NUL. `--cost=N` (4–31, default `Cli::DEFAULT_COST` = 10;
   PHP's own default of 12 since 8.4 costs ~250 ms per authenticated file
   request), anything else → usage, exit 3.
3. `$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost])`
   — bcrypt explicitly, never `PASSWORD_DEFAULT`; `password_verify`
   roundtrip, else `cli.hash.mismatch`, exit 1. `Config` accepts bcrypt and
   Argon2 hashes of valid shape (a pasted Argon2 hash works).
4. `--token`: `$token = bin2hex(random_bytes(24))`, `$tokenHash = password_hash($token, PASSWORD_BCRYPT, ['cost' => $cost])`;
   print `cli.hash.token` with the plain token to `$err` (the only time it is shown).
5. To `$err`: `cli.hash.info` (`{algo}` = `password_get_info()['algoName']`,
   `{cost}` = `options['cost'] ?? '—'`), blank line, `cli.hash.output` with
   `{secret}` = `SUDARE_PHP` (or `CREDENTIALS_PHP` with `--legacy`), blank line.
6. To `$out` the file, exactly:

```php
<?php

return [
    'user' => 'preview',
    'hash' => '$2y$10$…',
    'realm' => 'Preview — X',      // only with --realm
    'token' => '$2y$10$…',         // only with --token
];
```

   With `--legacy`:
   `<?php\n\nreturn ['vorschau' => ['benutzer' => 'preview', 'hash' => '…']];\n`
   (+ `'realm' => …` when given; `--token` with `--legacy` is a usage error, exit 3 —
   the legacy file has no token).
   Values are emitted with `var_export()` (single quotes, escaping done by PHP).
7. Exit 0. The plain password is never written anywhere.

### 9.2 `sudare-check` — port of `scripts/pruefe-zugangsdaten.php`

Arguments: one optional positional — a project root (directory: `Config::load`)
or a file (`Config::fromFile`); default `$cwd`. Relative paths resolve against
`$cwd`. A path that does not exist (and is no dangling symlink) is a root
without configuration (`Config::load` → `none`, or the environment). Flags
`--allow-off`, `--language=`.

Output (to `$out`), by state, then exit code:

| State | Lines | Exit |
|---|---|---|
| `none` | `cli.check.none` (`{path}` = the root or file as given) | 1 (0 with `--allow-off`, then also `cli.check.allowOff`) |
| `off` | `cli.check.off` | 1 (0 with `--allow-off`, plus `cli.check.allowOff`) |
| `broken` | `cli.check.broken` with `{reason}` = `I18n::t('reason.' . $config->reason())` | 2 |
| `on` | `cli.check.ok` (`{source}`, `{user}`, `{algo}` = `algorithm()`, `{realm}`), then `cli.check.token` if `hasToken()`, then `cli.check.exempt` (`{paths}` = `implode(', ', exempt())`) if any, then `cli.check.files` (`{media}`/`{assets}` = `word.yes`/`word.no`), then `cli.check.style` (`{source}` = `Page::styleSource()`) | 0 |

When `$env['GITHUB_ACTIONS'] === 'true'`, prefix failing lines (exit 1
without `--allow-off`, exit 2) with `::error::` so GitHub annotates the run.
The configuration is loaded with the files only; `--env` lays `$env`'s
`SUDARE_*` over them and prints `cli.check.env` naming the counted variables
(a CI shell's variables are not the server's: without the flag
`SUDARE_ACTIVE=true` in CI could hide a parked file).
Never print the hash, the token hash or the file's contents. Every line goes
through `I18n::printable()` (no C0/DEL/C1 control characters: a realm or path
cannot add a line or an annotation to the log). The environment
passed to `Config::load()` is `$env` (so `SUDARE_*` from the shell counts).

### 9.3 `bin/` wrappers (architect-written, final unless the task finds a bug)

`bin/sudare-hash` → `exit(Cli::hash($argv, STDIN, STDOUT, STDERR, getenv()))`;
`bin/sudare-check` → `exit(Cli::check($argv, STDOUT, STDERR, getenv(), (string) getcwd()))`.
Both refuse to run outside the CLI (404). `ci.yml` runs both: check must exit
1 in an empty directory; hash must produce a file that `php -l` accepts and
that verifies the piped password with user `ci`.

---

## 10. Test plan

### 10.0 `tests/helpers.php` (architect)

`expect($expected, $actual, $what)`, `holds($bool, $what)`, `only($array, $keys)`,
`finish($suite)` (prints, cleans temp dirs, exits 0/1);
`sudare_tmp()` → fresh root with `site/config`, `public/media`, `public/assets`
(realpath'd, removed by `finish()`); `sudare_write($path, $content)`;
`sudare_config($root, array $config, $name = 'sudare.php')` → writes
`<?php return <var_export>;`; `sudare_hash($password = 'test-password')`
(bcrypt cost 4); `sudare_basic($user, $password, $server = [])` → `$_SERVER`
with `HTTP_AUTHORIZATION`; `sudare_php($code, $server, $env)` → runs a probe
in a new PHP after bootstrap, returns `['exit','out','err','status']`
(`status` = `http_response_code()` at shutdown; **header() is invisible in
CLI**); `sudare_free_port()`, `sudare_server($docroot, $router, $env)`,
`sudare_server_stop($server)`, `sudare_http($port, $method, $path, $headers)`
→ `['status', 'headers' (lower-case names), 'body']`, no redirects followed.

### 10.1 `tests/RequestTest.php` (config) — Request and Response

Request: method upper-cased, missing → GET, `'get'` → GET, `'FOO BAR'` → GET;
path: `/a/b?x=1#f` → `/a/b`; `//a///b/` → `/a/b`; `/a/./b/../c` → `/a/c`;
`/%2e%2e/etc/passwd` → `/etc/passwd` (decoded once, resolved: `..` at root
pops nothing); `/a%252Fb` → `/a%2Fb` (not decoded twice); `/a\0b` → `/ab`;
`''` → `/`; `/.well-known/acme-challenge/../../panel` → `/panel`; rawPath keeps
the sent form. Credentials: PHP_AUTH_USER/PW; PHP_AUTH_USER without PW →
password `''`; `HTTP_AUTHORIZATION: Basic base64("u:p")` → u/p; password
containing `:` (`u:p:q` → user `u`, password `p:q`); `basic ` lower-case
accepted; `Bearer x` → null; `Basic !!!` → null; `Basic base64("nocolon")` →
null; `REDIRECT_HTTP_AUTHORIZATION` fallback; PHP_AUTH_USER beats
HTTP_AUTHORIZATION. Token: `HTTP_X_SUDARE_TOKEN` → value; `''` → null.
`isHead/isOptions/wantsBody` for GET, HEAD, OPTIONS, POST.
Response: each factory's status/Content-Type; `header()` case-insensitive;
`withHeader` replaces regardless of case and keeps one entry; `withHeaders`
order; `withStatus`; `withoutBody` keeps headers incl. Content-Length;
`file()` Content-Length = filesize, missing file → `'0'`; `toArray()` shape;
immutability (original unchanged after `with*`).

### 10.2 `tests/ConfigTest.php` (config)

Port every case of the original guard and deploy-gate tests at the
Config level, and more:

- `load()` on an empty root → `none`, `source() === null`, `protects() === false`, `enabled() === false`.
- `sudare.php` present and valid → `on`, source `sudare.php`, user trimmed, `algorithm() === 'bcrypt'`, language `en`, realm `Preview`.
- `credentials.php` legacy valid (`vorschau.benutzer/hash/realm`) → `on`, source `credentials.php`, `language() === 'de'`, realm from file; without realm → `Vorschau`.
- both files present → `sudare.php` wins.
- legacy file plus `SUDARE_LANGUAGE=en` → `en`; plus `SUDARE_REALM` → overrides.
- broken: empty file → `notArray`; `<?php return [;` → `syntax`; `return "x"` → `notArray`;
  `return ["vorschau" => "x"]` → `user`; `echo "LEAK"; return []` → `user` and
  **nothing printed** (`ob_get_level()` unchanged, capture with `ob_start()`
  around the call and assert `''`); plain-text hash → `hash`; example hash
  (`…ERSETZEN…` and `…REPLACE…`) → `example`; user `''`, `'   '`, `'a:b'`, `"a\nb"`, non-string → `user`;
  `active => 'maybe'` → `active`; `active => 'false'` (string) → `off`; `active => false` → `off`
  (and `user()`/`hash()` null, `enabled()` false); `token => 'plain'` → `token`; `realm => 5` → `realm`;
  `exempt => 'api'` (no slash) / `['/']` / `[5]` → `exempt`; `language => 'fr'` → `language`;
  `media => 'x'` → `media`; `assets => 1` → `assets`; `theme => 'red'` / `['chrome' => 'red']` / `['foo' => '#fff']` → `theme`.
- dangling symlink as `sudare.php` → `broken`, `unreadable`; unreadable file (chmod 000, skip when running as root) → `unreadable`.
- env only: `SUDARE_USER` + `SUDARE_HASH` → `on`, source `environment`; `SUDARE_ACTIVE=off` → `off`;
  `SUDARE_EXEMPT="/api/hook, /x/"` → `['/api/hook', '/x']`; `SUDARE_THEME` ignored; unknown `SUDARE_FOO` alone → `none`.
- spellings: nested `['sudare' => [...]]` and flat `['sudare.user' => …]` → `on`; flat beats nested.
- `isExempt()`: ACME exact and sub-path true even on `none()`; `/api/hook` matches `/api/hook`, `/api/hook/1`, not `/api/hooks`, not `/api`.
- `theme()`: partial override merges with defaults, values upper-cased (`#abc` → `#ABC`), 8-digit accepted.
- `option('hash')` and `option('token')` → null even when set; `option('realm')` → raw value; `option('nope', 'd')` → `'d'`.
- `toArray()` exact shape for an `on` config with token and exempt; contains no `$2y$`.
- `isLegacy()`/`fromLegacy()` directly; a credentials.php with English keys is read as new (language `en`).
- `examples/sudare.php` loads to `broken`/`example`; `examples/credentials.php` loads to `broken` (`example` or `hash`) — the templates must never validate.

### 10.3 `tests/GuardTest.php` (guard) — pure `Guard::handle()`

Fixtures: `$on = Config::fromArray(['user' => 'test', 'hash' => sudare_hash(), 'token' => sudare_hash('tok'), 'exempt' => ['/api/hook']])`,
`$legacy` (language de), `$off`, `$broken` (`['user' => 'x']`), `Config::none()`.
Request via `Request::fromServer([...])`.

| Case | Expect |
|---|---|
| none, GET `/` | passes, headers `[]` |
| off, GET `/panel` | passes, headers `[]` |
| broken, GET `/` | 503, body === `Page::unavailable($broken)`, headers X-Robots-Tag/Cache-Control/Vary/Retry-After, Content-Type text/plain, body contains no `$2y$`, no 'hash', no path |
| broken, GET `/.well-known/acme-challenge/abc` | passes with the three headers |
| broken, HEAD `/` | 503 (body stripping is Sudare's job; here body may be present) |
| on, GET `/` no credentials | 401, WWW-Authenticate `Basic realm="Preview", charset="UTF-8"`, three headers, Content-Type text/html, body === `Page::denied($on)` |
| on, wrong password | 401 |
| on, right password wrong user (`Test`) | 401 (case-sensitive) |
| on, right credentials via PHP_AUTH_* | passes, headers = `Guard::headers()` exactly |
| on, right credentials via HTTP_AUTHORIZATION | passes |
| on, `Basic !!!` | 401 |
| on, `/panel`, `/panel/site`, `/api/system`, `/media/pages/x/y.jpg`, `/assets/css/x.css`, `/robots.txt`, `/sitemap.xml` without credentials | all 401 (no path is special) |
| on, same paths with credentials | all pass |
| on, GET `/api/hook` and `/api/hook/x` | pass with headers; `/api/hooks` → 401 |
| on, `/.well-known/acme-challenge/x` | pass; `/.well-known/other` → 401 |
| on, OPTIONS `/` no credentials | 204, Allow header, three headers, body `''` |
| on, token header right | pass; wrong token → 401; right token but config without token → 401 |
| on, legacy (`de`) 401 | WWW-Authenticate realm `Vorschau`, body contains `Diese Seite ist noch nicht öffentlich` and `lang="de"` |
| realm `He said "hi"\back` | header `Basic realm="He said hiback", charset="UTF-8"` |
| `sameUser` | equal → true; different lengths → false; `'a'` vs `'a\0'` → false |
| no `Location` header in any response of this file | holds |
| `Guard::headers()` exact array | expect |

Also: after `handle($legacy, …)` then `handle($on, …)`, the English page is
English again (language set per call).

### 10.4 `tests/PageTest.php` (guard)

`denied()`: starts with `<!doctype html>`; contains `lang="en"`/`lang="de"`,
`<meta name="viewport"`, `<meta name="robots" content="noindex, nofollow, noarchive">`,
`theme-color" content="#1E3648"` (default) and the custom chrome when themed;
heading and text in both languages; does not contain the realm, the user,
`<script`, `http://`, `https://`; theme value `#ABC` appears as given;
`htmlspecialchars` applied (feed a Config whose language text would contain
`<` — not possible via I18n, so test escaping through `realmHeader` and by
asserting the fixed texts contain no raw `&`-breaking characters). `unavailable()`
= translated sentence + `"\n"`, both languages. `realmHeader()`: plain;
quotes/backslashes/CR/LF removed; empty → `Preview`; UTF-8 kept (`Vorschau — Demo`).

### 10.5 `tests/MediaTest.php` (media) — port of the original media tests + assets

Root via `sudare_tmp()`; `$public = $root . '/public'`. Write files, call
`Media::resolve()` and `Media::respond()` (with `Request::fromServer(['REQUEST_URI' => …])`
and `$on`/`Config::none()`):

| Stored under public/ | Requested | Served? |
|---|---|---|
| `media/panel/abc/index.min.js` | same | yes, `text/javascript` |
| `media/panel/abc/style.min.css` | same | yes, `text/css` |
| `media/panel/abc/font.woff2` | same | yes, `font/woff2` |
| `media/panel/abc/favicon.ico` | same | yes |
| `media/plugins/index.js` | same | yes |
| `media/panel/abc/evil.php` / `.phtml` / `noext` | same | no |
| `media/pages/p/upload.js` / `.json` / `.css` | same | no |
| `media/pages/p/detour.js` | `/media/panel/abc/../../pages/p/detour.js` | no |
| `media/pages/p/encoded.js` | `/media/panel/abc/%2e%2e/%2e%2e/pages/p/encoded.js` | no |
| `media/pages/p/image.webp` | same | yes, `image/webp` |
| `media/pages/p/doc.pdf`, `.svg`, `.avif`, `.JPG` (upper-case ext) | same | yes |
| `media/pages/p/clip.mp4` | same | no (Kirby serves it) |
| `assets/css/site.css`, `assets/js/app.mjs`, `assets/fonts/x.woff2`, `assets/img/logo.svg`, `assets/site.webmanifest`, `assets/video/x.mp4` | same | yes |
| `assets/secret.php`, `assets/.env`, `assets/dir/` (directory) | same | no |
| `/media/../site/config/sudare.php` | — | no |
| symlink `media/pages/p/link.png` → `/etc/hosts` | same | no (realpath leaves root) |
| `/media/pages/p/image.webp?v=2` | query stripped by Request | yes |
| `/other/x.png` | — | null |
| `$media = false` → `/media/...` null; `$assets = false` → `/assets/...` null | | |

Headers (`respond()`): protected → `Cache-Control: private, max-age=31536000`,
`X-Robots-Tag`, `Vary: Authorization, X-Sudare-Token`, `X-Content-Type-Options: nosniff`,
`Content-Length` = byte count; `none` → `public, max-age=31536000`, no
X-Robots-Tag, no Vary. `filePath()` is the realpath. Also one subprocess
check with `sudare_php()`: `Sudare::guard()` on `none` state + `/media/pages/p/image.webp`
prints the file bytes and nothing else, exit 0, status 200.

### 10.6 `tests/CliTest.php` (cli)

Use `fopen('php://memory', 'w+')` streams (write input, rewind) for `$in`,
`$out`, `$err`. Cases:

hash: pipe `correct horse\n` → exit 0, `$out` starts with `<?php\n\nreturn [`,
`php -l`-clean (write to temp and `sudare_php`? simpler: `eval`-free check by
requiring the written temp file and `password_verify`), `user` default
`preview`, `--user=client` honoured, `--realm=X` present, `--token` → `$err`
contains a 48-hex token and `$out` contains `'token' =>` whose hash verifies
that token; `--legacy` → `['vorschau' => ['benutzer' => 'preview', 'hash' =>`;
`--legacy --token` → exit 3; empty input → exit 1 + `cli.hash.empty`; `short\n`
→ exit 1 + "shorter than 8"; `--language=de` → German prompt on `$err`;
`--help` → exit 0 + usage on `$out`; `--bogus` → exit 3; `$err` never contains
the password; `$out` never contains the password; a password with `$`, `'`,
`\` and umlauts roundtrips (`var_export` escaping).

check: empty root → exit 1, `cli.check.none` with the path; `--allow-off` →
exit 0 + allowOff line; valid `sudare.php` → exit 0, ok line contains
`sudare.php`, `test`, `bcrypt`, `Preview`; with token → token line; with exempt
→ exempt line `/api/hook`; files line `yes`/`no`; legacy file → ok line with
`credentials.php` and German text (language from config) unless
`--language=en`; broken file (plain hash) → exit 2 + reason text, and `$out`+`$err`
contain neither the plain "password" value nor `$2y$`; `active => false` → exit 1,
`--allow-off` → 0; env `SUDARE_USER/HASH` with empty root → exit 0 source
`environment`; file path argument instead of root; relative path resolved
against `$cwd`; `GITHUB_ACTIONS=true` → `::error::` prefix on exit 1/2 lines
and absent on exit 0; `--help` → 0; two positionals → exit 3.
`arguments()`: `['bin', '--a=1', '--b', 'x', 'y']` → `['a' => '1', 'b' => true, '_' => ['x', 'y']]`.

Plus a real run of both `bin/` scripts with `proc_open` (PHP_BINARY) to prove
the wrappers wire up (`bin/sudare-check <empty tmp>` exit 1; `printf pw | bin/sudare-hash --user=ci` exit 0).

### 10.7 `tests/SudareTest.php` (docs-http) — the facade in subprocesses

With `sudare_php()` (header() invisible, so check exit/status/body):
- no config, GET `/` → probe continues (`echo "KIRBY"` visible), exit 0, status 200, `Sudare::active()` false.
- valid config, no credentials → status 401, body contains `not public yet`, `KIRBY` absent, exit 0.
- valid config, wrong password → 401. Right credentials → `KIRBY` present, `active()` **true** (echo it), status 200.
- legacy credentials.php, right credentials → `KIRBY`; wrong → 401 with German body.
- broken config (every reason of §10.2 once: empty, syntax, string, nested wrong type, plain hash, output in file) → 503, body === unavailable text, `KIRBY` absent, and **the file's output (`LEAK`) is not in stdout**.
- HEAD on 401 → status 401, body `''`. OPTIONS → 204, body `''`.
- token header right → `KIRBY`.
- exempt path → `KIRBY` without credentials.
- media file under protection without credentials → 401; with credentials → file bytes, `KIRBY` absent.
- env-only configuration (`SUDARE_USER`, `SUDARE_HASH` passed as `$env`) → 401 without, pass with credentials.
- `Sudare::handle()` pure: returns Response, no output, `Sudare::config()` set, `reset()` clears.
- `sudare_option('user')` → user after guard; `sudare_option('hash')` → null; before guard → default.
- `Sudare::protects($root)` true/false without state.
- `Sudare::translations()['de']['sudare.realm'] === 'Vorschau'`.
- `Sudare::sealed()` pure (§6.1), with `Guard::headers()` as `$protective`:
  `Cache-Control` and `X-Robots-Tag` take the protective value over what was
  sent (names compare case-insensitively, the result keeps the protective
  spelling); `Vary` merges — sent `Cookie` gives `Cookie, Authorization,
  X-Sudare-Token`; no sent `Vary` gives the protective one; a sent
  `authorization` is not repeated and keeps its spelling; two sent `Vary`
  lines merge in order; values are trimmed and empty ones dropped; `*` wins
  (sent or protective); a header that is not protective is not in the result;
  empty `$sent` gives the protective headers unchanged. `seal()` itself is not
  visible here (`header()` and `headers_list()` do nothing in the CLI): it is
  proved in §10.8 and §10.9.

### 10.8 `tests/HttpTest.php` (docs-http) — PHP built-in server, real headers

Fixture: `$root = sudare_tmp()`; router `$root/public/index.php`:

```php
<?php
// cli-server: let real files under /assets through only when the test says so (we want them via Sudare) — so: never return false here.
$base = dirname(__DIR__);
require '<abs path to package>/bootstrap.php';
Sudare\Sudare::guard($base);
if (($_GET['override'] ?? '') === '1') {   // what Kirby or a plugin route does after guard()
    header('Cache-Control: public, max-age=2592000');
    header('Vary: Cookie');
    header('X-Robots-Tag: all');
}
header('X-Kirby: yes');              // stands in for Kirby
echo 'KIRBY ', Sudare\Sudare::active() ? 'active' : 'inactive';
```

Start with `sudare_server($root . '/public', $root . '/public/index.php', $env)`.
Scenarios (restart the server per config, or write the config file between
requests — the file is read per request):

1. No config: GET `/` → 200, body `KIRBY inactive`, **no** `x-robots-tag`, no `vary`.
2. Valid config: GET `/` → 401, `www-authenticate` = `Basic realm="Preview", charset="UTF-8"`,
   `x-robots-tag` = `noindex, nofollow, noarchive`, `cache-control` = `private, no-store`,
   `vary` = `Authorization, X-Sudare-Token`, `content-type` starts `text/html`, body contains `not public yet`, no `KIRBY`.
3. GET `/` with `Authorization: Basic …` right → 200, body `KIRBY active`, `x-kirby: yes`,
   and the three protective headers present; wrong → 401.
4. HEAD `/` → 401, headers present, body `''`. OPTIONS `/` → 204, `allow` present.
5. `/panel`, `/api/system` → 401 without, 200 with credentials.
6. Media: write `public/media/pages/p/img.webp` (bytes `RIFFTEST`) → without credentials 401 (body is the 401 HTML, not the bytes);
   with credentials 200, `content-type: image/webp`, `content-length: 8`, body bytes, `cache-control: private, max-age=31536000`, `x-content-type-options: nosniff`.
   `public/media/pages/p/upload.js` with credentials → body `KIRBY active` (passed to "Kirby"), not the file.
   `public/assets/css/site.css` with credentials → `text/css`.
   HEAD on the webp → 200, `content-length: 8`, body `''`.
7. Token: `X-Sudare-Token: <plain>` → 200 `KIRBY active`; wrong token → 401.
8. Exempt `/api/hook` → 200 without credentials, with `x-robots-tag`.
8a. The seal, `/?override=1`: with Basic Auth, with a valid token, and on the exempt path
   without credentials → 200, body `KIRBY active`, `x-kirby: yes`, `cache-control` =
   `private, no-store`, `vary` = `Cookie, Authorization, X-Sudare-Token`, `x-robots-tag` =
   `noindex, nofollow, noarchive` (the router's values are replaced; `sudare_http()` joins
   repeated headers with ", "). Without credentials → 401, the router not reached. With
   state `off` (`active => false`) and with no configuration → 200, `KIRBY inactive`,
   the router's own `public, max-age=2592000`, `Cookie` and `all`: no seal.
9. ACME: a token file → 200 with its content, a missing one → empty 404, both without credentials and without reaching "Kirby"; `/panel/../.well-known/acme-challenge/x` → 401. Exempt paths only as sent: `/panel/../api/hook`, `/api//hook`, `/api%2Fhook`, `/%61pi/hook` → 401.
10. Broken config (plain hash) → 503 on `/`, `/panel`, `/media/pages/p/img.webp`; `retry-after: 300`; body is the one sentence; the ACME file still 200, a missing one 404. A file that calls `exit` → 503, its output never sent.
10a. Ranges on an asset: `bytes=0-1` → 206 + `content-range`, `bytes=10-` on 10 bytes → 416, `If-Range` → whole file.
11. Env-only config: start the server with `SUDARE_USER`/`SUDARE_HASH` in `$env`, no file → 401/200.
12. No `location` header in any response of this file.
13. Legacy `credentials.php` → realm `Vorschau`, German body.

Stop the server in a `finally`/at the end; print its log on failure.

### 10.9 `tests/KirbyHttpTest.php` (docs-http) — the same, with a real Kirby

Without `vendor/autoload.php`, or when `Kirby\Cms\App` does not exist after
requiring it, the file prints `skipped: Kirby not installed` and exits 0 (CI
runs `composer install` first). Otherwise: a temp project with `content/site.txt`,
`content/home/home.txt` (`Title: Home`) and `site/templates/default.php`; a router
`public/index.php` that sets `SCRIPT_NAME`/`SCRIPT_FILENAME`/`PHP_SELF` (as
`examples/index.php` does), requires `vendor/autoload.php`, calls
`Sudare\Sudare::guard($base)` and echoes `(new Kirby\Cms\App([...]))->render()`
with a route `thumb` that answers `new Kirby\Http\Response('PNGBYTES',
'image/png', 200, ['Cache-Control' => 'public, max-age=2592000',
'X-Content-Type-Options' => 'nosniff'])` — a thumbnail route. Scenarios:

1. No configuration: `/thumb` → 200, `PNGBYTES`, `cache-control` = `public, max-age=2592000`,
   no `x-robots-tag`; `/` renders the home page.
2. Valid configuration with a token: anonymous `/thumb` → 401, no `PNGBYTES`; a wrong token → 401.
3. `X-Sudare-Token` right, `/thumb` → 200, `PNGBYTES`, `content-type` starts `image/png`,
   `cache-control` = `private, no-store`, `vary` contains `Authorization` and `X-Sudare-Token`,
   `x-robots-tag` = `noindex, nofollow, noarchive`, `x-content-type-options` = `nosniff`
   (Kirby's own header survives the seal).
4. Basic Auth `/` → 200 with the page title and the sealed headers.
5. The configuration file removed → `/thumb` → 200 with `public, max-age=2592000` again, no `x-robots-tag`.

Stop the server in a `finally`.

---

## 11. Documentation (task docs-http)

### 11.1 `README.md` (English) — sections in this order

Title + one-paragraph meaning of the name and what the package does; **Features** (Basic Auth before Kirby, noindex everywhere, media and assets through PHP, fail closed, machine token, exempt paths, hash and check CLIs, EN/DE, no cookies, storage or third parties); **Requirements** (PHP 8.2–8.4 with mbstring, Kirby on the site but not required by the package, host root only); **Installation** (`composer require sayamaapps/kirby-sudare`, vcs repository until Packagist, **type library: lands in `vendor/`, not `site/plugins/`**); **Setup** (1. `public/index.php` — the `examples/index.php` block with `require vendor/autoload.php` first, then `Sudare\Sudare::guard($base)`, then Kirby; 2. `.htaccess` — the `media/` and `assets/` rewrites, keep `CGIPassAuth On` + `SetEnvIf Authorization`; 3. config with `vendor/bin/sudare-hash`, kept out of Git; 4. deploy: secret `SUDARE_PHP` → `site/config/sudare.php`, `vendor/bin/sudare-check` as a gate before upload, `--allow-off` on the public deploy, and a media file requested without credentials must answer 401); **Usage** (check order, states §4.2, HTTPS, media and assets (§8 allowlists), local development); **Machine token**; **Exempt paths**; **Calls** (`Sudare::guard()`, `active()`, `protects()`, `config()`, `translations()`, `sudare_option()`); **Translations**; **Options** (the three sources and their spellings, table §4.3, `theme`, the CSP hash of the 401 page); **Privacy** (no visitor data); **Maintenance** (`composer test`, contributions, security reports through GitHub, CHANGELOG link); **License**.

### 11.2 `CHANGELOG.md`

CHANGELOG: Keep a Changelog, SemVer, `## [1.0.0] — unreleased`, with the
project rule "a change to a header or to the default of `active`/`media`/
`assets` is major" and a **before → now** table
(`vorschauschutz($basis)` → `Sudare::guard($base)`, `vorschauMedien()` →
built in, `vorschauAbweisen()` → `Page::denied()`, `vorschauAktiv()` →
`Sudare::active()`/`protects()`, `credentials.php` `vorschau.benutzer` → still
read / `sudare.php` `user`, `hash-erzeugen.php` → `sudare-hash`,
`pruefe-zugangsdaten.php` → `sudare-check`), "Changed behaviour" (headers on
every response incl. pass and media; `Vary: Authorization, X-Sudare-Token`; HEAD/OPTIONS
handled; assets served; token; exempt; 401 page gets viewport + theme-color +
`<meta name=robots>`; 503 gets Retry-After; example-hash check and token
check; language English by default, German for the legacy file) and
"Unchanged" (file presence is the switch; same 401 texts in German; same
media allowlist; same status codes 200/401/503).

No privacy text: Sudare processes no visitor data. The README's Privacy section says so.

### 11.3 `examples/index.php`, `examples/htaccess.txt`

`examples/index.php`: the default `public/index.php` rewritten for Sudare
(English comments), including the `cli-server` branch, adjusted so
`/assets/` is **not** returned to the built-in server when Sudare is meant to
serve it (comment both options). `examples/htaccess.txt`: only the lines a
site adds/keeps: `RewriteRule ^media/(.*) index.php [NC,L]`,
`RewriteRule ^assets/(.*) index.php [NC,L]` (without `[NC]` Apache
on a case-insensitive file system serves `Media/…` past the password),
`SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1`, `CGIPassAuth On`,
`SetEnv ap_trust_cgilike_cl 1`, each with a comment.

---

## 12. Deviations from the plugin family (documented here, repeated in README)

| Convention | Sudare | Why |
|---|---|---|
| `type: kirby-plugin`, `getkirby/composer-installer`, `installer-name`, `App::plugin()` | `type: library`, lands in `vendor/`, `bootstrap.php` instead of `index.php`, no `App::plugin()` | runs before Kirby boots; a plugin loads too late to protect the Panel, the API and media files |
| Options via Kirby `option()` with defaults at call sites | `site/config/sudare.php` + legacy `credentials.php` + `SUDARE_*` env, read by `Options`/`Config`; defaults in `Config` | there is no Kirby yet; the config file is the package's own, so bare keys are allowed as a third spelling |
| `'<name>.active' => true` needed | `active` defaults to true when a source exists; `false` switches off | the switch has always been "the file exists" (deploy writes it, public deploy removes it); the legacy file has no `active` key |
| Translations registered as Kirby translations | `Sudare::translations()` returns the prefixed tables for a site plugin | cannot register before Kirby exists |
| Unstyled snippets + array methods | one inline-styled 401 page with a `theme` option | there is no template engine before Kirby; the page must be self-contained |
| `<name>_option()` reads Kirby | `sudare_option()` reads the loaded Config (non-secret keys) | same |
| Kirby-booting tests with `vendor/` | one Kirby test, `KirbyHttpTest`, skipped without `vendor/`; everything else uses PHP's built-in server with a stand-in for Kirby | Kirby is irrelevant to the decision; it matters for the seal (§6.1), which has to hold against Kirby's own `Response::send()`. getkirby/cms stays a dev dependency only |

Everything else follows the family: composer metadata, MIT, author, support
URLs, `scripts.test`, `config.platform.php 8.2.0`, namespace = PascalCase
name, `src/I18n.php` + `translations/`, `README/CHANGELOG`,
standalone tests + `run.sh`, `ci.yml` with the 8.2–8.4 matrix and the
fork-PR guard, `.gitignore` with `vendor/`.

---

## 13. Decisions and their reasons

1. **Composer `library`, not a Kirby plugin.** Loaded via `autoload.files`
   (bootstrap.php) — no autoloader magic, same `require_once` style as the
   plugins. A site calls `require vendor/autoload.php; Sudare\Sudare::guard($base);`
   before `new App`. Alternative considered: a Kirby plugin with a
   `route:before` hook — rejected, Kirby's Panel/API/media routes and error
   pages would already be initialised and Panel assets would need Kirby to
   boot for every request.
2. **Three config sources** (sudare.php, legacy credentials.php, `SUDARE_*`),
   file first, env laid over per key. Existing `CREDENTIALS_PHP`
   secrets keep working unchanged. New sites use `SUDARE_PHP` →
   `site/config/sudare.php`. Env alone also works (hosting panel or Apache `SetEnv`).
3. **The switch is the presence of a source**, not `active => true`
   (deviation from the family). `active => false` exists for parking a file.
   Danger acknowledged: a typo'd `active` is `broken` (503), never open.
4. **Legacy file → German texts by default.** The legacy files belong to German-language
   sites and would otherwise show an English 401 page the day they switch.
   New-format files default to English like every family member.
5. **Fail closed includes the example hash and a malformed token.** A
   `sudare.php` copied from `examples/` is 503, not open.
6. **ACME challenge path passes even while broken.** Certificate renewal
   must not break because a secret was mistyped. Usually the web server
   serves the file before PHP. When it reaches PHP, Sudare answers it itself
   (the file as text, else an empty 404) — handing it to Kirby would show
   the unlaunched site's error page (navigation, footer) to anyone.
7. **OPTIONS answered with 204 without credentials.** Reveals nothing;
   lets preflight-style probes and monitoring work. `Allow` lists the usual
   methods.
8. **Media and assets are served whenever the request reaches PHP,
   protected or not** (as the original did for media). Cache header flips
   between `private` and `public`. `media`/`assets` options exist to hand
   serving back to Kirby.
9. **Media allowlist unchanged** (images + PDF everywhere; JS/CSS/fonts only
   under `media/panel` and `media/plugins`); **assets get a wider list**
   (code-shipped directory, never uploads). Video/audio under `media/` still
   fall through to Kirby.
10. **Token is stored hashed**, verified with `password_verify`; the plain
    token is printed once by `sudare-hash --token`. Header name
    `X-Sudare-Token`. Smoke tests can use it instead of the Basic password.
11. **`Vary: Authorization, X-Sudare-Token` + `Cache-Control: private, no-store`
    on pages, `private, max-age=1y` on files** — a reviewer's browser may cache
    hashed media URLs; shared caches may not. `Vary` names the token header
    too: a token-authenticated answer and an anonymous one are
    different cache variants.
12. **Minimum password length stays 8** (as the original). Raise to 12?
13. **User names are compared in constant time via SHA-256 digests**
    (`hash_equals` on equal-length digests), and user+password checks are
    both always evaluated.
14. **One Kirby test.** `HttpTest` runs PHP's built-in server with a two-line
    stand-in for Kirby; `KirbyHttpTest` lets a real Kirby answer,
    because the seal has to hold against Kirby's own `Response::send()`.
    Kirby remains a dev dependency (family convention).
15. **The package does not touch any site.** The migration of a site
    (index.php, .htaccess assets line, deleting vorschauschutz.php and the two
    scripts, Annai's noindex hook, a site's `kizami.previewAccess`) is a separate
    rollout step, not part of the package.
16. **Secret name `SUDARE_PHP`** recommended for new deploys; the existing
    `CREDENTIALS_PHP` continues to work with the legacy file name.
17. **The protective headers of a pass are sealed.** Sending them
    before Kirby is not enough: Kirby's `Response::send()` replaces headers by
    name, so a plugin route's `Cache-Control: public, max-age=…` would leave
    the preview cacheable. `header_register_callback()` runs right before PHP
    sends the headers and sets them again (§6.1). Cost: PHP keeps one such
    callback per request, so a site that registers its own removes the seal.
