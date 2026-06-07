# Szenario 1: Neues Projekt — Manuelle Einrichtung

Dieses Szenario beschreibt die Einrichtung eines neuen Contao-Projekts von Grund auf:
lokale Entwicklungsumgebung, GitHub-Repository und Staging-Server werden gemeinsam aufgesetzt.

---

## Voraussetzungen

- ddev ist lokal installiert
- `revolte-setup` ist verfügbar
- SSH-Zugang zum Server (Passwort oder bestehender Key)
- GitHub-Account mit Schreibrecht auf das Ziel-Repository

---

## 1. Server-Verzeichnis anlegen

Melde dich auf dem Server an und lege das Projektverzeichnis an. Der Pfad folgt der Konvention
des Hosters — bei Hetzner-Managed-Hosting typischerweise:

```
/usr/www/users/SERVERACCOUNT/PROJEKTNAME
```

Das Verzeichnis muss leer sein. `revolte:deploy:init` erwartet ein leeres Verzeichnis und
bricht sonst ab.

**Mögliche Probleme:**
- Kein SSH-Zugang: Zugang über Hoster-Panel (z. B. Plesk) oder Support beantragen
- Falscher Pfad: Mit `pwd` auf dem Server prüfen, Pfad exakt notieren — er wird später in der YAML benötigt

---

## 2. Lokales Projekt erstellen (revolte-setup)

```bash
revolte-setup
```

Das Skript richtet ddev ein, installiert Contao und das revolte-deploy-bundle. Folge den
Anweisungen des Skripts. Am Ende hast du eine lauffähige lokale Contao-Installation.

**Mögliche Probleme:**
- ddev startet nicht: `ddev restart` oder Docker prüfen
- Port-Konflikt: ddev-Konfiguration anpassen (`router_http_port`, `router_https_port`)

---

## 3. SSH-Einrichtung (revolte-ssh-setup)

```bash
vendor/bin/revolte-ssh-setup
```

Das Skript führt durch die vollständige SSH-Einrichtung für eine neue Umgebung:

- **SSH-Key erstellen** — Namenskonvention: `KUERZEL_PROJEKTNAME-UMGEBUNG_ed25519`
  (z. B. `rt_meinprojekt-stage_ed25519`). Der Key wird mit Passphrase erstellt — diese
  Passphrase musst du dir merken, sie wird bei `ddev auth ssh` abgefragt.
- **SSH-Profil in `~/.ssh/config`** — damit du den Server über einen kurzen Profilnamen
  erreichst statt über IP und Port
- **homeadditions** — SSH-Konfiguration innerhalb des ddev-Containers
  (`.ddev/homeadditions/.ssh/config.d/revolte.conf`), ohne IdentityFile-Eintrag, da der
  ddev-Agent den Key verwaltet
- **`config/revolte_deploy.yaml`** — Projektname, Server-Pfad, Branch und SSH-Profil werden
  eingetragen

Notiere den Public Key, den das Skript am Ende ausgibt — er wird in Schritt 4 und 5 benötigt.

**Mögliche Probleme:**
- Key wird vom ddev-Agent nicht erkannt: `ddev auth ssh` ausführen und Passphrase eingeben
- Profil bereits vorhanden mit anderem Key: Skript fragt nach, ob das IdentityFile aktualisiert
  werden soll — mit `j` bestätigen
- SSH-Verbindungstest schlägt fehl: Prüfen ob Public Key auf dem Server hinterlegt ist (Schritt 4)

---

## 4. GitHub-Repository anlegen

Lege das Repository auf GitHub an (über die Weboberfläche oder `gh`):

```bash
gh repo create ORGANISATION/PROJEKTNAME --private
```

Danach Remote im lokalen Projekt setzen:

```bash
git remote add origin git@github.com:ORGANISATION/PROJEKTNAME.git
```

Initialen Commit erstellen und pushen:

```bash
git add .
git commit -m "Initial commit"
git push -u origin main
```

**Mögliche Probleme:**
- Push schlägt fehl: `git remote -v` prüfen, ob die URL korrekt ist
- GitHub-Authentifizierung: `gh auth login` ausführen

---

## 5. Deploy Key auf dem Server einrichten

Damit der Server Code direkt von GitHub pullen kann, braucht er einen eigenen SSH-Key
(GitHub Deploy Key). Dieser Key hat nur Lesezugriff auf das Repository.

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

Den Public Key ausgeben:

```bash
cat ~/.ssh/id_ed25519_PROJEKTNAME_github.pub
```

**Auf GitHub:** Repository → Settings → Deploy keys → Add deploy key
- Title: z. B. `stage-server`
- Key: Public Key einfügen
- Allow write access: **nicht** aktivieren

Verbindung vom Server testen:

```bash
ssh -T github-PROJEKTNAME
```

**Mögliche Probleme:**
- `Permission denied`: Public Key nicht korrekt hinterlegt — nochmals prüfen
- `Host key verification failed`: GitHub-Fingerprint noch nicht in `known_hosts` — einmalig
  manuell verbinden und bestätigen

---

## 6. Server initialisieren (revolte:deploy:init)

Der `init`-Befehl richtet die Verzeichnisstruktur auf dem Server ein (releases, shared,
current) und klont das GitHub-Repository. Dafür muss der Deploy Key aus Schritt 5 korrekt
eingerichtet sein.

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:init stage --env=dev
```

> **Hinweis:** `--env=dev` ist immer erforderlich, da das Bundle nur in der Dev-Umgebung
> geladen wird.

**Mögliche Probleme:**
- `Verzeichnis ist nicht leer`: Server-Verzeichnis aus Schritt 1 muss leer sein
- `Git clone fehlgeschlagen`: Deploy Key auf dem Server oder GitHub-Konfiguration prüfen
- Befehl nicht gefunden: `--env=dev` fehlt oder Bundle nicht korrekt installiert

---

## 7. Ersten Deploy ausführen (revolte:deploy:full)

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:full stage --env=dev
```

Dieser Befehl überträgt den Code (via Git), führt Composer install auf dem Server aus,
migriert die Datenbank und setzt den neuen Release aktiv.

**Mögliche Probleme:**
- Composer schlägt fehl: PHP-Version auf dem Server prüfen (`php_cli` in revolte_deploy.yaml)
- Datenbank-Migration schlägt fehl: Contao-Installation auf dem Server prüfen

---

## Ergebnis

Nach Schritt 7 ist die Stage-Umgebung live. Der Workflow für weitere Deployments:

```bash
# Code deployen
ddev exec php vendor/bin/contao-console revolve:deploy:code stage --env=dev

# Content (DB + Dateien) vom Server holen
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull stage --env=dev
```
