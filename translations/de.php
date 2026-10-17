<?php

/**
 * Deutsche Texte. Shared file — owned by the architect (SPEC.md §5). Every
 * key of translations/en.php must exist here as well (tests/I18nTest.php).
 */
return [
    // Browser-Dialog und die 401-Seite
    'realm'            => 'Vorschau',
    'denied.pageTitle' => 'Vorschau',
    'denied.heading'   => 'Diese Seite ist noch nicht öffentlich',
    'denied.text'      => 'Sie befindet sich im Aufbau und ist nur mit Zugangsdaten erreichbar.',

    // 503, solange die Konfiguration beschädigt ist (Text, nie Einzelheiten)
    'unavailable.text' => 'Die Vorschau ist vorübergehend nicht verfügbar.',

    // bin/sudare-hash
    'cli.hash.prompt'      => 'Passwort für die Vorschau: ',
    'cli.hash.visible'     => 'Achtung: shell_exec ist gesperrt, das Passwort ist beim Tippen sichtbar. Besser per Pipe: printf \'%s\\n\' "…" | vendor/bin/sudare-hash …',
    'cli.hash.empty'       => 'Kein Passwort eingegeben, Abbruch.',
    'cli.hash.short'       => 'Das Passwort ist kürzer als {min} Zeichen. Bitte etwas Längeres.',
    'cli.hash.long'        => 'Das Passwort ist länger als {max} Byte oder enthält ein NUL-Zeichen: bcrypt würde einen Teil ignorieren. Bitte ein kürzeres wählen.',
    'cli.hash.mismatch'    => 'Der erzeugte Hash bestätigt das Passwort nicht. Das darf nicht passieren.',
    'cli.hash.info'        => 'Verfahren: {algo}, Kosten {cost}',
    'cli.hash.output'      => 'Inhalt für das Secret {secret}:',
    'cli.hash.token'       => 'Maschinen-Token (wird nur einmal gezeigt — in den CI-Secrets ablegen): {token}',
    'cli.hash.usage'       => 'Aufruf: sudare-hash [--user=NAME] [--realm=TEXT] [--legacy] [--token] [--cost=4-31] [--language=en|de]',

    // bin/sudare-check
    'cli.check.none'   => 'Keine Sudare-Konfiguration unter {path}. Die Seite ist NICHT geschützt.',
    'cli.check.off'    => 'Sudare ist ausgeschaltet (active => false). Die Seite ist NICHT geschützt.',
    'cli.check.allowOff' => 'Nicht geschützt — wegen --allow-off akzeptiert (öffentlicher Deploy).',
    'cli.check.broken' => 'Die Sudare-Konfiguration ist beschädigt: {reason}. Jede Anfrage würde mit 503 beantwortet.',
    'cli.check.ok'     => 'Sudare-Konfiguration gültig ({source}): Benutzer „{user}“, {algo}-Hash, Realm „{realm}“.',
    'cli.check.token'  => 'Maschinen-Token: eingerichtet.',
    'cli.check.exempt' => 'Ausgenommene Pfade: {paths}',
    'cli.check.env'    => 'Aus der Umgebung mitgezählt (--env): {keys}.',
    'cli.check.files'  => 'Dateien über PHP: media/ {media}, assets/ {assets}.',
    'cli.check.style'  => 'Farben der 401-Seite unter einer Content-Security-Policy: {source} in style-src aufnehmen.',
    'cli.check.opcache' => 'opcache.restrict_api ist in diesem PHP gesetzt: Sudare kann die zwischengespeicherte Kopie der Datei nicht verwerfen, eine geänderte zählt erst, wenn opcache sie selbst neu prüft (revalidate_freq; nie bei validate_timestamps=0). {file} in opcache.blacklist_filename aufnehmen.',
    'cli.check.usage'  => 'Aufruf: sudare-check [PROJEKTWURZEL|KONFIGURATIONSDATEI] [--allow-off] [--env] [--language=en|de]',

    // Gründe, warum eine Konfiguration beschädigt ist (Config::reason())
    'reason.unreadable' => 'die Datei ist nicht lesbar',
    'reason.syntax'     => 'die Datei lässt sich nicht ausführen (Syntaxfehler oder Exception)',
    'reason.notArray'   => 'die Datei gibt kein Array zurück',
    'reason.exit'       => 'die Datei beendet das Skript (exit, die oder ein fataler Fehler)',
    'reason.active'     => '„active“ muss true oder false sein',
    'reason.user'       => '„user“ fehlt, ist leer oder enthält einen Doppelpunkt',
    'reason.hash'       => '„hash“ ist kein vollständiger Passwort-Hash (bcrypt oder Argon2) — beschädigt, abgeschnitten oder das Klartextpasswort? Erzeugen mit: vendor/bin/sudare-hash',
    'reason.example'    => 'der Beispielhash aus der Vorlage steht noch drin',
    'reason.token'      => '„token“ ist gesetzt, aber kein vollständiger Passwort-Hash (bcrypt oder Argon2)',
    'reason.realm'      => '„realm“ muss eine Zeichenkette sein',
    'reason.exempt'     => '„exempt“ muss eine Liste von Pfaden sein, die mit „/“ beginnen (nicht „/“ allein)',
    'reason.language'   => '„language“ muss en oder de sein',
    'reason.media'      => '„media“ muss true oder false sein',
    'reason.assets'     => '„assets“ muss true oder false sein',
    'reason.theme'      => '„theme“ muss ein Array mit Farbwerten sein',

    // Kleine Wörter
    'word.yes' => 'ja',
    'word.no'  => 'nein',
];
