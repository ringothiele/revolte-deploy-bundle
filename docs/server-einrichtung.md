# Server-Einrichtung und .env.local

Diese Anleitung beschreibt die manuellen Schritte nach `revolte:deploy:init` — also alles was Zugangsdaten betrifft und bewusst außerhalb des Tools bleibt.

---

## .env.local auf dem Server anlegen

Nach dem Init per SSH auf den Server verbinden:

```bash
ssh PROFIL-NAME
nano /pfad/zum/projekt/.env.local
```

### Mindestinhalt

```
APP_ENV=prod
APP_SECRET=<zufälliger String, siehe unten>
DATABASE_URL="mysql://user:passwort@localhost:3306/datenbankname?serverVersion=8.0&charset=utf8mb4"
```

### APP_SECRET generieren

```bash
php -r "echo bin2hex(random_bytes(32));"
```

---

## DATABASE_URL — Sonderzeichen im Passwort

**Wichtig:** Wenn das Datenbankpasswort Sonderzeichen enthält (`@`, `#`, `/`, `?`, `=`, `&` usw.), muss es URL-kodiert werden. Sonst schlägt `parse_url()` in Contao fehl und die Console startet nicht (Exit 255, kein Output).

### Symptom

```
PHP Fatal error: Uncaught TypeError: str_replace(): Argument #3 ($subject) must be of type array|string, false given
in .../ContaoManager/Plugin.php
```

### Lösung

Passwort URL-kodieren. Contao bietet dafür ein Werkzeug:

→ **https://docs.contao.org/5.x/manual/de/system/einstellungen/#konvertieren-deiner-datenbank-parameter**

Dort einfach Host, Datenbankname, User und Passwort eingeben — der fertige DATABASE_URL-String wird generiert.

### Häufig problematische Zeichen

| Zeichen | Kodiert |
|---------|---------|
| `@`     | `%40`   |
| `#`     | `%23`   |
| `/`     | `%2F`   |
| `?`     | `%3F`   |
| `=`     | `%3D`   |
| `&`     | `%26`   |
| `+`     | `%2B`   |
| ` `     | `%20`   |

### Beispiel

Passwort `mein@Passwort#1` wird zu:

```
DATABASE_URL="mysql://user:mein%40Passwort%231@localhost:3306/dbname?serverVersion=8.0&charset=utf8mb4"
```

---

## .env.local nach Änderungen

Nach jeder Änderung an `.env.local` muss der Cache geleert werden:

```bash
php vendor/bin/contao-console cache:clear
```

Oder einfach den nächsten `revolte:deploy:full` ausführen — der erledigt das automatisch.

---

## Checkliste nach init

- [ ] `.env.local` angelegt mit `APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL`
- [ ] Passwort in DATABASE_URL URL-kodiert (Sonderzeichen prüfen)
- [ ] Verbindung testen: `php vendor/bin/contao-console --version` (sollte Versionsnummer ausgeben)
- [ ] `revolte:deploy:full stage` ausführen
