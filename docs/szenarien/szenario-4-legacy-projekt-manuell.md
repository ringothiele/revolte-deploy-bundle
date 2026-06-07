# Szenario 4: Legacy-Projekt — Manuelle Einrichtung

Dieses Szenario beschreibt die Überführung eines Legacy-Projekts in eine saubere
Deployment-Infrastruktur. Das Projekt existiert bisher nur auf dem Server, ohne Git-Repository
und ohne Deploy-Struktur.

---

> **Wichtig: Der bestehende Server-Ordner wird nicht verändert.**
> Auf den Legacy-Ordner des Livesystems wird ausschließlich lesend zugegriffen (rsync pull).
> Für das spätere Deployment wird zwingend ein neuer Ordner auf dem Server angelegt.
> Der Wechsel auf die neue Struktur erfolgt am Ende durch Umbiegen der Domain.
> Der Legacy-Ordner bleibt so lange erhalten, bis der Wechsel abgeschlossen und geprüft ist.

---

## Voraussetzungen

- SSH-Zugang zum Server mit Lesezugriff auf das Legacy-Verzeichnis
- GitHub-Account mit Schreibrecht auf das Ziel-Repository
- ddev ist lokal installiert
- Contao-Version des Legacy-Projekts auf dem Server bekannt

---

## 1. Lokales Projekt anlegen (revolte-setup)

```bash
revolte-setup
```

Beim Ausführen des Skripts die **gleiche Contao-Version wie auf dem Liveserver** auswählen.
Das verhindert Kompatibilitätsprobleme beim Code-Sync. revolte-setup installiert auch das
revolte-deploy-bundle automatisch.

**Mögliche Probleme:**
- Falsche Version gewählt: Projekt löschen und revolte-setup erneut ausführen
- Version unbekannt: Auf dem Server `php -r "echo \Contao\CoreBundle\ContaoCoreBundle::getVersion();"` ausführen

---

## 2. SSH-Einrichtung (revolte-ssh-setup)

```bash
vendor/bin/revolte-ssh-setup
```

Hier wird der Liveserver als Quelle konfiguriert — nicht als Deployment-Ziel.

- **SSH-Key erstellen** — Namenskonvention: `KUERZEL_PROJEKTNAME-live_ed25519`
- **SSH-Profil in `~/.ssh/config`** — z. B. `meinprojekt-live`
- **homeadditions** — SSH-Konfiguration im ddev-Container
- **`config/revolte_deploy.yaml`** — Live-Umgebung als `live` eintragen

Den Public Key, den das Skript ausgibt, für Schritt 3 bereithalten.

**Mögliche Probleme:**
- SSH-Verbindungstest schlägt fehl: Public Key muss auf dem Server in `authorized_keys` hinterlegt sein

---

## 3. Public Key auf dem Server hinterlegen

```bash
ssh-copy-id -i ~/.ssh/KUERZEL_PROJEKTNAME-live_ed25519.pub -p PORT USER@SERVER
```

Oder manuell den Public Key in `~/.ssh/authorized_keys` auf dem Server eintragen.

Verbindung testen:

```bash
ssh meinprojekt-live exit
```

---

## 4. Code vom Server holen (revolte:legacy:code:pull)

```bash
ddev exec php vendor/bin/contao-console revolte:legacy:code:pull live --env=dev
```

Synct den Code des Legacy-Projekts per rsync in das lokale Projektverzeichnis. Ausgenommen
sind `.git/`, `.ddev/`, `vendor/`, `var/`, `node_modules/` und `.env.local`. Danach wird
`composer install` automatisch ausgeführt.

Mit `--dry-run` lässt sich vorher prüfen, welche Dateien übertragen würden:

```bash
ddev exec php vendor/bin/contao-console revolte:legacy:code:pull live --dry-run --env=dev
```

**Mögliche Probleme:**
- SSH-Verbindung schlägt fehl: `ddev auth ssh` ausführen und Passphrase eingeben
- Composer install fehlgeschlagen: `ddev exec composer install` manuell ausführen

---

## 5. Content vom Server holen

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull live --skip-git-pull --env=dev
```

Zieht Datenbank und Upload-Dateien vom Liveserver. `--skip-git-pull` ist nötig, da auf dem
Legacy-Server kein Git-Repository existiert.

---

## 6. Git-Repository anlegen und pushen

`.gitignore` prüfen — folgende Verzeichnisse gehören nicht ins Repository:

```
/vendor/
/var/
/web/assets/
/web/files/
/.env.local
```

Git initialisieren, GitHub-Repository anlegen und initialen Commit pushen:

```bash
git init
git branch -M main
gh repo create ORGANISATION/PROJEKTNAME --private
git remote add origin git@github.com:ORGANISATION/PROJEKTNAME.git
git add .
git commit -m "Initial commit — Legacy-Import"
git push -u origin main
```

---

## 7. Neues Server-Verzeichnis anlegen

Für die Deploy-Struktur wird ein **neuer Ordner** auf dem Server angelegt — der Legacy-Ordner
wird nicht verändert:

```
/usr/www/users/SERVERACCOUNT/PROJEKTNAME-new
```

Der Legacy-Ordner bleibt unangetastet, bis der Wechsel auf die neue Struktur abgeschlossen ist.

---

## 8. Deploy Key auf dem Server einrichten

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

Public Key auf GitHub hinterlegen: Repository → Settings → Deploy keys → Add deploy key.

Verbindung testen:

```bash
ssh -T github-PROJEKTNAME
```

---

## 9. revolte_deploy.yaml anpassen

Den neuen Server-Pfad in `config/revolte_deploy.yaml` für die Stage- oder Live-Umgebung
eintragen:

```yaml
environments:
  live:
    ssh_profile: meinprojekt-live
    remote_path: /usr/www/users/SERVERACCOUNT/PROJEKTNAME-new
    branch: main
```

---

## 10. Server initialisieren (revolte:deploy:init)

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:init live --env=dev
```

---

## 11. Ersten Deploy ausführen (revolte:deploy:full)

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:full live --env=dev
```

---

## 12. Domain umbiegen

Sobald der neue Ordner läuft und geprüft ist, wird die Domain im Hoster-Panel auf den neuen
Pfad umgeleitet. Erst dann ist der Legacy-Ordner obsolet und kann archiviert oder gelöscht
werden.

---

## Ergebnis

Das Legacy-Projekt ist in eine saubere Deployment-Infrastruktur überführt. Workflow für
weitere Deployments:

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:code live --env=dev
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull live --env=dev
```
