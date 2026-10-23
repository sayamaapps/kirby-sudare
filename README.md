# Sudare

Preview protection for [Kirby CMS](https://getkirby.com) sites before launch.

Sudare puts HTTP Basic Auth in front of a Kirby site, adds `noindex` to its responses, and serves the files under `media/` and `assets/` through PHP, so they sit behind the same password. It runs before Kirby boots, and no route gets past it: not the Panel, not the API, not an error page. Without a configuration, it does nothing.

## Features

- Basic Auth before Kirby: the Panel, the API and error pages are protected too.
- `X-Robots-Tag: noindex, nofollow, noarchive` on every protected response, so search engines skip the preview.
- `media/` and `assets/` served through PHP, behind the same password.
- Fails closed: an invalid configuration answers every request with `503`.
- Machine token for CI and uptime checks, and exempt paths for webhooks.
- Hash and check CLIs (`sudare-hash`, `sudare-check`) for the deploy gate; English and German.

## Requirements

- PHP 8.2–8.4 with `mbstring`.
- Kirby on the site, any version. The package does not require Kirby.
- Host root only: `public/` is the document root. In a sub-directory install, `media/` and `assets/` requests return `404`, which is closed, not open.

## Installation

```sh
composer require sayamaapps/kirby-sudare
```

Until the package is on Packagist, add the repository to `composer.json` first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/sayamaapps/kirby-sudare" }]
```

The package is a Composer `library`, not a Kirby plugin. It installs to `vendor/sayamaapps/kirby-sudare/`; deploy `vendor/` as you deploy `kirby/`.

### Setup

1. **`public/index.php`**: call the guard before Kirby. Print nothing before it, not even a BOM. Keep Kirby's `api.basicAuth` off, or the Sudare login is taken as an API login.

   ```php
   <?php

   $base = dirname(__DIR__);

   require $base . '/vendor/autoload.php';

   Sudare\Sudare::guard($base);   // 401 or 503 (and exit), a media or assets file (and exit), or return

   echo (new Kirby\Cms\App([...]))->render();
   ```

   The complete file is [`examples/index.php`](examples/index.php).

2. **`public/.htaccess`**: route `media/` and `assets/` through `index.php`, and keep the two authentication lines. See [`examples/htaccess.txt`](examples/htaccess.txt).

   ```apache
   RewriteRule ^media/(.*) index.php [NC,L]
   RewriteRule ^assets/(.*) index.php [NC,L]
   SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
   CGIPassAuth On
   ```

   Keep other static files out of `public/`: Apache serves them without Sudare.

3. **Configuration**: `sudare-hash` asks for the password (at least 8 characters, at most 72 bytes) and prints the file to standard output. Keep `site/config/sudare.php` out of Git.

   ```sh
   vendor/bin/sudare-hash --user=preview > site/config/sudare.php
   ```

   Under `opcache.validate_timestamps=0` or `opcache.restrict_api`, add the file to `opcache.blacklist_filename`. With `restrict_api`, a changed password otherwise counts only when opcache revalidates; `sudare-check` warns about that.

4. **Deploy**: store the file's content as the secret `SUDARE_PHP`, write it to `site/config/sudare.php` in the deploy job, and gate the upload with `vendor/bin/sudare-check`. It exits `1` when there is no configuration or it is switched off, and `2` when it is broken. The public deploy runs `vendor/bin/sudare-check --allow-off` instead: a missing configuration passes, a broken one still fails. The check reads the files; `--env` also counts the `SUDARE_*` variables of the job.

   After the first deploy, request an existing media file without credentials. The answer must be `401`. A front server that serves files itself bypasses Sudare, and no check can see that.

## Usage

- **Check order:** the ACME challenge path; a broken configuration (`503`); exempt paths; `OPTIONS` (`204`); a valid `X-Sudare-Token`; valid Basic Auth; everything else (`401`).
- **States:** without a file or `SUDARE_*` variable, Sudare does nothing. A file that exists but does not validate is broken, and every request gets `503`.
- **HTTPS:** Basic Auth and tokens travel with every request, so the site must be served over HTTPS.
- **Media and assets:** images and PDF under `media/`; code and fonts only under `media/panel/`, `media/plugins/` and `assets/`; a few web formats (`webmanifest`, `xml`, `txt`, `mp4`, `webm`, `mp3`) under `assets/`. Anything else goes to Kirby. While protected, responses are `private`; on an open site, media is cached publicly for a year and `assets/` is left to the server.
- **Local development:** without a configuration nothing changes. [`examples/index.php`](examples/index.php) shows how to run PHP's built-in server with the same protection.

### Machine token

```sh
vendor/bin/sudare-hash --token     # prints the plain token once, and the hash for the configuration
curl -H 'X-Sudare-Token: …' https://example.com/
```

The configuration keeps only the hash. Store the plain token as a CI secret.

### Exempt paths

For a webhook from an outside service:

```php
'exempt' => ['POST /api/webhook', '/feed/*'],
```

An exempt path is open to everyone, so keep entries as narrow as the endpoint. `/.well-known/acme-challenge` is always exempt.

### Calls

| Call | Meaning |
|---|---|
| `Sudare\Sudare::guard($base)` | Decides, sends the headers, and exits unless the request passes. |
| `Sudare\Sudare::active()` | `true` when this request is behind the protection (after `guard()`). |
| `Sudare\Sudare::protects($base)` | Stateless: would `guard($base)` protect? Reads files and this process's environment only. |
| `Sudare\Sudare::config()` | The loaded configuration, or `null`. |
| `Sudare\Sudare::translations()` | The package's strings as `sudare.*` keys, for a site plugin to register. |
| `sudare_option($key, $default)` | A non-secret option. `hash` and `token` are never returned. |

### Translations

The 401 page, the 503 text and the CLI messages come in English and German. `Sudare::translations()` returns them as `sudare.*` keys; Sudare cannot register them itself, because it runs before Kirby.

## Options

Keys are `sudare.<key>` (flat), `'sudare' => [...]` (nested) or bare in `site/config/sudare.php`; a flat key beats a nested one, which beats a bare one. `null` and an empty string mean "not set". Environment variables are laid over the file per key, and without any file they can configure Sudare on their own. If `sudare.php` is absent, the legacy `site/config/credentials.php` (a `vorschau` array) is read.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `active` | `SUDARE_ACTIVE` | `true` when a source exists | `false` parks the configuration and the site is open. A typo makes it broken, never open. |
| `user` | `SUDARE_USER` | — | Basic Auth user name: non-empty, no `:`, no control characters. |
| `hash` | `SUDARE_HASH` | — | `password_hash()` output (bcrypt or Argon2), never the plain password. A damaged or template hash makes the configuration broken. |
| `realm` | `SUDARE_REALM` | `Preview` (`Vorschau` in the legacy file) | Text of the browser's login dialog. |
| `token` | `SUDARE_TOKEN` | — | Hash of a machine token (see Machine token). |
| `exempt` | `SUDARE_EXEMPT` | `[]` | Paths that pass without credentials: `/path`, `/path/*`, optionally with a method. Comma-separated in the environment. |
| `language` | `SUDARE_LANGUAGE` | `en` (`de` in the legacy file) | `en` or `de` for the 401 page, the 503 text and the default realm. |
| `theme` | — | built-in palette | Colours of the 401 page: `chrome` (browser theme colour), `paper`, `ink`, `deep`, `text`; `#rgb`, `#rrggbb` or `#rrggbbaa`. File only. |
| `media` | `SUDARE_MEDIA` | `true` | Serve `media/` through PHP. `false` leaves it to Kirby. |
| `assets` | `SUDARE_ASSETS` | `true` | Serve `assets/` through PHP. `false` leaves it to Kirby. |

The `theme` defaults are `#1E3648` (chrome), `#FFFFFF` (paper), `#15222B` (ink), `#1E3648` (deep) and `#41505A` (text). Under a Content-Security-Policy with `style-src 'self'`, the 401 page's colours need the hash that `sudare-check` prints.

## Privacy

Sudare processes no visitor data: no cookies, no browser storage, no requests to third parties.

## Maintenance

Tests: `composer test`. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE).
