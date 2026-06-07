# Szenario 2: Bestehendes Projekt — Manuelle Einrichtung

Dieses Szenario beschreibt die Einrichtung von revolte-deploy-tools für ein Projekt, das
bereits lokal mit ddev läuft, aber noch kein GitHub-Repository und noch keine
Deployment-Infrastruktur hat.

---

## Voraussetzungen

- Lokales Projekt läuft bereits mit ddev
- SSH-Zugang zum Server
- GitHub-Account mit Schreibrecht auf das Ziel-Repository

---

## 1. revolte-deploy-bundle installieren

```bash
ddev exec composer require revolte/contao-deploy-tools
```

Das Bundle wird als Composer-Abhängigkeit installiert und registriert sich automatisch über
den Contao Manager Plugin Mechanismus. Die YAML-Konfiguration wird im nächsten Schritt durch
`revolte-ssh-setup` angelegt.

**Mögliche Probleme:**
- Bundle nicht gefunden: Composer-Repository prüfen, ggf. `ddev exec composer update`
- Befehl `revolte:deploy:*` nicht verfügbar: `--env=dev` fehlt oder ddev neu starten

---

## 2. Server-Verzeichnis anlegen

Melde dich auf dem Server an und lege das Projektverzeichnis an:

```
/usr/www/users/SERVERACCOUNT/PROJEKTNAME
```

Das Verzeichnis muss leer sein — `revolte:deploy:init` bricht bei vorhandenen Dateien ab.

**Mögliche Probleme:**
- Kein SSH-Zugang: Zugang über Hoster-Panel beantragen
- Falscher Pfad: Mit `pwd` prüfen, Pfad exakt notieren — er wird in der YAML benötigt

---

## 3. SSH-Einrichtung (revolte-ssh-setup)

```bash
vendor/bin/revolte-ssh-setup
```

Das Skript richtet alles ein, was für die Verbindung zwischen lokalem Projekt und Server
benötigt wird:

- **SSH-Key erstellen** — Namenskonvention: `KUERZEL_PROJEKTNAME-UMGEBUNG_ed25519`
  (z. B. `rt_meinprojekt-stage_ed25519`). Den Key mit Passphrase erstellen — diese wird
  bei `ddev auth ssh` abgefragt.
- **SSH-Profil in `~/.ssh/config`** — kurzer Profilname statt IP und Port
- **homeadditions** — SSH-Konfiguration im ddev-Container
  (`.ddev/homeadditions/.ssh/config.d/revolte.conf`), ohne IdentityFile, da der
  ddev-Agent den Key verwaltet
- **`config/revolte_deploy.yaml`** — Projektname, Server-Pfad, Branch und SSH-Profil

Den Public Key, den das Skript am Ende ausgibt, für Schritt 4 und 5 bereithalten.

**Mögliche Probleme:**
- Key wird vom ddev-Agent nicht erkannt: `ddev auth ssh` ausführen und Passphrase eingeben
- Profil bereits vorhanden mit anderem Key: Skript fragt nach — mit `j` bestätigen
- SSH-Verbindungstest schlägt fehl: Public Key muss zuerst auf dem Server hinterlegt werden

---

## 4. Git-Repository anlegen und pushen

Git initialisieren:

```bash
git init
git branch -M main
```

`.gitignore` prüfen — folgende Verzeichnisse gehören nicht ins Repository:

```
/vendor/
/var/
/web/assets/
/web/files/
/.env.local
```

GitHub-Repository anlegen und als Remote setzen:

```bash
gh repo create ORGANISATION/PROJEKTNAME --private
git remote add origin git@github.com:ORGANISATION/PROJEKTNAME.git
```

Initialen Commit erstellen und pushen:

```bash
git add .
git commit -m "Initial commit"
git push -u origin main
```

**Mögliche Probleme:**
- Push schlägt fehl: `git remote -v` prüfen
- GitHub-Authentifizierung: `gh auth login` ausführen
- Zu viele Dateien im Commit: `.gitignore` prüfen, insbesondere `vendor/` und `var/`

---

## 5. Deploy Key auf dem Server einrichten

Damit der Server Code direkt von GitHub pullen kann, braucht er einen eigenen SSH-Key.

**Auf dem Server:**

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_PROJEKTNAME_github -C "deploy@PROJEKTNAME"
```

SSH-Config auf dem Server ergänzen (`~/.ssh/config`):

```
Host github-PROJEKTNAME
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519_PROJEKTNAME_github
    IdentitiesOnly yes
```

Public Key ausgeben:

```bash
cat ~/.ssh/id_ed25519_PROJEKTNAME_github.pub
```

**Auf GitHub:** Repository → Settings → Deploy keys → Add deploy key
- Title: z. B. `stage-server`
- Key einfügen
- Allow write access: **nicht** aktivieren

Verbindung testen:

```bash
ssh -T github-PROJEKTNAME
```

**Mögliche Probleme:**
- `Permission denied`: Public Key nicht korrekt hinterlegt
- `Host key verification failed`: einmalig manuell verbinden und bestätigen

---

## 6. Server initialisieren (revolte:deploy:init)

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:init stage --env=dev
```

Richtet die Release-Struktur auf dem Server ein und klont das GitHub-Repository.

> **Hinweis:** `--env=dev` ist immer erforderlich — das Bundle wird nur in der Dev-Umgebung
> geladen.

**Mögliche Probleme:**
- `Verzeichnis ist nicht leer`: Server-Verzeichnis aus Schritt 2 muss leer sein
- `Git clone fehlgeschlagen`: Deploy Key und GitHub-Konfiguration auf dem Server prüfen

---

## 7. Ersten Deploy ausführen (revolte:deploy:full)

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:full stage --env=dev
```

Überträgt den Code, führt Composer install auf dem Server aus, migriert die Datenbank und
setzt den Release aktiv.

**Mögliche Probleme:**
- Composer schlägt fehl: PHP-Version auf dem Server prüfen (`php_cli` in revolte_deploy.yaml)
- Migration schlägt fehl: Contao-Installation auf dem Server prüfen

---

## Ergebnis

Die Stage-Umgebung ist live. Workflow für weitere Deployments:

```bash
# Code deployen
ddev exec php vendor/bin/contao-console revolte:deploy:code stage --env=dev

# Content (DB + Dateien) vom Server holen
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull stage --env=dev
```
