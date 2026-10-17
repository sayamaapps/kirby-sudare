# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to a protective header** (`X-Robots-Tag`, `Cache-Control`, `Vary`)
  **or to the default of `active`, `media` or `assets`** is a **major** version.
  A site would be indexed, cached or unprotected after a routine update.
- **A change to the status codes** of the decision table (401, 503, 204, 200)
  or to the allowlists of `Media` is a **major** version as well.
- **A change to the privacy text** (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the package must then review its privacy
  policy.

## [2.1.1] — 2026-10-17

### Fixed

- **A damaged `$2y$` hash is a broken configuration, not a working one.** A
  `$2y$` hash damaged when the configuration was edited or copied (a cost
  outside 04–31, a character outside the bcrypt alphabet) was accepted as
  valid, because `password_get_info()` checks only the prefix and the length
  of a bcrypt hash. The preview was `on`, `sudare-check` exited 0, the deploy
  gate let it through, and every login answered 401. Now `Config::isHash()`
  checks every bcrypt prefix (`$2a$`, `$2b$`, `$2x$`, `$2y$`) for the full
  shape and an Argon2 hash for its encoded form, by syntax only, before
  anything is verified. Such a configuration is `broken` (503,
  `sudare-check` exit 2, reason `hash` or `token`), as the same damage with a
  `$2a$` or `$2b$` prefix already was. The reason texts for `hash` and `token`
  now say "not a complete password hash" instead of "not a hash PHP
  recognises", which was exactly the misleading part. Valid hashes are
  unchanged.

## [2.1.0] — 2026-10-17

### Fixed

- **The protective headers are sealed when PHP sends the response.** Sudare
  sent `Cache-Control: private, no-store` before Kirby ran, and Kirby's own
  response then replaced it by name: a plugin route that answers with
  `Cache-Control: public, max-age=2592000` (a thumbnail route, say) left the
  preview with a public cache header, and a shared cache in front of it served
  the protected bytes to anonymous requests. `X-Robots-Tag` could be replaced
  the same way. Now `guard()` registers `header_register_callback()`, and
  `Sudare::seal()` sets `Cache-Control` and `X-Robots-Tag` again right before
  PHP sends the headers, whatever Kirby or a plugin set in between. `Vary` is
  merged, not replaced: the Panel's `Vary: Cookie` becomes
  `Vary: Cookie, Authorization, X-Sudare-Token`. Sudare's own answers (401,
  503, 204, files) and a site without a configuration are unchanged. PHP keeps
  one such callback per request: a site or plugin that registers its own
  replaces the seal and has to set the headers itself.
- **`Vary` names `X-Sudare-Token` as well** (`Vary: Authorization,
  X-Sudare-Token`). A request authenticated by the token header was the same
  cache variant as an anonymous one; now a token-authenticated answer and an
  anonymous one are different variants.

The value of a protective header changes (`Vary` gains a value), which the
rules above call a major version. This is a minor version on purpose: nothing
is weakened, and sites pin `^2.0`, so a 3.0.0 would not reach them with a
routine update.

## [2.0.0] — 2026-10-17

### Changed — protective header (major by the rules above)

- **No `Cache-Control` for `assets/` on the open site** (`none`, `off`). Sudare
  sent `public, no-cache`, which overruled the server's own caching rules
  (`mod_expires`, versioned URLs) for every live site that keeps the
  `assets/` rewrite: the browser re-requested CSS, JavaScript, fonts and
  images on every page view and got a 304 each time. Now the server decides,
  as if the rewrite were not there. Protected sites are unchanged
  (`private, no-cache`), `media/` is unchanged (a year, the URLs are hashed).
  After updating, an asset under `assets/` whose URL carries no version may
  stay in browsers for as long as the server's rules allow; give such URLs a
  version (`?v=…`) or a shorter `ExpiresByType`.

## [1.0.0] — 2026-10-12

First release as a Composer package. Before 1.0.0 the preview guard lived as a
copy in each site: `site/config/vorschauschutz.php` (byte-identical in
kirby-starter and six client sites), a deviating copy in one client site (themed
401 page, `vorschauAktiv()`), and the scripts `scripts/hash-erzeugen.php` and
`scripts/pruefe-zugangsdaten.php` of kirby-starter. The changes below are
measured against kirby-starter's copy.

### Fixed — against the copy

- A configuration behind a symlink that `open_basedir` does not allow (a shared config of Deployer/Capistrano) counted as absent and opened the site; it is now broken (503). Filesystem checks (configuration and served files) run under the package's own error handler: no `open_basedir` warning reaches the output, and a site's handler that throws on warnings cannot turn one into a 500.
- A conditional request with the ETag of a compressed answer (`-gzip`/`-br` from mod_deflate/mod_brotli) now gets its 304.

### Changed — renamed and restructured (breaking)

A site switching from the copy updates its `public/index.php` once, using the
table below. The legacy `credentials.php` keeps working without changes.

| Before (`vorschauschutz.php` copy) | Now (Sudare) |
|---|---|
| `vorschauschutz($basis)` | `Sudare\Sudare::guard($base)` |
| `vorschauMedien($basis)` | built into `guard()`: `media/` and `assets/` are served through PHP |
| `vorschauAbweisen()` | `Page::denied()`, called by `guard()` |
| `vorschauAktiv($basis)` (deviating copy) | `Sudare\Sudare::active()` after `guard()`, or `Sudare\Sudare::protects($base)` anywhere |
| `site/config/credentials.php` with `vorschau.benutzer`, `vorschau.hash`, `vorschau.realm` | still read; the same file as `site/config/sudare.php` with `user`, `hash`, `realm` |
| `scripts/hash-erzeugen.php` | `vendor/bin/sudare-hash` |
| `scripts/pruefe-zugangsdaten.php` | `vendor/bin/sudare-check` |
| `CREDENTIALS_PHP` (secret name) | `SUDARE_PHP` recommended for new deploys; `CREDENTIALS_PHP` keeps working |
| `tests/VorschauschutzTest.php`, `tests/PanelMedienTest.php` | `tests/` of the package (`GuardTest`, `MediaTest`, `SudareTest`, `HttpTest` and the others) |

### Changed behaviour

- **Protective headers on every response** while protected: `X-Robots-Tag: noindex, nofollow, noarchive` and `Cache-Control: private, no-store` (both already sent by the starter's copy), now also on the 503, on OPTIONS and on every page that passes.
- **`Vary: Authorization`** is new. It is sent on every protected response, so a shared cache does not hand a page to a visitor who has no password.
- **HEAD and OPTIONS are handled.** HEAD gets the status and headers of GET without a body. OPTIONS gets `204` with an `Allow` header and needs no credentials.
- **`media/` and `assets/` through PHP**, protected or not (as the starter did for `media/` only). `assets/` is new as an option; its allowlist is wider than `media/` (code and fonts shipped with the site). Uploads under `media/pages/` still never serve scripts.
- **Machine token**: `X-Sudare-Token` with a hashed token, checked with `password_verify()`. New.
- **Exempt paths**: configurable list of `[METHOD ]/path[/*]` entries, exact by default; `/.well-known/acme-challenge` is always exempt, also while the configuration is broken. New. Both count only for the path as sent: `/panel/../api/hook` or `/%61pi/hook` is not exempt, because Kirby would route it to the Panel or the site's error page. Entries contain only letters, digits, `-`, `_`, `.` and `/` (a `:` segment is a Kirby URL parameter: `/lang:de/*` would have opened the Panel). An entry with a method needs it as routed and as sent (no `X-HTTP-Method-Override` either way); `HEAD` entries need an exact `HEAD`; `OPTIONS` entries are refused. An ACME request that reaches PHP is answered by Sudare (the challenge file, or an empty 404), never by Kirby's error page.
- **Byte ranges** for files under `media/` and `assets/`: `Accept-Ranges: bytes`, `206` for one range, `416` beyond the file, `If-Range` compared with the file's validators. Safari and iOS need it for video and audio. New.
- **Cache headers of files**: `media/` keeps a year (Kirby hashes those URLs); `assets/` gets `no-cache` with `ETag` and `Last-Modified` and answers `304`, because its URLs stay the same after a deploy. Files answer `GET` and `HEAD` only. Large files are streamed past any output buffer. New.
- **Options**: `null` and `''` both mean "not set"; `media` and `assets` count while switched off; English keys next to `vorschau` in a legacy `credentials.php` count (`'active' => false` parks it).
- **`sudare-hash`** refuses passwords over 72 bytes or with a NUL byte (bcrypt would ignore the rest).
- **The 401 page** gets a viewport, a `theme-color` (configurable with `theme`) and `<meta name="robots">`. It is one self-contained document with a translated `lang`.
- **The 503 answer** carries `Retry-After: 300`. It is returned for every request while broken, also for `/panel`, the API and media files.
- **Fail closed** is stricter: a configuration that exists and does not validate is 503. New checks: the example hash from the templates (`ERSETZEN`, `REPLACE`), a malformed token, an `active` value that is not a boolean, a `theme` with a non-hex colour, output printed by the configuration file (captured, never sent), a file that ends the script (`exit`, `die`, a fatal error: 503, and exit 2 in `sudare-check`), and a `site/` or `site/config/` that is a dangling link or not searchable for PHP.
- **Hashes are verified with `password_get_info()`**, not by a prefix test. Argon2 hashes are accepted.
- **User names are compared in constant time** (SHA-256 digests), and the password is always verified, also for a wrong user.
- **Language**: English by default; the legacy file keeps German (`de`) because all six legacy sites are German.
- **No `Location` header anywhere.** The package never redirects.

### Unchanged

- The switch is the presence of the file: `site/config/credentials.php` (or `sudare.php`) present means protected, as before. `active => false` parks it (new option, same effect as removing the file).
- The same 401 texts in German for the legacy file: "Diese Seite ist noch nicht öffentlich" and the realm `Vorschau`.
- The same media allowlist for `media/`: images and PDF everywhere, JavaScript, CSS and fonts only under `media/panel` and `media/plugins`. Video and audio under `media/` still go to Kirby.
- The same status codes: 200 (through), 401 (no or wrong credentials), 503 (broken).
- The same password minimum: 8 characters in `sudare-hash`.
- Basic Auth with bcrypt hashes from `password_hash()`; no cookies, no sessions, no database.

### Added

- Package `sayamaapps/kirby-sudare`, Composer type `library`, PHP namespace `Sudare\`, option prefix `sudare.`, environment prefix `SUDARE_`.
- `Sudare\Sudare` facade: `guard()`, `handle()`, `emit()`, `active()`, `config()`, `protects()`, `environment()`, `translations()`, `reset()`, and the global helper `sudare_option()`.
- Three configuration sources, file first, environment laid over per key: `site/config/sudare.php`, the legacy `site/config/credentials.php`, `SUDARE_*` variables. Three spellings per option: bare, nested, flat.
- States `none`, `off`, `broken`, `on` and reason codes for a broken configuration, shown by `sudare-check`.
- `vendor/bin/sudare-hash`: password prompt without echo, bcrypt hash with cost 10 (`--cost=4-31`; PHP's own default of 12 since 8.4 costs ~250 ms per authenticated file request), optional machine token, `--legacy` format, `--language`. A configuration may still hold any hash `password_get_info()` knows, Argon2 included.
- `vendor/bin/sudare-check`: validates a configuration or a project root (files only; `--env` adds the shell's `SUDARE_*`), `--allow-off` for the public deploy, `::error::` annotations under GitHub Actions, and the CSP hash of the 401 page's style for sites with `style-src 'self'`.
- English and German texts (`translations/en.php`, `translations/de.php`), `Sudare::translations()` for a site plugin.
- Examples: `examples/index.php`, `examples/htaccess.txt`, `examples/sudare.php`, `examples/credentials.php`.
- Standalone PHP test suite (`composer test`), including real HTTP tests through PHP's built-in server.
- The technical privacy description in English (`PRIVACY.md`) and German (`DATENSCHUTZ.md`).
