<?php

/**
 * English strings (default and fallback). Shared file — owned by the
 * architect (SPEC.md §5). Keys are the contract; `{name}` are placeholders.
 */
return [
    // Browser dialog and the 401 page
    'realm'            => 'Preview',
    'denied.pageTitle' => 'Preview',
    'denied.heading'   => 'This site is not public yet',
    'denied.text'      => 'It is under construction and can only be reached with access credentials.',

    // 503 while the configuration is broken (plain text, never details)
    'unavailable.text' => 'The preview is temporarily unavailable.',

    // bin/sudare-hash
    'cli.hash.prompt'      => 'Password for the preview: ',
    'cli.hash.visible'     => 'Warning: shell_exec is disabled, the password will be visible while you type. Pipe it in instead: printf \'%s\\n\' "…" | vendor/bin/sudare-hash …',
    'cli.hash.empty'       => 'No password entered, aborting.',
    'cli.hash.short'       => 'The password is shorter than {min} characters. Please choose something longer.',
    'cli.hash.long'        => 'The password is longer than {max} bytes or contains a NUL character: bcrypt would ignore part of it. Please choose a shorter one.',
    'cli.hash.mismatch'    => 'The generated hash does not confirm the password. This must not happen.',
    'cli.hash.info'        => 'Algorithm: {algo}, cost {cost}',
    'cli.hash.output'      => 'Content for the secret {secret}:',
    'cli.hash.token'       => 'Machine token (shown once — store it in your CI secrets): {token}',
    'cli.hash.usage'       => 'Usage: sudare-hash [--user=NAME] [--realm=TEXT] [--legacy] [--token] [--cost=4-31] [--language=en|de]',

    // bin/sudare-check
    'cli.check.none'   => 'No Sudare configuration at {path}. The site is NOT protected.',
    'cli.check.off'    => 'Sudare is switched off (active => false). The site is NOT protected.',
    'cli.check.allowOff' => 'Not protected — accepted because of --allow-off (public deploy).',
    'cli.check.broken' => 'The Sudare configuration is broken: {reason}. Every request would be answered with 503.',
    'cli.check.ok'     => 'Sudare configuration valid ({source}): user "{user}", {algo} hash, realm "{realm}".',
    'cli.check.token'  => 'Machine token: configured.',
    'cli.check.exempt' => 'Exempt paths: {paths}',
    'cli.check.env'    => 'Counted from the environment (--env): {keys}.',
    'cli.check.files'  => 'Files served through PHP: media/ {media}, assets/ {assets}.',
    'cli.check.style'  => 'Colours of the 401 page under a Content-Security-Policy: add {source} to style-src.',
    'cli.check.opcache' => 'opcache.restrict_api is set in this PHP: Sudare cannot drop the cached copy of the file, so a changed one counts only when opcache revalidates it itself (revalidate_freq; never with validate_timestamps=0). Add {file} to opcache.blacklist_filename.',
    'cli.check.usage'  => 'Usage: sudare-check [PROJECT-ROOT|CONFIG-FILE] [--allow-off] [--env] [--language=en|de]',

    // Reasons why a configuration is broken (Config::reason() codes)
    'reason.unreadable' => 'the file cannot be read',
    'reason.syntax'     => 'the file cannot be executed (syntax error or exception)',
    'reason.notArray'   => 'the file does not return an array',
    'reason.exit'       => 'the file ends the script (exit, die or a fatal error)',
    'reason.active'     => '"active" must be true or false',
    'reason.user'       => '"user" is missing, empty or contains a colon',
    'reason.hash'       => '"hash" is not a complete password hash (bcrypt or Argon2) — damaged, truncated, or the plain password? Generate one with: vendor/bin/sudare-hash',
    'reason.example'    => 'the example hash from the template is still in place',
    'reason.token'      => '"token" is set but is not a complete password hash (bcrypt or Argon2)',
    'reason.realm'      => '"realm" must be a string',
    'reason.exempt'     => '"exempt" must be a list of paths starting with "/" (not "/" alone)',
    'reason.language'   => '"language" must be one of: en, de',
    'reason.media'      => '"media" must be true or false',
    'reason.assets'     => '"assets" must be true or false',
    'reason.theme'      => '"theme" must be an array of colour values',

    // Small words
    'word.yes' => 'yes',
    'word.no'  => 'no',
];
