# Neues Stage-Projekt auf dem Labor — Kurzanleitung

Der Schnelldurchlauf für alle, die die [Ersteinrichtung](ersteinrichtung.md)
schon einmal komplett gemacht haben. Dann gilt nämlich: Dein Labor-Key existiert
und liegt schon auf dem Server, dein Kürzel und die Stage-Serverdaten sind auf
deinem Rechner gemerkt, GitHub-Zugang steht — es bleibt nur noch das
Projektspezifische. Bei jedem Schritt steht der Verweis auf die ausführliche
Version, falls doch etwas hakt.

**Ausgangslage:** Neues Contao-Projekt läuft lokal mit ddev (z. B. per
`revolte-setup` angelegt), Backend-Login funktioniert.
**Bereithalten:** DB-Zugangsdaten der neuen Stage (oder im Panel eine DB anlegen).

---

## 1. Session & Bundle

[HOST] — im Projektordner:

```bash
ddev auth ssh    # falls in dieser Session noch nicht geschehen (Passphrase)
ddev composer config repositories.revolte-deploy-tools '{"type":"vcs","url":"git@github.com:ringothiele/revolte-deploy-bundle.git"}'
ddev composer require --dev "revolte/contao-deploy-tools:@dev"
```

→ Details: [Ersteinrichtung Schritte 1–2](ersteinrichtung.md#schritt-1--github-zugriff-aus-dem-container-herstellen)

## 2. Git & GitHub

Neues privates Repository `<projektname>` auf GitHub anlegen (ohne README), dann:

```bash
git init -b main
git add -A && git commit -m "Initiales Contao-Setup"
git remote add origin git@github.com:<organisation>/<projektname>.git
git push -u origin main
git switch -c develop && git push -u origin develop
```

(`.gitignore` prüfen — Vorlage in
[Ersteinrichtung Schritt 3](ersteinrichtung.md#schritt-3--projekt-in-git-und-auf-github-bringen).
Merke: **main = Live, develop = Stage** — du arbeitest ab jetzt auf `develop`.)

## 3. Deploy-Konfiguration & KI-Schutz

```bash
mkdir -p config
cp vendor/revolte/contao-deploy-tools/resources/revolte_deploy.yaml.dist config/revolte_deploy.yaml
mkdir -p .claude
cp vendor/revolte/contao-deploy-tools/resources/ki/claude-settings.dist.json .claude/settings.json
```

In `config/revolte_deploy.yaml` zwei Werte setzen — Platzhalter wirklich ersetzen:

```yaml
project: <projektname>

git:
  repository: git@github.com-<projektname>:<organisation>/<projektname>.git
```

(Der Alias `github.com-<projektname>` entsteht in Schritt 5 auf dem Server —
Erklärung in [Ersteinrichtung Schritt 4](ersteinrichtung.md#schritt-4--deploy-konfiguration-anlegen).)

## 4. SSH-Setup — jetzt fast nur noch Enter

```bash
vendor/bin/revolte-ssh-setup stage
```

- Host, Port, Benutzer, Account und Pfad (mit neuem Projektnamen) sind aus
  deinen gemerkten Stage-Daten **vorbefüllt** → jeweils mit Enter bestätigen.
- Frage „Ist der Key bereits in authorized_keys enthalten?" → **Ja** — alle
  Labor-Stages nutzen denselben Server-Benutzer, dein Key liegt seit dem ersten
  Projekt dort. (Unsicher? Der angezeigte Prüfweg per WebFTP kostet eine Minute.)
- Der Verbindungstest am Ende muss grün sein.

→ Details/Störungen: [Ersteinrichtung Schritt 6](ersteinrichtung.md#schritt-6--ssh-zugang-zur-stage-einrichten) ·
Diagnose: `vendor/bin/revolte-ssh-setup stage --check`

## 5. Deploy-Key fürs neue Repo

Pro Repository braucht der Server einen eigenen Deploy Key (GitHub-Regel:
ein Key = ein Repo). **Vorhandene Keys auf dem Server nie anfassen.**

```bash
ssh <projektname>-stage                                                    # [HOST]
ssh-keygen -t ed25519 -N "" -f ~/.ssh/<projektname>_github_ed25519 -C "deploy-key <projektname>"   # [SERVER]
cat ~/.ssh/<projektname>_github_ed25519.pub
nano ~/.ssh/config       # speichern: Strg+O, Enter — beenden: Strg+X
```

In der `~/.ssh/config` auf dem Server ergänzen:

```
Host github.com-<projektname>
    HostName github.com
    User git
    IdentityFile ~/.ssh/<projektname>_github_ed25519
    IdentitiesOnly yes
```

Die `.pub`-Zeile im GitHub-Repo eintragen (Settings → Deploy keys → Add,
ohne Schreibzugriff), dann testen:

```bash
ssh -T git@github.com-<projektname>    # [SERVER] → „Hi <organisation>/<projektname>!"
exit
```

→ Details: [Ersteinrichtung Schritt 7](ersteinrichtung.md#schritt-7--deploy-key-der-server-braucht-lesezugriff-auf-das-repo)

## 6. Committen, prüfen, initialisieren

```bash
git add -A && git commit -m "Deploy-Setup" && git push
ddev exec php vendor/bin/contao-console revolte:deploy:check stage --env=dev
ddev exec php vendor/bin/contao-console revolte:deploy:init stage --env=dev
```

Danach die `.env.local` auf dem Server anlegen (per FTP oder
`nano ~/<projektname>/.env.local` — Inhalt inkl. fertigem `APP_SECRET` zeigt die
init-Ausgabe; DB vorher im Panel anlegen, Sonderzeichen im Passwort URL-kodieren)
und testen:

```bash
ssh <projektname>-stage
php ~/<projektname>/vendor/bin/contao-console --version    # → Versionsnummer
exit
```

→ Details: [Ersteinrichtung Schritt 9](ersteinrichtung.md#schritt-9--server-initialisieren-einmalig)

## 7. Subdomain & erster Deploy

Im Panel (KonsoleH) die Subdomain `<projektname>.revolte-labor.de` auf
`/usr/www/users/<benutzer>/<projektname>/public` zeigen lassen, SSL aktivieren.
Dann:

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:full stage --dry-run --env=dev
ddev exec php vendor/bin/contao-console revolte:deploy:full stage --env=dev
```

**Fertig-Check:** `https://<projektname>.revolte-labor.de` zeigt die Website,
`/contao` akzeptiert deinen lokalen Admin-Login, und
`revolte:deploy:status stage` meldet `✓ aktuell`.

Ab hier übernimmt die [Alltags-Anleitung](alltag.md).
