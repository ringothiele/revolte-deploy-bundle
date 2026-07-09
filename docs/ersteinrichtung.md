# Ersteinrichtung — Schritt für Schritt, ohne Vorwissen

Diese Anleitung führt von einem frisch aufgesetzten Contao (ddev läuft, Seite ist
lokal erreichbar) bis zum ersten erfolgreichen Deploy auf die Stage. **Es wird
nichts vorausgesetzt** — vor jedem Baustein steht ein Test, ob er schon vorhanden
ist, und falls nein, die konkreten Schritte, um ihn herzustellen.

> Diese Anleitung dient zugleich als Validierungsprotokoll: Wenn ein Schritt sich
> nicht exakt so ausführen lässt wie beschrieben, ist das ein Fehler — bitte
> notieren, an welcher Stelle und mit welcher Meldung.

## Bevor du anfängst

**Zwei Terminals, ein Unterschied.** Befehle laufen entweder auf deinem Rechner
(**[HOST]** — unter Windows immer das WSL2/Ubuntu-Terminal, nie PowerShell; am Mac
das normale Terminal) oder im ddev-Container (**[HOST]**-Befehle, die mit
`ddev exec` beginnen, laufen automatisch im Container). Alle Befehle werden im
Projektordner ausgeführt (z. B. `~/projekte/mein-projekt`).

**Das musst du bereitlegen** (beim Team-Lead erfragen, falls unklar):

- [ ] GitHub-Account mit Zugriff auf `ringothiele/revolte-deploy-bundle` und dem Recht, ein neues Repository in der Organisation anzulegen
- [ ] SSH-Zugangsdaten der Stage: Host, Port, Benutzer, Passwort (oder Zugang zum Hosting-Panel, z. B. Hetzner KonsoleH)
- [ ] Datenbank-Zugangsdaten der Stage (Host, DB-Name, Benutzer, Passwort) — oder das Recht, im Panel eine DB anzulegen
- [ ] Dein Entwicklerkürzel (rt, pl, sl)

**Warum KI hier tabu ist:** Der Server sperrt IPs schon nach einem einzigen
fehlgeschlagenen SSH-Login für 30+ Minuten (fail2ban). Deshalb führt **jeden**
Befehl dieser Anleitung ein Mensch aus. Eine KI darf Befehle erklären und
vorbereiten — ausführen niemals.

---

## Schritt 0 — Ausgangslage prüfen

**Was & warum:** Wir stellen sicher, dass die Basis steht: ddev läuft und das
lokale Contao ist vollständig installiert (Datenbank migriert, Admin-Benutzer
vorhanden). Der spätere Full Deploy überträgt genau diese lokale Datenbank auf
die Stage — sie muss also brauchbar sein.

**Prüfen:** [HOST]

```bash
ddev describe
```

Erwartet: Eine Tabelle mit Projektname und URLs, Status `OK`. Danach im Browser
`https://<projektname>.ddev.site/contao` öffnen und einloggen.

**Wenn ddev nicht läuft:** `ddev start` ausführen.

**Wenn das Backend nicht erreichbar ist oder kein Login existiert:** [HOST]

```bash
ddev exec php vendor/bin/contao-console contao:migrate --no-interaction
ddev exec php vendor/bin/contao-console contao:user:create
```

(Der zweite Befehl fragt Benutzername, Passwort usw. interaktiv ab — als
Administrator anlegen.)

**Erfolgskriterium:** Backend-Login unter `https://<projektname>.ddev.site/contao`
funktioniert.

---

## Schritt 1 — GitHub-Zugriff aus dem Container herstellen

**Was & warum:** Das Deploy-Bundle liegt in einem privaten GitHub-Repository.
Composer läuft bei uns im ddev-Container — der Container braucht also SSH-Zugriff
auf GitHub, sonst schlägt schon die Installation des Bundles fehl. (GitHub sperrt
bei Fehlversuchen nicht — dieser Test ist gefahrlos.)

**Prüfen:** [HOST]

```bash
ddev exec ssh -T git@github.com
```

Erwartet: `Hi <dein-github-name>! You've successfully authenticated, ...`
(Eine eventuelle Frage nach dem Server-Fingerprint mit `yes` bestätigen. Dass
danach „GitHub does not provide shell access" steht, ist normal und kein Fehler.)

**Wenn stattdessen `Permission denied` kommt**, fehlt der Weg vom Container zu
GitHub. Der Reihe nach:

1. Hast du überhaupt einen GitHub-Key? [HOST]

   ```bash
   ls ~/.ssh/*.pub
   ```

   Liegt dort ein Key, den du bei GitHub hinterlegt hast (typisch:
   `<kürzel>_github_ed25519.pub`)? Dann weiter bei 3.

2. Key erzeugen und bei GitHub hinterlegen: [HOST]

   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/<kürzel>_github_ed25519 -C "<kürzel>-github"
   cat ~/.ssh/<kürzel>_github_ed25519.pub
   ```

   Beim `ssh-keygen` eine Passphrase wählen und **merken** — sie wird gleich noch
   einmal gebraucht. Die ausgegebene Zeile (beginnt mit `ssh-ed25519`) komplett
   kopieren und bei GitHub eintragen: github.com → Profilbild → **Settings** →
   **SSH and GPG keys** → **New SSH key** → einfügen, speichern.

3. Keys in den Container-Agenten laden: [HOST]

   ```bash
   ddev auth ssh
   ```

   Das lädt deine Keys aus `~/.ssh/` in den SSH-Agenten von ddev und fragt dabei
   die Passphrase ab. Das hält bis zum nächsten Rechner-/ddev-Neustart — danach
   einfach `ddev auth ssh` wiederholen.

4. Test von oben wiederholen.

**Erfolgskriterium:** `ddev exec ssh -T git@github.com` meldet
`Hi <dein-github-name>!`.

---

## Schritt 2 — Deploy-Bundle installieren

**Was & warum:** Das Bundle bringt alle `revolte:deploy:*`-Befehle sowie die
Setup-Scripts und Vorlagen ins Projekt. Es wird als Dev-Abhängigkeit installiert
und ist nur in der lokalen Dev-Umgebung aktiv — auf dem Server läuft es nie.

**Machen:** [HOST]

```bash
ddev composer config repositories.revolte-deploy-tools '{"type":"vcs","url":"git@github.com:ringothiele/revolte-deploy-bundle.git"}'
ddev composer require --dev "revolte/contao-deploy-tools:@dev"
```

**Erfolgskriterium:** [HOST]

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:doctor --env=dev
```

Der Befehl läuft durch und zeigt eine Prüfliste an (gelbe `!`-Punkte sind jetzt
noch normal — genau die arbeiten wir in den nächsten Schritten ab).

**Wenn `command not found` / Befehl unbekannt:** Das `--env=dev` fehlt — das
Bundle ist absichtlich nur in der Dev-Umgebung geladen.

---

## Schritt 3 — Deploy-Konfiguration anlegen

**Was & warum:** Die Datei `config/revolte_deploy.yaml` beschreibt das Projekt
und seine Zielumgebungen (Stage, Live). Sie enthält **keine Secrets** und gehört
mit ins Git-Repository, damit alle Entwickler dieselbe Deploy-Konfiguration nutzen.

**Machen:** [HOST]

```bash
mkdir -p config
cp vendor/revolte/contao-deploy-tools/resources/revolte_deploy.yaml.dist config/revolte_deploy.yaml
```

Dann die Datei im Editor öffnen und **nur zwei Werte** anpassen (den Rest trägt
später das SSH-Setup-Script ein):

```yaml
project: <projektname>          # z. B. mein-projekt

git:
  remote: origin
  repository: git@github.com:<organisation>/<projektname>.git   # das Repo aus Schritt 4
```

**Erfolgskriterium:** Datei existiert, `project:` und `git.repository:` sind gesetzt.

---

## Schritt 4 — Projekt in Git und auf GitHub bringen

**Was & warum:** Deployments laufen bei uns git-first: Der Server holt sich den
Code per `git pull` aus dem GitHub-Repository — nicht per Datei-Upload. Ohne
gepushtes Repository gibt es also nichts zu deployen.

**Prüfen:** [HOST]

```bash
git status
```

- `fatal: not a git repository` → weiter bei „Machen".
- Zeigt einen Branch und `origin` existiert (`git remote -v`) → weiter zu Schritt 5.

**Machen:**

1. Ist Git vorgestellt? [HOST]

   ```bash
   git config --get user.name && git config --get user.email
   ```

   Kommt nichts, einmalig setzen:

   ```bash
   git config --global user.name "Vorname Nachname"
   git config --global user.email "<kürzel>@die-revolte.de"
   ```

2. `.gitignore` prüfen: `cat .gitignore` — falls die Datei fehlt oder Einträge
   fehlen, diesen Stand herstellen (Contao liefert einen Teil oft schon mit):

   ```gitignore
   /vendor/
   /var/
   /.env.local
   /.env.*.local
   /public/assets/
   /public/bundles/
   /public/contao-manager.phar.php
   /files/*/content/
   /files/*/downloads/
   /files/*/user_upload/
   ```

   (Warum: Abhängigkeiten und Caches werden auf dem Server neu erzeugt, Secrets
   bleiben lokal, und Redaktions-Inhalte werden nicht über Git, sondern über die
   Content-Befehle synchronisiert.)

3. Repository anlegen und erster Commit: [HOST]

   ```bash
   git init -b main
   git add -A
   git commit -m "Initiales Contao-Setup"
   ```

4. Leeres Repository auf GitHub anlegen: github.com → **New repository** →
   Name = `<projektname>`, **Private**, **ohne** README/gitignore anlegen.

5. Verbinden und pushen — plus den `develop`-Branch, denn bei uns gilt:
   **main = Live, develop = Stage**: [HOST]

   ```bash
   git remote add origin git@github.com:<organisation>/<projektname>.git
   git push -u origin main
   git switch -c develop
   git push -u origin develop
   ```

**Erfolgskriterium:** Das Repository ist auf GitHub sichtbar und enthält beide
Branches; lokal bist du auf `develop` (`git branch --show-current`).

---

## Schritt 5 — KI-Schutz aktivieren

**Was & warum:** Damit eine KI (Claude Code, Codex) niemals selbst SSH-Verbindungen
aufbaut oder Deployments auslöst, wird das technisch verboten, nicht nur
dokumentiert. Für Claude Code gibt es dafür eine fertige Deny-Liste, die ins
Projekt committet wird und dann für alle Entwickler gilt.

**Machen:** [HOST]

```bash
mkdir -p .claude
cp vendor/revolte/contao-deploy-tools/resources/ki/claude-settings.dist.json .claude/settings.json
git add .claude/settings.json && git commit -m "KI-Schutz: SSH- und Deploy-Befehle für Claude Code gesperrt"
```

Wird im Projekt auch Codex genutzt: den Abschnitt aus
`vendor/revolte/contao-deploy-tools/resources/ki/AGENTS-hinweis.md` in die
`AGENTS.md` des Projekts übernehmen.

**Erfolgskriterium:** `.claude/settings.json` liegt im Projekt und ist committet.

---

## Schritt 6 — SSH-Zugang zur Stage einrichten

**Was & warum:** Jetzt kommt der Kern: Ein eigener SSH-Key für dich, die passenden
SSH-Profile auf deinem Rechner **und** im ddev-Container, und der Eintrag der
Umgebung in der Deploy-Konfiguration. Das erledigt ein Script in einem Rutsch —
es ist bewusst so gebaut, dass es pro Lauf höchstens **einen** Verbindungsversuch
macht (fail2ban-Schutz) und jederzeit gefahrlos wiederholt werden kann.

**Jetzt bereithalten:** Host, Port, Benutzer und Passwort der Stage
(bzw. Panel-Zugang).

**Machen:** [HOST]

```bash
vendor/bin/revolte-ssh-setup stage
```

Das Script führt durch alles durch. Was dabei passiert und was es fragt:

1. **Entwicklerkürzel** (nur beim allerersten Lauf auf diesem Rechner — wird
   dauerhaft gespeichert).
2. **Serverdaten** (Host, Port, Benutzer, Remote-Pfad, Branch) sowie der
   **Server-Account für die Key-Benennung** (für die Stage: `revolte-labor`).
   Beim ersten Stage-Setup im Team tippst du sie ein; das Script bietet danach
   an, sie als Team-Defaults zu speichern — bei späteren Projekten sind sie dann
   vorbefüllt. Branch für Stage ist `develop` (Vorgabe einfach bestätigen).
3. **SSH-Key**: Existiert noch keiner nach der Namenskonvention, ruft das Script
   `ssh-keygen` auf — hier wählst du eine Passphrase (2× eingeben, merken).
4. **Configs & Container**: Das Script schreibt die SSH-Profile, startet ddev bei
   Bedarf neu und lädt die Keys per `ddev auth ssh` in den Agenten (hier kommt
   die Passphrase-Abfrage).
5. **Key auf den Server**: Das Script fragt, ob der Public Key schon auf dem
   Server liegt (beim ersten Mal: nein). Dann zeigt es zwei Wege — Eintrag übers
   Hosting-Panel oder ein fertiger Befehl, der **einmal** das Server-Passwort
   abfragt. Einen davon ausführen, dann im Script Enter drücken.
6. **Ein Verbindungstest.** Bei Erfolg bist du fertig.

**Wenn der Verbindungstest fehlschlägt:** Das Script erklärt das Fehlerbild und
setzt einen **35-Minuten-Cooldown** für diesen Server. Das ist Absicht — jeder
weitere Fehlversuch würde eine mögliche fail2ban-Sperre verlängern. Ursache in
Ruhe beheben (steht in der Fehlermeldung), warten, dann:

```bash
vendor/bin/revolte-ssh-setup stage --check
```

`--check` ändert nichts, prüft alle Bausteine einzeln und macht höchstens einen
Verbindungsversuch — es zeigt dir genau, welcher Baustein noch fehlt.

**Erfolgskriterium:** Das Script endet mit
`=== SSH-Einrichtung abgeschlossen — Profil '<projektname>-stage' ist einsatzbereit ===`.

---

## Schritt 7 — Deploy-Key: der Server braucht Lesezugriff auf GitHub

**Was & warum:** Beim Deploy holt sich der **Server** den Code selbst von GitHub
(`git pull`). Dafür braucht der Server einen eigenen SSH-Key, der im
GitHub-Repository als „Deploy Key" (nur Lesen) hinterlegt wird. Das ist ein
anderer Key als deiner — er gehört dem Server, nicht dir.

**Machen:**

1. Auf den Server verbinden (funktioniert jetzt dank Schritt 6): [HOST]

   ```bash
   ssh <projektname>-stage
   ```

2. Auf dem Server prüfen, ob schon ein Key existiert: [SERVER]

   ```bash
   cat ~/.ssh/id_ed25519.pub
   ```

   Kommt eine `ssh-ed25519 ...`-Zeile → direkt zu 4. Kommt „No such file":

3. Key auf dem Server erzeugen — **ohne Passphrase**, weil der Server ihn beim
   Deploy unbeaufsichtigt benutzen muss: [SERVER]

   ```bash
   ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C "deploy-<projektname>-stage"
   cat ~/.ssh/id_ed25519.pub
   ```

4. Die ausgegebene Zeile kopieren und bei GitHub eintragen: Repository →
   **Settings** → **Deploy keys** → **Add deploy key** → Titel z. B.
   `stage-server`, Key einfügen, **kein** Schreibzugriff, speichern.

5. Vom Server aus testen (GitHub sperrt nicht — gefahrlos): [SERVER]

   ```bash
   ssh -T git@github.com
   exit
   ```

**Erfolgskriterium:** Der Test auf dem Server meldet `Hi <organisation>/<projektname>!`
(bei Deploy Keys meldet GitHub den Repo-Namen statt eines Benutzernamens).

---

## Schritt 8 — Subdomain auf das Zielverzeichnis zeigen lassen

**Was & warum:** Contao liefert seine Seiten aus dem Unterordner `public/` aus.
Die Stage-Subdomain (`<projektname>.revolte-labor.de`) muss deshalb im
Hosting-Panel auf `<remote-pfad>/public` zeigen — sonst sieht man später
Verzeichnislisten oder 403-Fehler statt der Website.

**Machen:** Im Hosting-Panel (Hetzner: KonsoleH) die Subdomain
`<projektname>.revolte-labor.de` anlegen bzw. bearbeiten und als Zielverzeichnis
`<remote-pfad>/public` eintragen. SSL/Zertifikat aktivieren, falls das Panel es
anbietet.

**Wichtig:** Falls das Panel dabei den Zielordner mit Platzhalter-Dateien anlegt
(`index.html` o. ä.), diese wieder löschen — der nächste Schritt verlangt einen
**leeren** Ordner.

**Erfolgskriterium:** Subdomain existiert und zeigt auf `<remote-pfad>/public`
(dass sie noch einen Fehler anzeigt, ist okay — dort liegt ja noch nichts).

---

## Schritt 9 — Diagnose vor dem ersten Deploy

**Was & warum:** Bevor irgendetwas auf den Server geht, prüfen zwei Befehle die
komplette Kette: lokale Werkzeuge, Konfiguration, SSH-Profil, Git-Zustand und die
echte Verbindung. Fehler tauchen so hier auf — nicht mitten im Deploy.

**Machen:** [HOST]

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:doctor --env=dev
ddev exec php vendor/bin/contao-console revolte:deploy:check stage --env=dev
```

**Erfolgskriterium:** `doctor` meldet „Lokale Umgebung ist bereit", `check`
meldet „Umgebung *stage* ist bereit für ein Deployment". Bei gelben/roten
Punkten: Die Meldungen enthalten jeweils den konkreten Befehl zur Behebung —
abarbeiten und erneut prüfen.

---

## Schritt 10 — Server initialisieren (einmalig)

**Was & warum:** `deploy:init` richtet die Stage erstmalig ein: Es klont das
GitHub-Repository in den (leeren) Zielordner und installiert die
PHP-Abhängigkeiten auf dem Server. Datenbank kommt im nächsten Schritt.

**Machen:** [HOST]

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:init stage --env=dev
```

Am Ende gibt der Befehl „Nächste Schritte" aus — die machen wir jetzt. Auf dem
Server muss die Datei `.env.local` angelegt werden: Sie enthält die
**Datenbank-Zugangsdaten der Stage** und bleibt als einzige Datei nur auf dem
Server (deshalb kann sie kein Script für dich schreiben).

1. [HOST] `ssh <projektname>-stage`
2. [SERVER] `nano <remote-pfad>/.env.local` — Inhalt (Werte aus deiner
   Unterlagen-Checkliste; das `APP_SECRET` hat der init-Befehl fertig generiert
   ausgegeben — einfach von dort kopieren):

   ```
   APP_ENV=prod
   APP_SECRET=<aus-der-init-ausgabe>
   DATABASE_URL="mysql://benutzer:passwort@localhost:3306/dbname"
   ```

   **Achtung:** Sonderzeichen im DB-Passwort (`@`, `#`, `/` …) müssen
   URL-kodiert werden (z. B. `@` → `%40`), sonst startet Contao nicht.

3. Direkt auf dem Server testen, ob Contao mit der Konfiguration startet: [SERVER]

   ```bash
   php <remote-pfad>/vendor/bin/contao-console --version
   exit
   ```

**Erfolgskriterium:** Der Test gibt eine Contao-Versionsnummer aus, keinen Fehler.

**Wenn stattdessen ein Fehler kommt:**
- Datenbank-Fehler → `DATABASE_URL` prüfen (Tippfehler, URL-Kodierung, stimmt der DB-Host?).
- PHP-Versions-Fehler (z. B. „requires PHP >= 8.1") → der CLI-Standard-PHP des
  Servers ist zu alt. In `config/revolte_deploy.yaml` bei der Stage-Umgebung
  `php_cli: /usr/bin/php83` (bzw. die passende Version) eintragen und den Test
  auf dem Server mit diesem Binary wiederholen.

---

## Schritt 11 — Erster Full Deploy

**Was & warum:** Der Full Deploy bringt alles auf die Stage: Code (per Git),
deine lokale Datenbank und die Upload-Dateien. Danach ist die Stage eine Kopie
deines lokalen Stands — inklusive deines Backend-Logins.

**Vorher:** Alle lokalen Änderungen committen und pushen (der Deploy verweigert
sonst zu Recht den Dienst — deployt wird immer nur, was im Repository ist):
[HOST]

```bash
git add -A && git commit -m "Deploy-Konfiguration" && git push
```

**Machen:** Erst ansehen, was passieren würde (völlig gefahrlos), dann echt:
[HOST]

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:full stage --dry-run --env=dev
ddev exec php vendor/bin/contao-console revolte:deploy:full stage --env=dev
```

**Erfolgskriterium:** Der Befehl endet mit „Full Deploy auf *stage* erfolgreich
abgeschlossen", und:

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:status stage --env=dev
```

zeigt für stage den gleichen Commit wie lokal (`✓ aktuell`). Im Browser:
`https://<projektname>.revolte-labor.de` zeigt die Website,
`/contao` akzeptiert deinen lokalen Admin-Login.

**Wenn die Startseite auf der Stage nicht lädt, lokal aber schon:** Im
Stage-Backend unter Seitenstruktur prüfen, ob am Startpunkt der Website ein
DNS-Eintrag (`<projektname>.ddev.site`) hinterlegt ist — den für die Stage
leeren oder auf die Stage-Domain ändern. (Tipp für die Zukunft: lokal den
DNS-Eintrag der Startseite leer lassen.)

---

## Geschafft — und wie es weitergeht

Die Stage steht. Ab jetzt ist der Alltag kurz:

| Situation | Befehl |
| --- | --- |
| Code geändert → auf Stage bringen | `git push`, dann `revolte:deploy:code stage` |
| Abhängigkeiten/Contao aktualisiert | `revolte:deploy:full stage` |
| Redaktions-Inhalte von Stage holen | `revolte:deploy:content:pull stage` |
| Irgendwas klemmt | `revolte:deploy:doctor` bzw. `vendor/bin/revolte-ssh-setup stage --check` |

Die Live-Umgebung kommt später mit denselben Schritten 6–11 dazu
(`vendor/bin/revolte-ssh-setup live ...`) — siehe
[Szenario 5](szenarien/szenario-5-zweite-umgebung-manuell.md).
