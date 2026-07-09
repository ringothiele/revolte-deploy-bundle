# Umbauplan: revolte-ssh-setup v2 — deterministisch & fail2ban-schonend

Stand: 2026-07-09 · Status: umgesetzt (Script v2 + KI-Schutz) · Validierung am Testprojekt steht aus

## Ausgangslage / Schmerzpunkte

1. **fail2ban-Sperrungen auf Hetzner** (Lunte sehr kurz, ~1 Fehlversuch → 30 min Bann,
   eskalierend). Trat v. a. auf, wenn eine KI eigenmächtig SSH-Verbindungen aufbaute.
2. **KI-gestützte Einrichtung ist inkonsistent** — Anweisungen in Docs/Prompts reichen
   nicht, um SSH-Zugriffe der KI zuverlässig zu verhindern.
3. **Das bisherige Script hat zu viele manuelle Bruchstellen** (`pause()` +
   Copy-Paste-Befehle) und fragt 9 Werte ab, von denen die meisten ableitbar sind.
4. **Mac-Inkompatibilität**: `${var,,}` (Bash 4) und GNU-`sed -i` — bricht auf macOS
   (Bash 3.2, BSD-sed). Ein Entwickler arbeitet am Mac.

## Ursachenanalyse fail2ban (wichtigster Befund)

`ddev auth ssh` lädt **alle** Keys aus `~/.ssh/` in den Container-Agent. Das
Container-SSH-Profil (homeadditions) hatte aber **kein `IdentityFile` und kein
`IdentitiesOnly`**. Folge: Jede Verbindung aus dem Container bietet dem Server
sämtliche Agent-Keys nacheinander an. Bei vielen Projekten/Keys erzeugt ein einziger
`ssh`-Aufruf mehrere abgelehnte Auth-Versuche → fail2ban greift, obwohl subjektiv
"nur einmal" verbunden wurde. Zusätzlich hat das alte Script bei der
"Working-Profile-Suche" jedes Profil mit passendem Host per SSH durchprobiert —
weitere potenzielle Fehlversuche.

## Leitprinzipien v2

1. **Pro Verbindung genau ein Key.** Container-Profile bekommen den Public Key als
   `IdentityFile` (Auswahl des Agent-Keys über die .pub-Datei) plus
   `IdentitiesOnly yes`. Maximaler Schaden eines Fehlversuchs: 1 Auth-Failure.
2. **Pro Script-Lauf maximal ein Verbindungstest.** Kein Probing mehrerer Profile.
   Erst Key auf den Server bringen, *dann* einmal testen — nie umgekehrt
   (der alte Flow produzierte einen *erwarteten* Fehlversuch vor dem Key-Eintrag).
3. **Cooldown nach Fehlversuch.** Fehlgeschlagener Test schreibt einen Timestamp
   (`~/.config/revolte/ssh-cooldown/<host>_<port>`); 35 Minuten lang verweigert das
   Script jeden weiteren Verbindungsversuch auf diesen Host — auch im `--check`-Modus.
4. **Fehlerbilder unterscheiden**: `Permission denied` (Key nicht akzeptiert) vs.
   Timeout/Refused (Netz oder Bann) — je eigene, konkrete Handlungsanweisung.
5. **Passphrase nur an zwei Stellen, beide vom Entwickler ausgelöst:**
   `ssh-keygen` (einmal je Key) und `ddev auth ssh` (einmal je ddev-Session).
   Das Script führt beide **direkt** aus (kein Copy-Paste-Pingpong); die KI
   fasst das Thema nie an.
6. **KI-Sperre technisch erzwingen, nicht nur dokumentieren** (siehe unten).
7. **Idempotent + portabel** (Bash 3.2 / BSD-Tools, läuft auf WSL2 und macOS).

## Neue Bedienung

```bash
# Einmalig pro Entwickler (wird beim ersten Lauf abgefragt und gespeichert):
#   ~/.config/revolte/identity.conf   → KUERZEL=rt

# Stage einrichten (Team-Defaults füllen Host/Port/Account/Pfad-Schema vor):
vendor/bin/revolte-ssh-setup stage

# Live einrichten — interaktiv oder komplett per Flags
# (die Flags darf auch die KI zusammenstellen; ausführen tut der Entwickler):
vendor/bin/revolte-ssh-setup live --host kunde.example.com --port 22 \
  --user k12345 --path /www/htdocs/k12345/live --branch main --account kunde-x

# Diagnose (read-only, max. 1 Verbindungstest nach Rückfrage, respektiert Cooldown):
vendor/bin/revolte-ssh-setup stage --check

# Weitere Optionen: --yes (keine Rückfragen), --skip-test, --profile, --key
```

Team-Defaults (`~/.config/revolte/team.conf`, bash-Format `KEY=value`) werden beim
ersten erfolgreichen Stage-Setup zum Speichern angeboten — damit tragen sich die
revolte-labor-Werte (Host, Port, Account, Pfad-Schema mit `{project}`-Platzhalter)
selbst ein und müssen nicht ins Bundle hartkodiert werden.

## Ablauf Setup-Modus (deterministische Reihenfolge)

1. Projekt erkennen (`.ddev/config.yaml` → Projektname), Identität laden/anlegen
2. Werte auflösen: Flags > Team-Defaults (nur stage) > Prompt; Defaults:
   Profil `{projekt}-{env}`, Branch stage→develop / live→main
3. Zusammenfassung + eine Bestätigung
4. **Key**: `~/.ssh/{kürzel}_{account}_ed25519` — vorhanden? sonst `ssh-keygen` direkt
5. **Host-Config** (`~/.ssh/config`): kanonischer Block inkl. `IdentitiesOnly yes`;
   abweichender Bestand wird angezeigt und nach Bestätigung ersetzt
6. **Container-Config** (homeadditions): Profil mit `IdentityFile ~/.ssh/revolte-keys/<key>.pub`
   + `IdentitiesOnly yes`; .pub-Datei wird mitkopiert. Da damit entwicklerspezifisch:
   `.ddev/homeadditions/.ssh/` kommt in die projekt-`.gitignore` (Warnung, falls
   bereits in Git getrackt). Bei Änderung: `ddev restart` direkt ausführen
7. **Agent**: Fingerprint in `ddev exec ssh-add -l` prüfen; fehlt → `ddev auth ssh`
   direkt ausführen, erneut prüfen
8. **Key auf den Server** (ohne Testverbindung vorab):
   a) "schon hinterlegt?" → weiter · b) funktionierendes Profil für denselben Server
   benennen (keine automatische Suche per Verbindung!) → Key darüber eintragen
   (idempotent via `grep -qxF`) · c) manuell: Pubkey + fertiger Befehl mit
   Passwort-Auth bzw. Hinweis aufs Hosting-Panel
9. **revolte_deploy.yaml** aktualisieren (awk, wie bisher — vor dem Verbindungstest,
   damit ein Cooldown die Config-Persistenz nicht blockiert)
10. **Ein** Verbindungstest (BatchMode, ConnectTimeout 10): OK → Cooldown löschen;
    Fehler → klassifizieren, Cooldown setzen, konkrete nächste Schritte, Exit 1

## KI-Sperre (zweiter Baustein)

- **Claude Code**: `resources/ki/claude-settings.dist.json` → wird als
  `.claude/settings.json` ins Projekt kopiert (committet, gilt für alle Entwickler).
  Deny-Regeln für alles Verbindende: `ssh`, `scp`, `sftp`, `rsync`, `ssh-keygen`,
  `ssh-copy-id`, `ssh-add`, `ddev auth`, `ddev exec ssh*`, `revolte-ssh-setup` sowie
  alle `revolte:deploy:*`-Commands, die Verbindungen öffnen (init, code, full,
  rollback, status, check, content:*, legacy:code:pull). Erlaubt bleiben:
  `revolte:deploy:doctor` und `revolte:deploy:explain` (rein lokal).
  ⚠ Die exakte Matcher-Syntax (Präfix `:` vs. Wildcards) gehört zur Validierung
  im Testprojekt.
- **Codex**: kennt keine äquivalenten projektweiten Deny-Regeln →
  `resources/ki/AGENTS-hinweis.md` als Baustein für die Projekt-AGENTS.md
  (+ Default-Sandbox blockt Netzwerk ohnehin). Das Ein-Key-Prinzip begrenzt
  den Schaden, falls doch etwas durchrutscht.
- **`revolte:deploy:doctor`** prüft künftig, ob `.claude/settings.json` mit
  SSH-Deny-Regeln vorhanden ist, und gibt sonst den Kopierbefehl aus.

## Nicht Teil dieses Umbaus (bewusst)

- `bin/revolte-setup` bleibt unverändert (funktioniert, Sidequest)
- PHP-Deploy-Commands bleiben unverändert (`php_cli: auto`-Thema separat)
- Szenario-Doku wird erst **nach** erfolgreicher Validierung angepasst

## Validierungs-Checkliste (Testprojekt)

- [ ] Stage-Setup komplett neu: ein Lauf, 2× Passphrase, 1 Verbindungstest, 0 Fehlversuche
- [ ] Wiederholter Lauf: keine Änderungen, keine Prompts außer Bestätigungen (Idempotenz)
- [ ] Live-Setup rein per Flags (von KI vorbereitete Zeile)
- [ ] `--check` nach Kaputtmachen einzelner Teile (Key weg, Agent leer, ddev aus, Profil verstellt)
- [ ] Absichtlicher Fehlversuch → Cooldown greift, Meldung verständlich, kein zweiter Versuch möglich
- [ ] Container-Verbindung bietet exakt einen Key an (`ddev exec ssh -v <profil>` prüfen)
- [ ] Claude Code: Deny-Regeln blocken `ssh`/`rsync`/deploy-Commands wirklich (Syntax ggf. nachziehen)
- [ ] macOS-Lauf beim Mac-Kollegen (Bash 3.2)
- [ ] Team-Defaults: Speichern beim ersten Stage-Setup, Vorbefüllung beim zweiten Projekt
