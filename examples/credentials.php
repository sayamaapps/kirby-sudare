<?php

/**
 * Legacy format, as the sites have it today (site/config/credentials.php).
 * Sudare still reads this file when site/config/sudare.php does not exist;
 * migrate with `vendor/bin/sudare-hash --legacy` (or to the new format with
 * `vendor/bin/sudare-hash --user=…`) and delete this file afterwards.
 *
 * This template is an example only: it never validates (the hash is a placeholder).
 */
return [
    'vorschau' => [
        'benutzer' => 'preview',
        'hash'     => '$2y$12$REPLACE.WITH.YOUR.OWN.HASH.OR.NOBODY.GETS.IN.AT.ALL',
        // 'realm' => 'Vorschau — Projektname',
    ],
];
