# Alltag mit revolte-deploy-tools

Diese Seite ist für den Tag danach: Die [Ersteinrichtung](ersteinrichtung.md) ist
durch, das Projekt deployt — und jetzt geht es um die tägliche Routine. Sie
berücksichtigt die drei typischen Realitäten: Du **wechselst zwischen Projekten**,
du kommst **nach Wochen Pause zurück** (und hast Profilnamen & Co. vergessen),
und **einmal pro Session** muss der SSH-Agent gefüllt werden.

Alle Konsolen-Befehle laufen im Projektordner. Die lange Form
`ddev exec php vendor/bin/contao-console … --env=dev` ist immer gemeint, wenn hier
kurz `revolte:deploy:…` steht.

---

## 1. Session starten — einmal pro Rechner-Start

```bash
cd ~/projekte/<projektname>
ddev start        # falls das Projekt nicht schon läuft
ddev auth ssh     # fragt die Passphrase ab
```

**Warum:** Alle Deploy-Befehle bauen ihre SSH-Verbindungen aus dem ddev-Container
heraus auf. `ddev auth ssh` lädt deine Keys in den SSH-Agenten von ddev — der
läuft **global für alle ddev-Projekte gemeinsam**. Das heißt: einmal pro
Rechner-Session reicht, auch wenn du danach zwischen fünf Projekten wechselst.
Nötig wird es erst wieder nach einem Neustart von Rechner/WSL oder nach
`ddev poweroff`.

**Bin ich schon angemeldet?** So findest du es heraus, ohne eine Verbindung zu riskieren:

```bash
ddev exec ssh-add -l
```

Zeigt die geladenen Keys (u. a. dein `rt_revolte-labor_ed25519`). Kommt
`The agent has no identities` → `ddev auth ssh` ausführen.

> **Host-Terminal (optional):** Für die Deploy-Befehle brauchst du auf dem Host
> keinen Agenten. Nur wenn du dich direkt per `ssh <profil>` auf einen Server
> verbinden willst und die Passphrase nicht jedes Mal tippen möchtest:
> `eval "$(ssh-agent -s)" && ssh-add ~/.ssh/rt_revolte-labor_ed25519`

---

## 2. Orientierung nach einer Pause — „wie hieß nochmal …?"

Du musst dir nichts merken und nichts in `~/.ssh/config` zusammensuchen — alles
Projektspezifische steht versioniert im Projekt selbst:

```bash
cat config/revolte_deploy.yaml
```

Dort stehen die Umgebungen mit `ssh_profile` (Konvention: `<projektname>-stage`,
`<projektname>-live`), `remote_path` und Branch. Und den Live-Zustand aller
Umgebungen inklusive „hängt meine lokale Version vorne?" zeigt:

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:status --env=dev
```

Wenn du unsicher bist, ob nach der Pause noch alles verkabelt ist (Key, Agent,
Profile, Konfiguration), gibt es den gefahrlosen Rundum-Check — er ändert nichts
und macht höchstens einen Verbindungsversuch, nach Rückfrage:

```bash
vendor/bin/revolte-ssh-setup stage --check
```

---

## 3. Der Deploy-Zyklus

### Schritt 1: Stand ins Repo bringen

```bash
git add -A && git commit -m "..." && git push
```

**Warum:** Deployt wird ausschließlich, was gepusht ist — der Server zieht sich
den Code aus GitHub, nie von deinem Rechner. Nicht committete oder nicht
gepushte Änderungen blockieren den Deploy (mit klarer Meldung).

### Schritt 2: Vorschau — was würde passieren?

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:code stage --dry-run --env=dev
```

Zeigt die Commits und Dateien, die übertragen würden, und warnt bei
`composer.lock`-Änderungen und anstehenden Migrationen. Kostet nichts, ändert nichts.

### Schritt 3: Deployen — welcher Befehl wann?

Die Frage ist immer: **Wer ist gerade Source of Truth für den Content?**

| Situation | Befehl |
| --- | --- |
| **Entwicklerphase** — du baust, niemand pflegt Inhalte auf dem Server. Deine lokale DB ist die Wahrheit. | `revolte:deploy:full stage` — Code **und** deine lokale DB + Upload-Dateien gehen auf den Server. |
| **Redakteursphase** — auf Stage/Live werden Inhalte gepflegt. Die Server-DB ist die Wahrheit. | `revolte:deploy:code stage` — nur Code; DB und Content bleiben unangetastet. |
| **Entwicklung wieder aufnehmen** — du willst den aktuellen Server-Stand lokal haben. | `revolte:deploy:content:pull stage` — Server-DB und Content-Dateien kommen zu dir (deine lokale Domain bleibt erhalten). |
| **Deploy hat etwas kaputt gemacht** | `revolte:deploy:rollback stage --list`, dann `revolte:deploy:rollback stage` — vor jedem Deploy wird automatisch ein Backup angelegt. |

Faustregel bei Unsicherheit: **Sobald irgendwo Redakteure aktiv sind, nie mehr
`full` Richtung Server** — sonst überschreibst du deren Inhalte mit deinem
lokalen Stand. Dann gilt: `content:pull` zu dir, `code` zum Server.

---

## 4. Wenn etwas klemmt — Checkliste von oben nach unten

1. **„SSH-Verbindung fehlgeschlagen" bei einem Deploy-Befehl** — in 9 von 10
   Fällen ist der Agent leer (neuer Tag, WSL-Neustart):
   `ddev exec ssh-add -l` prüfen → `ddev auth ssh`.
2. **Immer noch Verbindungsfehler?** `vendor/bin/revolte-ssh-setup <env> --check`
   zeigt, welcher Baustein fehlt. **Wichtig:** Nicht auf Verdacht wiederholt
   `ssh` probieren — der Server sperrt nach Fehlversuchen für 30+ Minuten
   (fail2ban). Der Check hält sich automatisch daran (Cooldown).
3. **Deploy-Befehl meckert über Branch/Working Tree** — kein Verbindungsproblem:
   committen, pushen, richtigen Branch auschecken (Stage = `develop`, Live = `main`).
4. **Alles andere:** `revolte:deploy:doctor` prüft die lokale Umgebung komplett
   durch und sagt zu jedem Punkt, was zu tun ist.

---

## Spickzettel

```bash
# Session
ddev start && ddev auth ssh                # einmal pro Rechner-Session
ddev exec ssh-add -l                       # Agent-Status ansehen

# Orientierung
cat config/revolte_deploy.yaml             # Umgebungen, Profile, Pfade
revolte:deploy:status                      # was ist wo deployt?
vendor/bin/revolte-ssh-setup stage --check # Rundum-Diagnose, read-only

# Zyklus
git add -A && git commit -m "..." && git push
revolte:deploy:code stage --dry-run        # Vorschau
revolte:deploy:full stage                  # Entwicklerphase (DB geht mit!)
revolte:deploy:code stage                  # Redakteursphase (DB bleibt)
revolte:deploy:content:pull stage          # Server-Stand zu mir holen
revolte:deploy:rollback stage --list       # Notausgang
```

(`revolte:deploy:…` steht jeweils für
`ddev exec php vendor/bin/contao-console revolte:deploy:… --env=dev`.)
