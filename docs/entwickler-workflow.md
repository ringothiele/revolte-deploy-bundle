# Entwickler-Workflow

Diese Seite beschreibt den täglichen Arbeitsablauf mit revolte-deploy-tools — von der Ersteinrichtung bis zum Deployment.

---

## Konzept: Wann welcher Befehl?

Das Tool unterscheidet Deployment-Arten anhand der Frage: **Wer ist Source of Truth für den Content?**

### Full Deploy — Entwicklerphase

```bash
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:full stage
```

Einsatz: während der Entwicklung, bevor Redakteure Inhalte pflegen.

- Git-Stand wird auf Remote gebracht
- **Lokale Datenbank wird vollständig auf den Server übertragen**
- Content-Verzeichnisse (`files/*/content/` etc.) werden per rsync übertragen
- Composer, Cache, Migrationen, Dateisystem-Sync
- Vor dem Deploy wird automatisch ein Backup erstellt (Code + DB)

### Code Deploy — Redakteursphase

```bash
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:code stage
```

Einsatz: sobald Redakteure Inhalte auf Stage/Live pflegen.

- Git-Stand wird auf Remote gebracht
- **Datenbank und Content-Dateien bleiben unberührt**
- Composer, Cache, Migrationen, Dateisystem-Sync
- Vor dem Deploy wird automatisch ein Backup erstellt (nur Commit)

### Content Pull — Entwicklung aufnehmen

```bash
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:content:pull stage
```

Einsatz: wenn ein Entwickler den aktuellen Stand von Stage/Live in seine lokale Umgebung holen möchte.

- `git pull` aus dem Repository (Standard, überspringbar mit `--skip-git-pull`)
- Remote-Datenbank wird lokal importiert
- Content-Verzeichnisse werden per rsync lokal synchronisiert
- Hotfix-Verzeichnisse (`files/*/layout/hotfix/`) werden mitgeholt
- Lokale Felder wie `tl_page.dns` bleiben erhalten
- Lokaler Cache wird geleert, Migrationen werden ausgeführt

```bash
# Nur Dateien, keine DB
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:content:pull stage --skip-database

# Nur DB, keine Dateien
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:content:pull stage --skip-files

# Ohne git pull
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:content:pull stage --skip-git-pull
```

### Rollback — Notfallrückkehr

```bash
# Verfügbare Backups anzeigen
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:rollback stage --list

# Auf neuestes Backup zurück (fragt nach Bestätigung)
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:rollback stage

# Auf bestimmtes Backup
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:rollback stage --backup=20260603_143022_a1b2c3d4_full
```

Backups werden automatisch vor jedem Deploy erstellt und auf dem Server unter `~/revolte-deploy-backups/` gespeichert. Es werden die letzten 3 Backups behalten.

---

## CSS-Hotfixes auf Stage/Live

Manchmal muss eine kleine CSS-Änderung direkt auf Stage/Live gemacht werden, ohne den vollen Entwicklungszyklus. Dafür gibt es das Hotfix-Verzeichnis:

1. Datei `files/<projekt>/layout/hotfix/hotfix.scss` auf dem Server anlegen (via SSH oder FTP)
2. Bei nächstem Content Pull wird sie automatisch mitgeholt:
   ```bash
   APP_ENV=dev php vendor/bin/contao-console revolte:deploy:content:pull stage
   ```
3. Änderungen in die regulären SCSS-Dateien einarbeiten und Hotfix-Datei löschen

Das Hotfix-Verzeichnis ist gitigniert und taucht nie im Repository auf.

---

## Neues Projekt einrichten

### 1. Lokale Umgebung aufsetzen

Das Setup-Script erstellt eine DDEV-Umgebung mit leerem Contao:

```bash
# Script in leeren Projektordner kopieren und ausführen
cp /pfad/zu/revolte-deploy-tools/bin/revolte-setup ~/projekte/mein-projekt/
cd ~/projekte/mein-projekt
bash revolte-setup
```

Das Script fragt nach: Projektname, Contao-Version, PHP-Version, Webroot, MariaDB-Version.
Danach sind DDEV, Contao, Adminer, Mailpit und der Contao Manager eingerichtet.

### 2. revolte-deploy-tools einbinden

```bash
ddev composer require --dev revolte/contao-deploy-tools

# Konfiguration anlegen
cp vendor/revolte/contao-deploy-tools/resources/revolte_deploy.yaml.dist config/revolte_deploy.yaml
```

### 3. Grundkonfiguration anpassen

`config/revolte_deploy.yaml` öffnen — mindestens `project` und `git.repository` eintragen:

```yaml
project: mein-projekt
git:
  repository: git@github-mein-projekt:revolte/mein-projekt.git
```

Umgebungen (stage, live) werden im nächsten Schritt automatisch eingetragen.

### 4. SSH-Einrichtung — für jede Umgebung einmal

```bash
vendor/bin/revolte-ssh-setup
```

Das Script fragt alle Werte ab (Umgebungsname, SSH-Profil, Server, Branch, Entwicklerkürzel)  
und erledigt: Key-Prüfung, `~/.ssh/config`, ddev homeadditions, Key auf Server, `revolte_deploy.yaml`.  
Für eine weitere Umgebung (live, Serverumzug) einfach erneut ausführen.

Details: siehe [ssh-einrichtung.md](ssh-einrichtung.md)

### 5. Umgebung prüfen

```bash
ddev exec php bin/console revolte:deploy:doctor
ddev exec php bin/console revolte:deploy:check stage
```

### 6. Ersten Deploy ausführen

```bash
ddev exec php bin/console revolte:deploy:init stage
ddev exec php bin/console revolte:deploy:full stage
```

Server-Einrichtung nach Init: siehe [server-einrichtung.md](server-einrichtung.md)

---

## Bestehendes Projekt übernehmen (Repo vorhanden)

```bash
# 1. Lokale Umgebung aufsetzen (wie oben, Schritte 1–2)
# 2. Repo klonen
git clone git@github.com:revolte/mein-projekt.git .

# 3. Abhängigkeiten installieren
ddev composer install

# 4. Aktuellen Stand holen
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:content:pull stage
```

---

## Legacy-Projekt ohne Repo

1. Neues GitHub-Repository anlegen
2. Lokale Umgebung aufsetzen (Setup-Script, Schritte 1–2)
3. Server-Code per SSH/rsync lokal holen und als ersten Commit einchecken
4. Deploy Key auf dem Server einrichten (→ [ssh-einrichtung.md](ssh-einrichtung.md))
5. `config/revolte_deploy.yaml` anlegen und anpassen
6. Content Pull ausführen

---

## Täglicher Start

```bash
cd ~/projekte/mein-projekt
ddev start
ddev auth ssh
```

`ddev auth ssh` lädt deinen SSH-Key in den ddev-Container — nötig für alle Deploy-Commands.  
Du wirst einmalig nach der Passphrase gefragt. Muss nach jedem WSL-Neustart wiederholt werden.

Die SSH-Profile für alle konfigurierten Umgebungen sind bereits im Repo hinterlegt  
(`.ddev/homeadditions/.ssh/config.d/`) — kein manuelles Einrichten im ddev-Container nötig.  
Auf deinem Host-System (`~/.ssh/config`) trägst du die Profile weiterhin selbst ein,  
falls du SSH-Befehle direkt im Terminal nutzt (außerhalb von ddev).

---

## WSL2 vs. Mac — Unterschiede

### SSH-Agent

**WSL2:** muss manuell gestartet werden. Am einfachsten in `~/.bashrc` oder `~/.zshrc`:

```bash
if [ -z "$SSH_AUTH_SOCK" ]; then
    eval "$(ssh-agent -s)" > /dev/null
fi
```

Nach dem WSL-Start einmalig den Key laden:

```bash
ssh-add ~/.ssh/id_ed25519
```

**Mac:** SSH-Agent läuft automatisch über den macOS-Keychain. Kein manueller Start nötig.

### rsync

**WSL2/Ubuntu:** muss ggf. installiert werden:
```bash
sudo apt install rsync
```

**Mac:** vorinstalliert.

### Sonstiges

Alle Deploy-Commands funktionieren auf beiden Plattformen identisch. DDEV-Befehle sind ebenfalls gleich.

---

## Alle verfügbaren Commands

```bash
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:doctor               # Lokale Umgebung prüfen
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:status [env]         # Deploy-Stand aller Umgebungen
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:check <env>          # Zielumgebung prüfen
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:init <env>           # Ersteinrichtung Remote
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:full <env>           # Vollständiger Deploy
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:code <env>           # Code-Deploy (ohne DB)
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:code <env> --dry-run # Zeigt Commits + Änderungen
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:content:pull <env>   # Content von Remote holen
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:content:push <env>   # Neue lokale Records pushen
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:rollback <env>       # Rollback auf Backup
APP_ENV=dev php vendor/bin/contao-console revolte:deploy:explain <profil> <pfad>  # Regel erklären
```
