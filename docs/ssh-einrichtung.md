# SSH-Einrichtung für Deployments

Diese Anleitung richtet sich an Entwickler ohne tiefes Linux-Vorwissen.  
Ziel: lokale Rechner so einrichten, dass Deployments per SSH auf Zielserver funktionieren.

---

## Was ist SSH und warum brauchen wir es?

SSH ist eine verschlüsselte Verbindung zwischen zwei Rechnern.  
Statt ein Passwort einzutippen, nutzen wir **SSH-Keys** — ein Schlüsselpaar aus:

- **Private Key** → bleibt auf deinem Rechner, nie weitergeben
- **Public Key** → wird auf dem Zielserver hinterlegt (wie ein Schloss, zu dem nur du den Schlüssel hast)

---

## Wo liegt `.ssh/` auf deinem Rechner?

Das Verzeichnis liegt im Home-Verzeichnis des Benutzers:

| System | Pfad |
|---|---|
| Linux / WSL2 | `/home/deinbenutzername/.ssh/` |
| macOS | `/Users/deinbenutzername/.ssh/` |
| Windows (Git Bash) | `C:\Users\deinbenutzername\.ssh\` |

**In WSL2:** du arbeitest im Linux-Dateisystem, nicht in Windows.  
Das Verzeichnis ist also `/home/ringo/.ssh/` — nicht `C:\Users\...`.

Prüfen ob es existiert:

```bash
ls -la ~/.ssh/
```

Falls es fehlt, legt SSH es automatisch beim ersten `ssh-keygen` an.

---

## SSH-Key erstellen

Für jede Kombination aus **Entwickler, Projekt und Umgebung** empfehlen wir einen eigenen Key.  
So ist im Server-Log erkennbar wer deployed hat, und einzelne Keys lassen sich sperren ohne andere zu beeinflussen.

**Namensschema:** `KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519`

Entwicklerkürzel im Team: `rt` (Ringo), `pl`, `sl`

Beispiele:
- `rt_kundea_stage_ed25519` — Ringo, Projekt kundea, Stage-Server
- `pl_kundea_live_ed25519` — pl, Projekt kundea, Live-Server

### Prüfen ob bereits ein Key existiert

```bash
ls ~/.ssh/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519 2>/dev/null && echo "vorhanden" || echo "nicht vorhanden"
```

→ **vorhanden:** weiter mit [SSH-Config einrichten](#sshconfig-einrichten)  
→ **nicht vorhanden:** Key generieren (nächster Schritt)

### Key generieren

```bash
ssh-keygen -t ed25519 -f ~/.ssh/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519 -C "KÜRZEL-PROJEKTNAME-UMGEBUNG"
```

Beispiel für Ringo, Projekt `kundea`, Umgebung `stage`:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/rt_kundea_stage_ed25519 -C "rt-kundea-stage"
```

Du wirst nach einer **Passphrase** gefragt. Empfehlung: eine setzen und im Passwortmanager speichern.  
Die Passphrase schützt den Key falls dein Rechner gestohlen wird.

---

## `~/.ssh/config` einrichten

Die Config-Datei erspart dir, bei jedem SSH-Befehl IP, Port und User anzugeben.  
Außerdem legt sie fest, welcher Key für welchen Server genutzt wird.

### Prüfen ob der Eintrag bereits existiert

```bash
ssh -G PROFIL-NAME 2>/dev/null | grep "^hostname " || echo "kein Eintrag gefunden"
```

→ **Hostname wird ausgegeben:** Eintrag existiert bereits, weiter mit [Public Key hinterlegen](#public-key-auf-dem-server-hinterlegen)  
→ **"kein Eintrag gefunden":** Eintrag anlegen (nächster Schritt)

Datei öffnen (anlegen falls nicht vorhanden):

```bash
nano ~/.ssh/config
```

Eintrag hinzufügen:

```
Host kundea-stage
    HostName 78.46.130.9
    Port 222
    User revolc
    IdentityFile ~/.ssh/rt_kundea_stage_ed25519
    IdentitiesOnly yes
```

**Wichtig bei Hetzner Managed Servern:** der Port ist oft **222**, nicht 22.  
Den richtigen Port findest du im Hetzner Konsolen-Panel oder frag nach.

Danach kannst du einfach `ssh kundea-stage` schreiben statt  
`ssh -p 222 revolc@78.46.130.9 -i ~/.ssh/...`

---

## Public Key auf dem Server hinterlegen

Der Zielserver muss deinen Public Key kennen, sonst wird die Verbindung abgelehnt.

### Prüfen ob der Key bereits hinterlegt ist

```bash
ssh PROFIL-NAME "echo OK" 2>/dev/null && echo "Verbindung funktioniert" || echo "Key nicht hinterlegt"
```

→ **"Verbindung funktioniert":** weiter mit [Verbindung testen](#verbindung-testen)  
→ **"Key nicht hinterlegt":** Public Key eintragen (nächster Schritt)

### Public Key anzeigen

```bash
cat ~/.ssh/rt_kundea_stage_ed25519.pub
```

Die Ausgabe sieht ungefähr so aus:

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA... kundea-stage-deploy-key
```

### Auf dem Server eintragen

Einfachste Methode — benötigt einmalig Passwort-Login (sofern der Server das erlaubt):

```bash
ssh-copy-id -i ~/.ssh/rt_kundea_stage_ed25519.pub -p 222 revolc@78.46.130.9
```

Falls Passwort-Login deaktiviert ist: jemanden mit bestehendem Zugang bitten,  
den Public Key manuell in `~/.ssh/authorized_keys` auf dem Server einzutragen — ein Eintrag pro Zeile.

---

## SSH-Agent: der Schlüsselbund

Wenn dein Key eine Passphrase hat, müsstest du sie bei jedem Deploy eingeben.  
Der **ssh-agent** merkt sich die entsperrten Keys für die aktuelle Sitzung.

### Agent starten und Key hinzufügen

```bash
eval "$(ssh-agent -s)"
ssh-add ~/.ssh/rt_kundea_stage_ed25519
```

Du wirst einmal nach der Passphrase gefragt. Danach klappt SSH in dieser Terminal-Sitzung ohne erneute Eingabe.

### In WSL2 automatisch beim Start

Füge das in deine `~/.bashrc` oder `~/.zshrc` ein:

```bash
# SSH-Agent automatisch starten
if [ -z "$SSH_AUTH_SOCK" ]; then
    eval "$(ssh-agent -s)" > /dev/null
fi
```

Dann mit `ssh-add` die gewünschten Keys einmalig nach dem WSL-Start hinzufügen.

---

## Verbindung testen

Nachdem alles eingerichtet ist:

```bash
ssh contao-deploy-bundle-stage "echo 'Verbindung OK'"
```

Wenn `Verbindung OK` erscheint, ist alles richtig eingerichtet.

Typische Fehlermeldungen:

| Fehler | Ursache |
|---|---|
| `Permission denied (publickey)` | Public Key nicht auf Server eingetragen, oder falscher Key |
| `Connection refused` | Falscher Port oder Server nicht erreichbar |
| `Host key verification failed` | Server-Fingerprint hat sich geändert — `ssh-keygen -R hostname` ausführen |
| `Could not open a connection to your authentication agent` | ssh-agent nicht gestartet — `eval "$(ssh-agent -s)"` ausführen |

---

---

## GitHub Deploy Key auf dem Server einrichten

Beim Git-first Deployment holt der **Zielserver** das Repo direkt von GitHub.
Dafür braucht der Server einen eigenen SSH-Key, der als Deploy Key im GitHub-Repo eingetragen ist.

Wichtig: der Server muss **pro Projekt** einen eigenen Key haben, damit Projekte sauber getrennt bleiben.

### 1. Prüfen ob ein Key bereits existiert

```bash
ssh PROFIL-NAME "ls ~/.ssh/id_ed25519_PROJEKTNAME_github 2>/dev/null && echo 'vorhanden' || echo 'nicht vorhanden'"
```

→ **vorhanden:** weiter mit Schritt 2 (SSH-Config prüfen)  
→ **nicht vorhanden:** Key generieren (nächster Schritt)

**Key generieren** — kein Passphrase (`-N ''`), weil der Server unbeaufsichtigt deployen muss.  
Leerzeichen im `-C`-Kommentar vermeiden (Quoting-Probleme über SSH):

```bash
ssh PROFIL-NAME "ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_PROJEKTNAME_github -N '' -C 'PROJEKTNAME-github-deploy-key'"
```

### 2. Prüfen ob SSH-Config auf dem Server vorhanden ist

```bash
ssh PROFIL-NAME "grep -q 'github-PROJEKTNAME' ~/.ssh/config 2>/dev/null && echo 'vorhanden' || echo 'nicht vorhanden'"
```

→ **vorhanden:** weiter mit Schritt 3 (Deploy Key auf GitHub prüfen)  
→ **nicht vorhanden:** Config-Eintrag auf dem Server ergänzen:

```bash
ssh PROFIL-NAME "printf '\nHost github-PROJEKTNAME\n    HostName github.com\n    User git\n    IdentityFile ~/.ssh/id_ed25519_PROJEKTNAME_github\n    IdentitiesOnly yes\n' >> ~/.ssh/config"
```

Warum ein eigener Host-Alias statt direkt `github.com`?  
Weil der Server mehrere Projekte bei GitHub hat — jedes Projekt braucht seinen eigenen Key.  
Mit dem Alias `github-PROJEKTNAME` weiß SSH automatisch welcher Key gemeint ist.

### 3. Prüfen ob Deploy Key bei GitHub eingetragen ist

Public Key auf dem Server anzeigen:

```bash
ssh PROFIL-NAME "cat ~/.ssh/id_ed25519_PROJEKTNAME_github.pub"
```

Dann auf GitHub prüfen: Repository → **Settings → Deploy keys**  
Ist ein Key mit diesem Fingerprint eingetragen? Falls nicht:

GitHub → **Add deploy key**

- Titel: z. B. `Hetzner stage deploy key`
- Key: den angezeigten Public Key einfügen
- Write access: **nicht** aktivieren (nur Lesen nötig)

### 4. Prüfen ob GitHub in known_hosts des Servers ist

```bash
ssh PROFIL-NAME "ssh-keygen -F github.com 2>/dev/null && echo 'vorhanden' || echo 'nicht vorhanden'"
```

→ **vorhanden:** weiter mit Schritt 5  
→ **nicht vorhanden:** GitHub-Fingerprint einmalig hinterlegen:

```bash
ssh PROFIL-NAME "ssh-keyscan github.com >> ~/.ssh/known_hosts"
```

### 5. Verbindung testen

```bash
ssh PROFIL-NAME "ssh -o BatchMode=yes -T git@github-PROJEKTNAME 2>&1"
```

Erwartete Ausgabe:

```
Hi organisation/repo-name! You've successfully authenticated, but GitHub does not provide shell access.
```

### 6. Repository-URL im YAML anpassen

In `config/revolte_deploy.yaml` den SSH-Alias statt `github.com` verwenden:

```yaml
git:
  repository: git@github-PROJEKTNAME:organisation/repo-name.git
```

---

## GitHub per SSH verbinden (für `git push` / `git pull`)

Git-Befehle zu GitHub laufen ebenfalls über SSH. Dafür muss dein SSH-Key in deinem **GitHub-Account** hinterlegt sein — das ist unabhängig vom Server-Zugang.

### Testen ob GitHub deinen Key kennt

```bash
ssh -T git@github.com
```

Erwartete Ausgabe:

```
Hi deinbenutzername! You've successfully authenticated, but GitHub does not provide shell access.
```

Falls stattdessen `Permission denied (publickey)` erscheint:

### Key in GitHub hinterlegen

1. Public Key anzeigen:

```bash
cat ~/.ssh/id_ed25519.pub
```

2. Den angezeigten Text komplett kopieren (beginnt mit `ssh-ed25519 AAAA...`)

3. GitHub öffnen → **Settings → SSH and GPG keys → New SSH key**

4. Titel vergeben (z. B. `WSL2 Entwicklungsrechner`), Key einfügen, speichern

5. Erneut testen: `ssh -T git@github.com`

### Mehrere Keys / Accounts

Wenn ihr mit verschiedenen GitHub-Accounts arbeitet (z. B. privat + Agentur), braucht ihr einen Eintrag in `~/.ssh/config` je Account:

```
Host github-revolte
    HostName github.com
    User git
    IdentityFile ~/.ssh/revolte_github_ed25519
    IdentitiesOnly yes
```

Dann in `config/revolte_deploy.yaml` die Repository-URL entsprechend anpassen:

```yaml
git:
  repository: git@github-revolte:revolte/mein-projekt.git
```

---

## Checkliste für ein neues Projekt

- [ ] Key generieren: `ssh-keygen -t ed25519 -f ~/.ssh/KÜRZEL_PROJEKT_UMGEBUNG_ed25519 -C "KÜRZEL-PROJEKT-UMGEBUNG"`
- [ ] Eintrag in `~/.ssh/config` anlegen (mit korrektem Port!)
- [ ] Public Key auf Server eintragen (`ssh-copy-id` oder manuell)
- [ ] Verbindung testen: `ssh PROFIL-NAME "echo OK"`
- [ ] GitHub-Key prüfen: `ssh -T git@github.com`
- [ ] SSH-Profil-Name in `config/revolte_deploy.yaml` eintragen
- [ ] `revolte:deploy:doctor` ausführen — SSH-Profil sollte ✓ zeigen

---

## SSH in ddev

Deploy-Commands laufen innerhalb des ddev-Containers. `ddev auth ssh` lädt deine SSH-Keys in den Container:

```bash
ddev auth ssh
```

Einmalig nach jedem `ddev start` (bzw. nach WSL-Neustart) ausführen.  
Du wirst für jeden gefundenen Key nach der Passphrase gefragt.

**Wichtig:** `ddev auth ssh` scannt `~/.ssh/` direkt nach Key-Dateien. Keys müssen deshalb direkt in `~/.ssh/` liegen — nicht in Unterordnern. Das Namensschema `KÜRZEL_PROJEKT_UMGEBUNG_ed25519` sorgt dafür dass Keys trotzdem eindeutig benannt und voneinander unterscheidbar sind.

**SSH-Profile (Host-Einträge) für ddev:**  
Diese sind im Repo unter `.ddev/homeadditions/.ssh/config.d/` hinterlegt und werden  
automatisch in den Container übernommen — kein manuelles Einrichten nötig.  
Einzige Voraussetzung: dein Public Key ist auf dem jeweiligen Server eingetragen.

**Bestehendes Projekt neu einrichten (neuer Entwickler):**
1. Repo klonen → `.ddev/homeadditions/` ist bereits vorhanden
2. `ddev start`
3. `ddev auth ssh` — einmalig mit Passphrase
4. Public Key auf Server hinterlegen (→ Abschnitt "Public Key auf dem Server hinterlegen")
5. `ddev exec php bin/console revolte:deploy:status` — alle Umgebungen sollten erreichbar sein
