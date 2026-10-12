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
