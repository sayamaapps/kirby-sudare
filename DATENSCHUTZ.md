# Sudare: technische Grundlage für den Datenschutztext

(English version: [PRIVACY.md](PRIVACY.md). Die Konfiguration heißt
`site/config/sudare.php`; die Optionen stehen im [README](README.md#configuration).)

Dieser Baustein beschreibt die Implementierung. Rechtsgrundlage, Interessenabwägung
und die Formulierung müssen vor Veröffentlichung für den konkreten Betrieb fachlich
geprüft und ergänzt werden. Der Text ist keine Rechtsberatung.

## Vorschau-Schutz — technischer Textbaustein

Solange unsere Website noch nicht öffentlich ist, ist sie mit einem Zugangsschutz
versehen. Wer sie aufrufen möchte, muss sich mit einem Benutzernamen und einem
Passwort anmelden. Die Zugangsdaten gibt der Browser bei jeder Anfrage an unseren
Webserver weiter, daher ist die Verbindung über HTTPS verschlüsselt.

Ihr Browser baut dafür **keine Verbindung zu einem anderen Dienst** auf: Es wird kein
Skript, keine Schriftart, keine Einbettung und kein Bild von einem fremden Server
nachgeladen. Das eingesetzte Modul setzt **keine Cookies** und verwendet **keinen
Browser-Speicher** (etwa Local Storage oder Session Storage). Die Zugangsdaten bleiben
im Rahmen der Sitzung in Ihrem Browser, nach dessen eigenen Regeln.

Der Server vergleicht die eingegebenen Angaben mit einem gespeicherten Hash, nie mit
dem Passwort im Klartext. Er speichert **keine Angaben zu Besucherinnen und Besuchern**:
kein eigenes Protokoll, keine Sitzung, keine Zähler für Fehlversuche. Die IP-Adresse
und der Browser-Typ werden für die Zugangsprüfung nicht ausgewertet.

Eine geschützte Seite antwortet mit einer neutralen Seite („Diese Seite ist noch nicht
öffentlich“). Sie nennt weder Benutzernamen noch Pfade. Der Schutz ist für Suchmaschinen
gekennzeichnet (`noindex`), damit die Vorschau nicht gefunden wird.

## Hinweise für den Betreiber

- **HTTPS.** Ohne verschlüsselte Verbindung wären Benutzername und Passwort im Klartext
  übertragbar. Der Zugangsschutz setzt HTTPS voraus, ebenso der Maschinen-Zugang per Token.
- **Zugriffsprotokoll.** Der Webserver kann bei geschützten Anfragen den Benutzernamen und
  bei jeder Anfrage die IP-Adresse protokollieren. Das Modul selbst tut das nicht. Die
  Aufbewahrungsfrist des Protokolls richtet sich nach der Datenschutzerklärung des Hosters
  und der Website.
- **Zugangsdaten getrennt übermitteln.** Benutzername und Passwort nicht in derselben
  Nachricht wie den Link und nicht öffentlich weitergeben. Das Passwort lang wählen.
- **Maschinen-Token geheim halten.** Er wird nur einmal angezeigt und gehört in die
  Geheimnisse der CI-Umgebung.
- **Ausnahmen sind offen.** Jeder Pfad, der in `exempt` eingetragen ist, ist ohne Anmeldung
  erreichbar. Eintragen nur, was ohne Anmeldung erreichbar sein muss (etwa ein Webhook).
- **Freigabe.** Mit der Freigabe der Website wird der Schutz entfernt: die Konfiguration
  wird nicht mehr ausgeliefert, oder `vendor/bin/sudare-check --allow-off` läuft im
  öffentlichen Deploy. Die Angaben zur Vorschau werden danach nicht mehr benötigt.
- **Übergabe der Website.** Wer die Website an einen Kunden übergibt, prüft, ob weitere
  Dienste (Analyse, Karten, Formulare) auf derselben Seite eingebunden sind; für diese gilt
  eine eigene Beschreibung. Dieses Modul gehört nicht dazu.
