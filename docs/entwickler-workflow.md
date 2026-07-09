# Entwickler-Workflow — Konzept

Diese Seite erklärt das **Konzept** hinter den Deploy-Befehlen: wann welcher
Befehl der richtige ist und warum. Für die Abläufe gibt es eigene Seiten:

- **[Ersteinrichtung](ersteinrichtung.md)** — von frischem Contao bis zum ersten Deploy, ohne Vorwissen
- **[Alltag](alltag.md)** — Session-Start, Projektwechsel, Deploy-Zyklus, Störungs-Checkliste
- **[Szenarien](szenarien/einfuehrung.md)** — Projektübernahme, Legacy, zweite Umgebung, Server-Migration

Alle Befehle laufen im Projektordner in der Form
`ddev exec php vendor/bin/contao-console <befehl> --env=dev`.

---

## Konzept: Wann welcher Befehl?

Das Tool unterscheidet Deployment-Arten anhand der Frage: **Wer ist Source of
Truth für den Content?**

### Full Deploy — Entwicklerphase

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:full stage --env=dev
```

Einsatz: während der Entwicklung, bevor Redakteure Inhalte pflegen.

- Git-Stand wird auf Remote gebracht
- **Lokale Datenbank wird vollständig auf den Server übertragen**
- Content-Verzeichnisse (`files/*/content/` etc.) werden per rsync übertragen
- Composer, Cache, Migrationen, Dateisystem-Sync
- Vor dem Deploy wird automatisch ein Backup erstellt (Code + DB)

### Code Deploy — Redakteursphase

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:code stage --env=dev
```

Einsatz: sobald Redakteure Inhalte auf Stage/Live pflegen.

- Git-Stand wird auf Remote gebracht
- **Datenbank und Content-Dateien bleiben unberührt**
- Composer, Cache, Migrationen, Dateisystem-Sync
- Vor dem Deploy wird automatisch ein Backup erstellt (nur Commit)

### Content Pull — Entwicklung aufnehmen

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull stage --env=dev
```

Einsatz: wenn ein Entwickler den aktuellen Stand von Stage/Live in seine lokale
Umgebung holen möchte.

- `git pull` aus dem Repository (Standard, überspringbar mit `--skip-git-pull`)
- Remote-Datenbank wird lokal importiert
- Content-Verzeichnisse werden per rsync lokal synchronisiert
- Hotfix-Verzeichnisse (`files/*/layout/hotfix/`) werden mitgeholt
- Lokale Felder wie `tl_page.dns` bleiben erhalten
- Lokaler Cache wird geleert, Migrationen werden ausgeführt

Varianten: `--skip-database` (nur Dateien), `--skip-files` (nur DB),
`--skip-git-pull` (ohne Code-Update).

### Content Push — neue Inhalte nach draußen

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:content:push stage --env=dev
```

Einsatz: lokal neu aufgebaute Seiten/Artikel/Inhalte auf den Server übertragen,
ohne die dortige Datenbank zu überschreiben. Übertragen werden nur **neue**
Datensätze seit dem letzten `content:pull` (die Baseline-Datei
`.revolte-content-baseline.json` merkt sich den Stand); IDs werden dabei
konfliktfrei neu vergeben, neue Seiten landen unveröffentlicht auf dem Server.

### Rollback — Notfallrückkehr

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:rollback stage --list --env=dev
ddev exec php vendor/bin/contao-console revolte:deploy:rollback stage --env=dev
ddev exec php vendor/bin/contao-console revolte:deploy:rollback stage --backup=20260603_143022_a1b2c3d4_full --env=dev
```

Backups werden automatisch vor jedem Deploy erstellt und auf dem Server unter
`~/revolte-deploy-backups/` gespeichert. Es werden die letzten 3 Backups behalten.

---

## CSS-Hotfixes auf Stage/Live

Manchmal muss eine kleine CSS-Änderung direkt auf Stage/Live gemacht werden,
ohne den vollen Entwicklungszyklus. Dafür gibt es das Hotfix-Verzeichnis:

1. Datei `files/<projekt>/layout/hotfix/hotfix.scss` auf dem Server anlegen (via FTP oder SSH)
2. Beim nächsten `content:pull` wird sie automatisch mitgeholt
3. Änderungen in die regulären SCSS-Dateien einarbeiten und Hotfix-Datei löschen

Das Hotfix-Verzeichnis ist gitigniert und taucht nie im Repository auf.

---

## SSH-Modell in Kürze

- **Profile:** pro Projekt und Umgebung eines (`<projektname>-stage`,
  `<projektname>-live`). Angelegt werden sie von `vendor/bin/revolte-ssh-setup`
  — auf dem Host (`~/.ssh/config`) und im ddev-Container
  (`.ddev/homeadditions/.ssh/`). Die Container-Profile sind
  **entwicklerspezifisch und gitigniert** — jeder Entwickler führt das
  Setup-Script einmal selbst aus.
- **Keys:** einer pro Entwickler und Server-Account
  (`{kürzel}_{account}_ed25519`) — Profile sammeln sich, Keys nicht.
  Jedes Profil pinnt seinen Key (`IdentitiesOnly yes`), damit pro Verbindung
  genau ein Key angeboten wird — wichtig, weil fail2ban auf den Servern schon
  nach einem Fehlversuch sperrt.
- **Agent:** `ddev auth ssh` einmal pro Rechner-Session (gilt für alle
  ddev-Projekte). Details in der [Alltags-Anleitung](alltag.md).
- **Diagnose:** `vendor/bin/revolte-ssh-setup <env> --check` (read-only,
  respektiert den fail2ban-Cooldown).

### WSL2 vs. Mac

Alle Befehle sind auf beiden Plattformen identisch. Unter WSL2 muss `rsync`
ggf. nachinstalliert werden (`sudo apt install rsync`), am Mac ist es
vorinstalliert. Ein Host-SSH-Agent ist nur nötig, wenn du dich manuell per
`ssh <profil>` verbinden willst — die Deploy-Befehle nutzen den ddev-Agenten.

---

## Alle verfügbaren Commands

```bash
revolte:deploy:doctor                    # Lokale Umgebung prüfen (rein lokal)
revolte:deploy:status [env]              # Deploy-Stand aller Umgebungen
revolte:deploy:check <env>               # Zielumgebung prüfen
revolte:deploy:init <env>                # Ersteinrichtung Remote
revolte:deploy:full <env>                # Vollständiger Deploy (Code + DB + Dateien)
revolte:deploy:code <env>                # Code-Deploy (ohne DB)
revolte:deploy:code <env> --dry-run      # Vorschau: Commits + Änderungen
revolte:deploy:content:pull <env>        # Content von Remote holen
revolte:deploy:content:push <env>        # Neue lokale Records pushen
revolte:deploy:rollback <env>            # Rollback auf Backup
revolte:deploy:explain <profil> <pfad>   # Deploy-Regel für einen Pfad erklären (rein lokal)
```

(Jeweils als `ddev exec php vendor/bin/contao-console … --env=dev` ausführen.)
