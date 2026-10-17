# Sudare

Preview protection for [Kirby CMS](https://getkirby.com) sites before launch: HTTP Basic Auth in front of Kirby, `noindex` on every response, and the files under `media/` and `assets/` served through PHP, so they sit behind the same password. Sudare runs before Kirby boots.

*Sudare* (簾) is the bamboo blind of a Japanese house: people inside can see out, people outside cannot see in. It is rolled up on launch day. The package does the same job for a site that is not public yet: the visitors outside see a closed page, and the reviewers inside see the site.

- **Basic Auth before Kirby.** The check runs in `public/index.php`, before `new Kirby\Cms\App`. There is no route past it: not the Panel, not the API, not an error page.
- **`noindex` on every response.** `X-Robots-Tag: noindex, nofollow, noarchive` on pages, files, the 401 and the 503, so search engines do not index the preview.
- **Media and assets through PHP.** Generated images, uploads and the site's CSS, JavaScript and fonts are served by Sudare, behind the password. Sudare never serves a script from the uploads (see [Media and assets](#media-and-assets)).
- **Fail closed.** A configuration that exists but does not validate answers every request with `503`. It never falls back to "no protection".
- **Machine token.** A CI smoke test or uptime check can pass with an `X-Sudare-Token` header instead of the Basic Auth password.
- **Exempt paths.** Webhooks and similar endpoints can be let through without credentials.
- **Two CLIs.** `sudare-hash` creates the password hash and the configuration; `sudare-check` validates a configuration and works as a gate before a deploy.
- **A contract for other packages.** `Sudare\Sudare::active()` tells site code whether the current request is behind the blind.
- **Legacy file still works.** A site's `site/config/credentials.php` (the `vorschau` format of kirby-starter) is read as it is. See [Migration](#migration-from-vorschauschutzphp).
- **English and German.** The 401 page, the 503 text and the CLI messages come in both languages.
- **No cookies, no browser storage, no requests to third parties.** See [PRIVACY.md](PRIVACY.md).

## Requirements

- PHP 8.2–8.4 with `mbstring` (every Kirby site has it)
- Kirby is **not** required by the package. Only the site that uses the package needs Kirby, in any version.
- The site at the **host root**: `public/` is the document root and Kirby answers at `https://host/`. In a sub-directory installation (`RewriteBase /preview/`, an `Alias`) Sudare still protects every request, but `media/` and `assets/` requests do not match its file routes (they are 404, closed rather than open) and `exempt` entries are matched against the full path, prefix included.

## Installation

```sh
composer require sayamaapps/kirby-sudare
```

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/simsimmal/kirby-sudare" }]
```

The package is of Composer type `library`. It lands in `vendor/sayamaapps/kirby-sudare/`, **not** in `site/plugins/`, and it is not a Kirby plugin. It has to run before Kirby exists, so a plugin would load too late (see [Deviations from the plugin family](#deviations-from-the-plugin-family)). Make sure the deploy ships `vendor/`, as it ships `kirby/`.

## Setup

1. **`public/index.php`** — call the guard before Kirby. The complete file is in [`examples/index.php`](examples/index.php). The essential part:

   ```php
   <?php

   $base = dirname(__DIR__);

   require $base . '/vendor/autoload.php';

   Sudare\Sudare::guard($base);   // 401 or 503 (and exit), or a media/assets file (and exit), or return

   echo (new Kirby\Cms\App([...]))->render();
   ```

   Without a configuration `guard()` does nothing and sends no header, so local development is not disturbed. Nothing may be output before `guard()` — not even a UTF-8 BOM at the top of `index.php`: with `output_buffering` off the headers are already gone, PHP logs *headers already sent*, and the 401 page arrives with status 200 and no login dialog (the site still stays closed, Kirby never runs).

2. **`public/.htaccess`** — the starter already routes `media/` through `index.php`. Add the `assets/` line to protect the assets too, give both `[NC]` (on a case-insensitive file system — macOS, Windows — Apache would otherwise serve `Media/…` past the password), and keep `CGIPassAuth On` and `SetEnvIf Authorization`. The lines are in [`examples/htaccess.txt`](examples/htaccess.txt):

   ```apache
   RewriteRule ^media/(.*) index.php [NC,L]
   RewriteRule ^assets/(.*) index.php [NC,L]
   SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
   CGIPassAuth On
   ```

3. **Create the configuration** with the hash CLI. It writes `site/config/sudare.php` (gitignored) and asks for the password without echoing it:

   ```sh
   vendor/bin/sudare-hash --user=preview > site/config/sudare.php
   ```

   The template is [`examples/sudare.php`](examples/sudare.php). The password has to be at least 8 characters and at most 72 bytes long (bcrypt ignores everything after byte 72, so `sudare-hash` refuses longer ones).

4. **Deploy.** The configuration reaches the server from a secret:
   - Store the generated file's content as the secret **`SUDARE_PHP`** (the name is recommended for new deploys; the legacy `CREDENTIALS_PHP` keeps working with the legacy file name).
   - Write it to `site/config/sudare.php` in the deploy job, then run `vendor/bin/sudare-check` as a **gate** before the upload. It exits 1 when Sudare is not configured and 2 when the configuration is broken, so an unprotected or broken preview never goes online. The gate reads the files only: `SUDARE_*` variables of the CI job are not the server's, so they count only with `--env`.
   - The **public** deploy (launch day) runs `vendor/bin/sudare-check --allow-off` instead: it accepts a missing or switched-off configuration on purpose, and still fails on a broken one.

   In GitHub Actions the failing lines carry `::error::` annotations when `GITHUB_ACTIONS=true`.

5. **Smoke test.** A check after the deploy needs the credentials. Two ways:
   - The Basic Auth user and password. kirby-starter's deploy workflow passes the secrets `PREVIEW_USERNAME` and `PREVIEW_PASSWORD` to `scripts/smoke-test.sh` (as `SMOKE_BENUTZER` and `SMOKE_PASSWORT`); the script writes them into a `curl` config file (`curl -K`) and never passes them as an argument on the command line.
   - The machine token: `curl -H 'X-Sudare-Token: …'` with the plain token from `sudare-hash --token`. Use it when the check should not need the human password.

## Configuration

Options are read from `site/config/sudare.php`, from the legacy `site/config/credentials.php`, and from `SUDARE_*` environment variables. The file is read per request. Opcache is asked to drop its copy when the file changed (`opcache_invalidate()` without force), so a rotated password counts at once even with a long `opcache.revalidate_freq`. Two php.ini settings need one more line: with `opcache.validate_timestamps=0` opcache has no modification time to compare and every request drops the copy (about 1 KB of wasted shared memory each, until opcache restarts); with `opcache.restrict_api` the call is refused and an old copy holds until opcache revalidates it itself — never with `validate_timestamps=0` (`sudare-check` warns). In both cases add the path of `site/config/sudare.php` to `opcache.blacklist_filename`: the file is then never cached.

### Spellings

Every option works in three spellings. A flat key beats a nested one, and a nested one beats a bare one:

```php
// site/config/sudare.php
return [
    'user' => 'preview',                     // bare
    'sudare' => ['hash' => '$2y$…'],         // nested
    'sudare.realm' => 'Preview — Praxis',    // flat
];
```

### Options

| Key | Env | Type | Default | Meaning |
|---|---|---|---|---|
| `active` | `SUDARE_ACTIVE` | bool | `true` | `false` parks the configuration: the site is open, no headers are sent. |
| `user` | `SUDARE_USER` | string | — | Basic Auth user name. Non-empty, no `:` (Basic Auth splits there), no control characters. Trimmed. |
| `hash` | `SUDARE_HASH` | string | — | `password_hash()` output (bcrypt or Argon2, recognised by `password_get_info()`), or a full bcrypt hash with the `$2a$`/`$2b$` prefix as python-bcrypt and htpasswd write it. Surrounding whitespace is trimmed. Never a plain password. Must not contain `ERSETZEN` or `REPLACE` (the template's example). |
| `realm` | `SUDARE_REALM` | string | `Preview` (`Vorschau` for the legacy file) | Text in the browser's login dialog. Sanitised: quotes, backslashes and line breaks are removed. |
| `token` | `SUDARE_TOKEN` | string | `null` | **Hash** of a machine token (`password_hash()` of the random token). See [Machine token](#machine-token). |
| `exempt` | `SUDARE_EXEMPT` | list of entries | `[]` | Paths that pass without credentials: `/path` (that path only), `/path/*` (and below), each optionally with a method (`POST /path`). `/.well-known/acme-challenge` is always exempt. See [Exemptions](#exemptions). |
| `language` | `SUDARE_LANGUAGE` | `en` \| `de` | `en`; **`de`** for the legacy file | Language of the 401 page, the 503 text and the default realm. |
| `theme` | — | array | see below | Colours of the 401 page. See [The 401 page and `theme`](#the-401-page-and-theme). |
| `media` | `SUDARE_MEDIA` | bool | `true` | Serve `public/media/**` through PHP. `false` leaves every media request to Kirby. |
| `assets` | `SUDARE_ASSETS` | bool | `true` | Serve `public/assets/**` through PHP when the request reaches PHP. `false` leaves it to Kirby. |

`SUDARE_THEME` does not exist: the theme is set in the file only. Environment variables are laid over the file per key, so `SUDARE_USER` alone replaces only the user.

`null` and an empty string both mean "not set": the default applies (`'token' => ''` is no token). `media` and `assets` count while `active` is `false` too: they decide whether Sudare serves files on the open site.

### Sources

1. `site/config/sudare.php`, if it exists.
2. `site/config/credentials.php` (the legacy file), read only when `sudare.php` does not exist. `vorschau.benutzer`, `vorschau.hash` and `vorschau.realm` map to `user`, `hash` and `realm`; English keys next to `vorschau` count too and win, so `'active' => false` parks a legacy file. A `credentials.php` that already uses the English keys is read as the new format. A legacy file speaks German, even when it cannot be read.
3. `SUDARE_*` environment variables, laid over the file per key. The environment alone (no file) is a source too, for hosters that set variables with Apache's `SetEnv`. `Sudare::environment()` reads `getenv()` plus the `SUDARE_*` keys of `$_SERVER`.

Any `SUDARE_*` variable of the table above makes the environment a source, except `SUDARE_LANGUAGE`: it also sets the language of the CLIs, so on its own it configures nothing. `SUDARE_REALM` alone, by contrast, is a source without user and hash, so the site answers 503 (fail closed).

A file that is a dangling symlink counts as present (and is broken, see below). So does a `site/` or `site/config/` directory that is a dangling symlink or that PHP may not search: the files behind it cannot be seen, so the configuration is broken, never absent.

### States

| State | When | What Sudare does |
|---|---|---|
| `none` | no file and no `SUDARE_*` variable | Nothing: the request passes, no header. Local development, a public site. |
| `off` | a source exists and `active` is `false` | Nothing: the request passes, no header. |
| `broken` | a source exists and does not validate | **503 on every request** (the ACME path excepted), with the protective headers and `Retry-After: 300`. |
| `on` | valid | Basic Auth or token, with the protective headers. |

`active` defaults to **true when a source exists**. The presence of the file is the switch: a deploy writes it, a public deploy removes it or runs `sudare-check --allow-off`. `active => false` parks a file without deleting it. A typo in `active` makes the configuration broken, never open.

### Reason codes

`Config::reason()` names why a configuration is broken. The check order is: `unreadable`, `syntax`, `notArray`, `active`, `user`, `example`, `hash`, `token`, `realm`, `exempt`, `language`, `media`, `assets`, `theme`. `sudare-check` prints the sentence for the code. The 503 page itself never shows it: the reason stays in the CLI output.

A file that ends the script while it is read (`exit`, `die`, a fatal error such as a function declared twice) cannot return a reason. Sudare catches it at shutdown: what the file printed is discarded, the request gets the 503, and in a CLI (`sudare-check`) the process exits 2 with the reason `exit`.

Keep the file a plain `return [...]`. It is executed once per request; `guard()` and a later `protects()` share the result.

### A complete file

```php
<?php

return [
    'user'     => 'preview',
    'hash'     => '$2y$12$…',                       // vendor/bin/sudare-hash
    'realm'    => 'Preview — Praxis Beispiel',
    'token'    => '$2y$12$…',                       // vendor/bin/sudare-hash --token
    'exempt'   => ['/api/webhook'],
    'language' => 'de',
    'theme'    => ['chrome' => '#16242F', 'paper' => '#FCFAF8', 'ink' => '#15222B', 'deep' => '#1E3648', 'text' => '#41505A'],
];
```

### The CLIs

```sh
vendor/bin/sudare-hash [--user=NAME] [--realm=TEXT] [--legacy] [--token] [--cost=4-31] [--language=en|de]
vendor/bin/sudare-check [PROJECT-ROOT|CONFIG-FILE] [--allow-off] [--env] [--language=en|de]
```

- `sudare-hash` prompts for the password (no echo on a terminal), prints the configuration file to standard output and a line for the secret to standard error. `--token` also creates a machine token, shown once. `--legacy` writes the old `credentials.php` format; it has no token, so `--legacy --token` is a usage error (exit 3). Hashes are bcrypt with cost 10; `--cost` changes it (see [Security notes](#security-notes) for why not PHP's default).
- `sudare-check` prints the state and what is protected, and exits 0 when the configuration is valid, 1 when there is none or it is switched off, 2 when it is broken. A path that does not exist counts as "none". `--allow-off` makes "none" and "off" exit 0. The check reads the files only; `--env` lays the `SUDARE_*` variables of the shell over them, as the server does with its own, and prints which ones counted. The output speaks the language of the configuration it found (a legacy file: German); `SUDARE_LANGUAGE` counts with `--env` or when there is no configuration, `--language` always wins. The check never prints the hash, the token hash or the file's contents, and it removes control characters from what it prints (realm, paths), so a value cannot add a line to a CI log. For a valid configuration it also prints the CSP source of the 401 page's style (see [The 401 page and `theme`](#the-401-page-and-theme)).

## Migration from `vorschauschutz.php`

The legacy file keeps working as it is: a site that changes nothing gets the same protection with the same texts (German, realm `Vorschau`). The migration is a rollout per site, not a package change.

What a site does:

1. Keep `site/config/credentials.php` for now. To switch to the new file, create `site/config/sudare.php` with `vendor/bin/sudare-hash --user=…`, or copy the existing `hash` value over. `sudare.php` wins once it exists; remove `credentials.php` afterwards (after a check with `sudare-check`) so that only one source remains.
2. Replace the guard in `public/index.php`: `vorschauschutz($base)` and `vorschauMedien($base)` become the single `Sudare\Sudare::guard($base)` after `require vendor/autoload.php`. Assets: add the `assets/` line to `.htaccess`.
3. Delete from the site: `site/config/vorschauschutz.php`, `scripts/hash-erzeugen.php`, `scripts/pruefe-zugangsdaten.php` and the two tests of the old guard. `vorschauschutz.php` was byte-identical in six sites; the copy in one client site differed (themed page, `vorschauAktiv()`).
4. Replace the "is this a preview?" checks:
   - `vorschauAktiv($base)` becomes `\Sudare\Sudare::active()` (after `guard()`) or `\Sudare\Sudare::protects($base)` (anywhere, without state). a client site's `site()->vorschau()` in `site/plugins/praxis/index.php` is an example.
   - Annai's noindex hook: `class_exists(\Sudare\Sudare::class) && \Sudare\Sudare::active()`.
   - a client site's `kizami.previewAccess` may read `Sudare::config()?->user()`. The hash stays private, so it keeps reading the file itself.
   - a client site's `kizami.report` treats the existence of `credentials.php` as "this is not a preview". After the switch to `sudare.php` that test has to become `Sudare::protects($base)`; this package does not change the site's `config.php`.
5. Secrets: the preview slots that deploy today still write `credentials.php` from the `CREDENTIALS_PHP` secret (for example two preview slots of the deploy setup). That keeps working; a new deploy uses `SUDARE_PHP`.

## Behaviour

On every request, after the configuration is loaded, the first matching row decides:

| # | Condition | Response |
|---|---|---|
| 1 | state `none` or `off` | pass, no header |
| 2 | path is `/.well-known/acme-challenge` or below, as sent | the challenge file as text, or an empty 404, with the protective headers, even while broken; never Kirby |
| 3 | state `broken` | 503, plain text, `Retry-After: 300` |
| 4 | path is exempt, as sent | pass, with the protective headers |
| 5 | method `OPTIONS` (path not exempt) | 204, `Allow`, no credentials needed |
| 6 | valid `X-Sudare-Token` | pass, with the protective headers |
| 7 | valid Basic Auth user and password | pass, with the protective headers |
| 8 | anything else | 401 HTML page with `WWW-Authenticate` |

Every path goes through the same rows: the Panel (`/panel`), the API (`/api/…`), `/media/…`, `/assets/…`, `/robots.txt`, `/sitemap.xml`. No path is special, and the tests prove it — for every request that reaches PHP. What the web server answers by itself never gets here: with the starter's `.htaccess` (`!-f` → `index.php`) Apache serves any real file in `public/` outside `media/` and `assets/` directly, so a leftover `public/notes.txt` is public. Keep `public/` free of other static files, or route them too (see [Security notes](#security-notes)).

Rows 2 and 4 apply only to a path **as sent**: no `.` or `..` segment, no double slash, no percent sign (a trailing slash is fine). Kirby routes the raw request path, so `/panel/../api/hook` would be the Panel there and `/%61pi/hook` the site's error page, even though both decode to `/api/hook`. Such a request goes on to rows 5–8 like any other. Browsers and webhook senders do not send these forms for a plain ASCII path.

**Headers.** While the state is `on` or `broken`, every response carries:

| Header | Value |
|---|---|
| `X-Robots-Tag` | `noindex, nofollow, noarchive` |
| `Cache-Control` | `private, no-store` |
| `Vary` | `Authorization` |

The 401 adds `WWW-Authenticate: Basic realm="…", charset="UTF-8"`. The 503 adds `Retry-After: 300`. Files under `media/` and `assets/` carry their own cache headers (see below). While the state is `none` or `off`, Sudare sends no header at all.

On a request that passes, Sudare sends these headers before Kirby runs, and Kirby may replace one of them: the Panel and the API send their own `Vary: Cookie`. `Cache-Control: private, no-store` stays, so shared caches still keep nothing.

**Passwords.** User and password are checked in constant time: the user names are compared as SHA-256 digests, and the password is always verified, even for a wrong user, so both cost the same time. Matching is case-sensitive.

**HEAD.** A HEAD request gets the same status and headers as GET, but no body. For a protected page that is a 401 with headers and no body. For a media file Sudare sends the `Content-Length`. Behind Apache with PHP-FPM (`mod_proxy_fcgi`, measured on 2.4.67; likely the hardening around 2.4.59) Apache drops it from every PHP response, GET and HEAD, unless `ap_trust_cgilike_cl` is set — see `examples/htaccess.txt`. Without it, files arrive complete but chunked: no size for download progress, none on HEAD.

**Kirby's `api.basicAuth`.** Leave it off behind Sudare: the browser sends the Sudare login with every request, so Kirby would take it as an API login and ignore the Panel session — the Panel and the API stop working.

**OPTIONS.** On a protected path, answered with `204` and an `Allow` header, without credentials. It reveals nothing, and lets preflight-style probes and monitoring work. On an exempt path (row 4 comes first) OPTIONS goes on to Kirby like every other method there — an exempt path is public by definition, and a cross-origin client of an exempt endpoint needs Kirby's own preflight answer.

**No redirects.** Sudare never sends a `Location` header. There is no open redirect by construction.

**Language.** The language of the 401 page and the 503 text comes from the configuration of the current request.

### Calls

| Call | Meaning |
|---|---|
| `Sudare\Sudare::guard($base, $public = null, $server = null, $env = null)` | Decides, sends the headers and exits unless the request passes. `$public` defaults to `$base/public`. |
| `Sudare\Sudare::handle($base, $public, $server, $env)` | The same decision as a `Response`, without output and without exit. Remembers the configuration. |
| `Sudare\Sudare::emit($response)` | Sends a `Response`: its headers, and the status and body or file unless it passes. Never exits. |
| `Sudare\Sudare::active()` | `true` when the request of this process is behind the blind. `false` before `guard()`. |
| `Sudare\Sudare::protects($base, $env = null)` | Stateless: would `guard($base)` protect? It reads the files and the environment of the *calling* process: in a CLI (`annai-check`, a build step) the web server's `SetEnv` or FPM `env[]` variables are not visible, so a configuration that lives only there reads as `none`. `sudare-check` has the same limit and names the variables it counts (`--env`). |
| `Sudare\Sudare::config()` | The loaded `Config`, or `null`. |
| `Sudare\Sudare::environment($server = null)` | `getenv()` with the `SUDARE_*` keys of `$_SERVER` laid over it. |
| `Sudare\Sudare::translations()` | The package's strings as Kirby translation keys (`sudare.*`). |
| `Sudare\Sudare::reset()` | Forgets the loaded configuration. For tests. |
| `sudare_option($key, $default = null)` | A non-secret option of the loaded configuration. |

## Media and assets

Generated thumbnails and uploads live under `public/media/`. Without a rewrite, Apache would serve them directly, past the password. The starter's `.htaccess` sends `media/` to `index.php`; Sudare then answers from the file system, with no Kirby boot. The same goes for `assets/` once the second rewrite line is added.

Sudare serves these files **whether or not the site is protected**, for `GET` and `HEAD` (other methods go to Kirby). A public site with the rewrite still needs its images. The cache headers differ:

| Header | Protected (`on`, `broken` never gets here) | `none` / `off` |
|---|---|---|
| `Cache-Control` under `media/` | `private, max-age=31536000` | `public, max-age=31536000` |
| `Cache-Control` under `assets/` | `private, no-cache` | — (none; the server's rules apply) |
| `ETag`, `Last-Modified` | from size and modification time | the same |
| `X-Robots-Tag` | `noindex, nofollow, noarchive` | — |
| `Vary` | `Authorization` | — |
| `X-Content-Type-Options` | `nosniff` | `nosniff` |

Kirby puts a hash into every `media/` URL that changes with the file, so a year is safe there. `assets/` URLs stay the same after a deploy. While protected, a reviewer's browser therefore asks again each time and gets a `304 Not Modified` (no body) while the file is unchanged, answered from `If-None-Match` or `If-Modified-Since`; shared caches may not keep anything. On the open site (`none`, `off`) Sudare sends **no** `Cache-Control` for `assets/`: caching is the server's business then, exactly as if the rewrite were not there. Apache's `mod_expires` (the starter's `.htaccess` has `ExpiresByType` rules) or a versioned URL (`app.css?v=…`) decides, and the browser does not ask again on every page view. Until 1.0.0 Sudare sent `public, no-cache` here, which cost every page view of a live site one round trip per asset.

**Byte ranges.** Every served file carries `Accept-Ranges: bytes`. A `GET` with one range (`Range: bytes=0-1`, `bytes=500-`, `bytes=-500`) gets `206` with `Content-Range` and only those bytes; a range that starts beyond the file gets `416`. Safari and iOS play video and audio only from a server that does this. With `If-Range`, the part is sent only when the value is the file's current `ETag` or `Last-Modified`; otherwise, and for several ranges in one header or `HEAD`, the whole file goes out. Files are streamed in chunks past any output buffer, so a video larger than `memory_limit` works too.

**What is served.** Allowlists by extension. Anything else is not Sudare's business: Sudare returns no file, and Kirby decides (usually a 404).

| Location | Served |
|---|---|
| `media/**` | images (`webp`, `avif`, `jpg`, `jpeg`, `png`, `gif`, `svg`) and `pdf` |
| `media/panel/**`, `media/plugins/**` | additionally code and fonts: `js`, `mjs`, `css`, `json`, `map`, `woff2`, `woff`, `ttf`, `otf`, `ico` |
| `assets/**` | images, code and fonts as above, plus `webmanifest`, `xml`, `txt`, `mp4`, `webm`, `mp3` |

Things that are never served: PHP and template files (`php`, `phtml`, `html`), files without an extension, dotfiles (`/.htaccess`, `/assets/.env`), directories, and anything outside the root. A `.js` file under `media/pages/` is therefore never served by Sudare. It goes on to Kirby, whose media route serves any upload Kirby accepted, scripts included, behind the same password while protected. To keep scripts out of the uploads, limit the types in the site's file blueprints (`accept`). Video and audio under `media/` fall through to Kirby. Paths are checked after decoding once and resolving `..`, and the real path of a file (after symlinks) must still lie under the root.

## Machine token

A token lets a CI job or an uptime check reach a protected site without the human password:

```sh
vendor/bin/sudare-hash --token     # prints the plain token once, and the line for the config
```

The configuration keeps only the hash (`'token' => '$2y$…'`). The request sends the plain token in the `X-Sudare-Token` header. Sudare verifies it with `password_verify()`. A wrong token is a 401, like a wrong password. A configuration without a token ignores the header.

Store the plain token as a CI secret. It is never written to a log or to a file by Sudare.

## Exemptions

`exempt` lists paths that pass without credentials, for example a webhook from an outside service:

```php
'exempt' => ['POST /api/webhook', '/feed/*', 'GET /health'],
```

| Entry | Matches |
|---|---|
| `/api/webhook` | `/api/webhook` (and `/api/webhook/`), any method |
| `/api/webhook/*` | `/api/webhook` and everything below it (`/api/webhook/1`), never `/api/webhooks` |
| `POST /api/webhook` | only a `POST` to that path; `GET` also covers `HEAD` |

Keep entries as narrow as the endpoint. An exempt request reaches Kirby without a login, and for anything Kirby does not route there (another method, an unknown sub-path) Kirby renders the site's error page, with its navigation. So a webhook that only takes `POST` gets a `POST` entry and no `/*`. The method compared is the one Kirby routes: An entry with a method needs that method twice: as Kirby routes it (Kirby honours `X-HTTP-Method-Override`) and as sent, so an override never opens an entry in either direction, and a lower-case `post` is not `POST`. A `HEAD` entry needs exactly `HEAD` as sent: Apache and PHP drop the body only then. `OPTIONS` entries are refused: on a protected path Sudare answers OPTIONS itself (see [Behaviour](#behaviour)), and an entry for OPTIONS alone would hand a preflight to Kirby's page route, which renders the page with its body while GET stays protected. (On a path exempt without a method, OPTIONS reaches Kirby like every other method — that path is public anyway.) Sudare serves `media/` and `assets/` files for what Kirby routes as GET or HEAD, as Kirby's media routes do. Each entry has to start with `/` (after the method) and be written as the request sends it: no `%`, no `.` or `..` segment, no double slash (an entry like `/x/../panel` is refused, not resolved). `/` and `/*` alone are refused, and `*` is allowed only as the final `/*`. Only the path as sent counts: `/x/../api/webhook` or `/%61pi/webhook` is not exempt (see [Behaviour](#behaviour)). Entries may contain only ASCII letters, digits, `-`, `_`, `.` and `/`; anything else makes the configuration broken. A request path with a percent sign is never exempt, so an entry with characters a client has to encode (a space, `ü`) could never match anyway, and Kirby reads a segment with `:` (`;` on Windows) as a URL parameter and routes without it: `/lang:de/*` would let `/lang:de/panel` reach the Panel.

`/.well-known/acme-challenge` is always exempt, even while the configuration is broken, so a certificate renewal never depends on the password. Usually the web server serves the challenge file before PHP runs. When the request does reach PHP, Sudare answers it itself: the file from `public/.well-known/acme-challenge/` as plain text, or an empty `404`. Kirby never sees these requests, because its error page would show the unlaunched site's navigation to anyone.

An exempt path still gets the protective headers. An exempt path is open to everyone: only list paths that are safe without a login.

## The 401 page and `theme`

The 401 page is one self-contained HTML document: no script, no external resource, no reference to the realm or the user. It has a viewport, `<meta name="robots">` and a `theme-color`. Its texts come from the translation table of the configured language.

The colours are set with `theme`. Each value is a hex colour (`#rgb`, `#rrggbb` or `#rrggbbaa`); a missing key keeps the default. The site computes the array itself in `site/config/sudare.php`:

| Key | Used for | Default |
|---|---|---|
| `chrome` | browser `theme-color` | `#1E3648` |
| `paper` | page background | `#FFFFFF` |
| `ink` | body text | `#15222B` |
| `deep` | heading | `#1E3648` |
| `text` | paragraph | `#41505A` |

`theme` is file-only. An invalid colour makes the configuration broken.

**Content-Security-Policy.** The colours sit in one inline `<style>` element. A site whose CSP says `style-src 'self'` (kirby-starter's `.htaccess` does, with `Header always set`, which covers the 401 too) blocks it: the page shows unstyled, and only `chrome` takes effect. To keep the colours, add the hash of the style element to `style-src`. `sudare-check` prints it for the configured theme:

```
Colours of the 401 page under a Content-Security-Policy: add 'sha256-…' to style-src.
```

The hash allows exactly this style element and nothing else. It changes with the theme, so run `sudare-check` again after a change. In PHP it is `Sudare\Page::styleSource($config)`.

## Translations

Every user-facing string comes from `translations/en.php` and `translations/de.php`, under the keys `realm`, `denied.*`, `unavailable.text`, `reason.*` and the CLI texts. A site plugin that wants the same strings as Kirby translations asks the package for them:

```php
// site/plugins/praxis/index.php, after the guard has run
$translations = \Sudare\Sudare::translations();   // ['en' => ['sudare.realm' => …], 'de' => …]
```

Sudare cannot register them itself, because it runs before Kirby exists. `sudare_option()` (a global helper from `bootstrap.php`) reads the non-secret options of the loaded configuration; `hash` and `token` are never returned.

## Local development

Nothing happens without a configuration: `guard()` returns, and the site runs as usual. Add `site/config/sudare.php` locally only when you want to try the protection.

With PHP's built-in server, the router script is called for every request, real files included. [`examples/index.php`](examples/index.php) therefore has a `cli-server` branch. By default it hands nothing to the built-in server for `/assets/`, so Sudare protects the assets locally too; the alternative (returning public assets directly) is written there as a commented option. The branch also sends the same security headers as `.htaccess`.

## Tests

```sh
composer test        # sh tests/run.sh
```

The tests are standalone PHP scripts, without PHPUnit, and run on a plain PHP 8.2+:

| File | Covers |
|---|---|
| `tests/I18nTest.php`, `tests/OptionsTest.php` | translations, option spellings and environment |
| `tests/RequestTest.php` | request path, method and credentials, and the response objects |
| `tests/ConfigTest.php` | sources, states, every validation rule, the templates |
| `tests/GuardTest.php` | the decision table (SPEC §6.2), pure |
| `tests/PageTest.php` | the 401 page, the 503 text, the realm header |
| `tests/MediaTest.php` | the media and assets allowlists, traversal, symlinks, headers |
| `tests/CliTest.php` | `sudare-hash` and `sudare-check`, both binaries |
| `tests/SudareTest.php` | the facade in fresh PHP processes |
| `tests/HttpTest.php` | real HTTP through PHP's built-in server: status lines and headers |

`tests/helpers.php` holds the shared helpers. `HttpTest` starts the built-in server on a free port and stops it at the end.

## Security notes

- Passwords are stored as `password_hash()` output (bcrypt or Argon2) and checked with `password_verify()`. The package never stores a plain password, and `sudare-hash` never writes one.
- Fail closed: a configuration that exists and does not validate answers every request with 503. A `sudare.php` copied from the example is broken, not open.
- No secret reaches output or logs: not the hash, not the token, not the password. The 503 body is one fixed sentence; the reason stays in `sudare-check`.
- User names are compared in constant time (SHA-256 digests with `hash_equals`).
- Basic Auth sends the credentials with every request. **Use HTTPS**, and the token too: both are only as safe as the connection.
- The file under `site/config/` is read by PHP and must not be served: the starter's `.htaccess` routes `site/` to `index.php`. Keep it out of Git (`site/config/sudare.php` in `.gitignore`).
- Sudare sends no `Location` header and never redirects.
- **Sudare protects what reaches PHP.** Everything the web server serves by itself bypasses it:
  - Any real file in `public/` outside `media/` and `assets/` (the starter's `.htaccess` sends only missing files to `index.php`). Keep `public/` free of other static files, or add a rewrite for them.
  - A rewrite that matches only lower-case `media/`: on a case-insensitive file system (macOS, Windows/NTFS) Apache finds `Media/…` on disk and serves it past the password. The rules in [`examples/htaccess.txt`](examples/htaccess.txt) carry `[NC]` for that reason; check a site's own `.htaccess`.
  - A host whose front server (the hosting panel's nginx with "serve static files directly", a CDN) answers existing files before Apache runs: then `media/**` and `assets/**` never see `.htaccess`. Neither `sudare-check` nor the tests can see this — **after every first deploy, request an existing media file without credentials; the answer must be 401**, not the file. In the hosting panel, switch "Serve static files directly by nginx" off for a preview. On a plain nginx + FPM server the usual Kirby block (`try_files $uri $uri/ /index.php?$query_string`) serves existing files itself; give `media/` and `assets/` their own `location ^~ /media/ { rewrite ^ /index.php last; }` (same for `/assets/`) so they reach PHP.
- Hashes are bcrypt with cost 10 by default (`sudare-hash --cost=N` for more). Every authenticated request through PHP — each image, each stylesheet — verifies the password once; at cost 12 (PHP's default since 8.4) that is ~250 ms of CPU per file on a shared host, at 10 ~60–85 ms. A `Config` accepts any hash `password_get_info()` knows, so a pasted Argon2 hash works too.

## Deviations from the plugin family

Sudare follows the conventions of the other sayamaapps packages (Composer metadata, MIT, PHP 8.2 floor, standalone tests, `src/` with a translation table, one README, CHANGELOG, PRIVACY and DATENSCHUTZ). It deviates where the job requires it:

| Convention | Sudare | Why |
|---|---|---|
| Kirby plugin (`type: kirby-plugin`, `App::plugin()`) | Composer `library`, lands in `vendor/`, `bootstrap.php` instead of `index.php`, no `App::plugin()` | Runs before Kirby boots. A plugin loads too late to protect the Panel, the API and media files. |
| Options via Kirby `option()` with defaults at call sites | `site/config/sudare.php`, the legacy `credentials.php` and `SUDARE_*` variables, read by the package's own `Options` and `Config` | There is no Kirby yet. A bare key is allowed as a third spelling. |
| `'<name>.active' => true` needed | `active` defaults to `true` when a source exists; `false` switches off | The switch has always been "the file exists". The legacy file has no `active` key. |
| Translations registered as Kirby translations | `Sudare::translations()` returns the prefixed tables for a site plugin | Cannot register before Kirby exists. |
| Unstyled snippets, array methods | One inline-styled 401 page with a `theme` option | There is no template engine before Kirby. The page must be self-contained. |
| `<name>_option()` reads Kirby | `sudare_option()` reads the loaded configuration (non-secret keys) | Same reason. |
| Kirby-booting tests | No Kirby test. `HttpTest` runs PHP's built-in server with a stand-in for Kirby | Kirby is irrelevant to the behaviour. `getkirby/cms` stays a dev dependency, so a later handover test is possible. |

## Privacy

Sudare sets no cookies, uses no browser storage and loads nothing from a third party. It does not record visitors. The technical description, in English and German, is in [PRIVACY.md](PRIVACY.md) and [DATENSCHUTZ.md](DATENSCHUTZ.md). They describe the implementation, not legal advice.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Maintenance

This module is open source under the MIT license and is maintained for the author's own projects. Issues and pull requests are welcome, but there is no support promise and no release schedule. Report security problems privately through GitHub's private vulnerability reporting (the "Report a vulnerability" button on the Security tab).

## License

MIT. See [LICENSE](LICENSE).
