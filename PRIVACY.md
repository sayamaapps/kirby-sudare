# Sudare: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe
the implementation. They are not legal advice. Each operator has to determine
the legal basis and the wording for their own site.

## What the visitor's browser does

Sudare sets no cookies. It uses no `localStorage`, `sessionStorage` or
IndexedDB, and it loads no script, font, image or embed from another server.
The 401 page is one self-contained HTML document.

Protected sites use HTTP Basic Auth. The browser asks for a user name and a
password and then sends them in the `Authorization` header **with every request**
to the site. This is the only data the visitor's browser hands over. It is
safe only over HTTPS. The browser keeps the credentials for the session, under
its own rules; Sudare does not store anything in the browser.

The machine token (`X-Sudare-Token`) is sent by the requesting program, not by
a browser. It is equally safe only over HTTPS.

## What the server does

- It compares the user name and the password with the stored values. The
  stored value is a hash (bcrypt or Argon2), never the password.
- It stores nothing: no log file of its own, no database, no session, no
  counter of failed logins.
- It does not read or record the visitor's IP address, user agent or device
  data. The client address is not used for any decision.
- It sends the protective headers `X-Robots-Tag`, `Cache-Control` and `Vary`,
  which keep search engines and shared caches away from the protected pages.

The web server's access log is outside Sudare. Depending on its configuration,
it can record the user name of an authenticated request, and the IP address of
every request. That log is covered by the hoster's log policy and the site's
general privacy policy.

## What is shown

- A neutral 401 page: "This site is not public yet" (German: "Diese Seite ist
  noch nicht öffentlich"). It names no user, no realm beyond the browser's
  dialog text, and no path.
- A fixed 503 sentence while the configuration is broken, without the reason
  and without any path.
- The media and assets that Sudare serves through PHP: behind the same check
  while the site is protected, and without it once the site is open.

## What the operator should check

- **HTTPS** for the site and the token. Basic Auth without HTTPS sends the
  password in clear text.
- **Log retention** of the web server, including the user name it may record.
- **Tell the reviewers the credentials out of band** (not in the same e-mail as
  the link, not in a public ticket). Keep the password long; `sudare-hash`
  refuses fewer than 8 characters.
- **Keep the token secret.** It is shown once by `sudare-hash --token`; store it
  as a CI secret only.
- **Roll the blind up on launch day.** Remove the configuration (or run
  `vendor/bin/sudare-check --allow-off` in the public deploy, which accepts a
  missing configuration on purpose and still fails on a broken one).
- **Exemptions are open.** Every path listed in `exempt` is reachable without a
  login. List only what must be reachable (for example a webhook).
