# Projekteinrichtung mit revolte-deploy-tools

## Warum dieser Ansatz?

Moderne Contao-Projekte werden lokal entwickelt — mit ddev läuft die komplette Umgebung
auf dem eigenen Rechner, unabhängig vom Server. Das hat einen entscheidenden Vorteil:
Die KI (z. B. Claude) kann direkt im Projekt mitarbeiten, Code analysieren, Änderungen
vorschlagen und beim Debugging helfen — ohne Zugriff auf Produktivsysteme.

Das revolte-deploy-bundle schließt die Lücke zwischen lokaler Entwicklung und Server.
Es stellt sicher, dass Code, Datenbank und Dateien zwischen den Umgebungen zuverlässig
und mit minimalem Aufwand synchronisiert werden — ohne manuelle FTP-Uploads, ohne
Raten ob der Stand auf dem Server aktuell ist.

## Was das Bundle leistet

- **Code deployen** — per Git, reproduzierbar und nachvollziehbar
- **Content synchronisieren** — Datenbank und Upload-Dateien zwischen Dev, Stage und Live
- **Rollback** — bei Problemen auf einen früheren Stand zurückkehren
- **Klare Trennung** — was lokal passiert, bleibt lokal; was auf den Server geht, wird
  bewusst ausgelöst

## Der Grundsatz: Entwickler entscheidet

Deployments und Synchronisierungen laufen nie automatisch — weder durch Skripte noch durch
die KI. Jeder Schritt, der Daten auf den Server überträgt oder von dort holt, wird vom
Entwickler bewusst ausgelöst. Die KI schlägt vor, der Entwickler entscheidet.

---

## Alle Befehle im Überblick

Alle Befehle werden mit `--env=dev` ausgeführt — das Bundle ist nur in der Dev-Umgebung
aktiv. Beispiel: `ddev exec php vendor/bin/contao-console revolte:deploy:status stage --env=dev`

### Diagnose & Status

| Befehl | Beschreibung | Typischer Einsatz |
|--------|-------------|-------------------|
| `revolte:deploy:status <env>` | Zeigt welcher Commit auf welcher Umgebung deployed ist | Vor dem Deploy prüfen ob Stage und Local übereinstimmen |
| `revolte:deploy:doctor` | Prüft die lokale Entwicklungsumgebung | Bei Problemen als erster Schritt zur Diagnose |
| `revolte:deploy:check <env>` | Prüft eine Zielumgebung vor dem Deployment | Vor init oder full ausführen um Probleme früh zu erkennen |
| `revolte:deploy:explain <env> <pfad>` | Erklärt warum ein Pfad in einem Deploy-Profil erlaubt oder ausgeschlossen ist | Wenn Dateien unerwartet deployed oder nicht deployed werden |

### Einrichtung & Deployment

| Befehl | Beschreibung | Typischer Einsatz |
|--------|-------------|-------------------|
| `revolte:deploy:init <env>` | Richtet die Release-Struktur auf dem Server ein und klont das Git-Repository | Einmalig bei der ersten Einrichtung einer Umgebung |
| `revolte:deploy:code <env>` | Deployt neuen Code per Git ohne Datenbankmigrationen | Schnelles Update wenn nur Code geändert wurde |
| `revolte:deploy:full <env>` | Deployt Code + führt Composer install und Datenbankmigrationen aus | Standard-Deploy bei Abhängigkeitsänderungen oder Contao-Updates |
| `revolte:deploy:rollback <env>` | Rollback auf ein früheres Backup | Wenn ein Deploy Probleme verursacht hat |

### Content-Synchronisierung

| Befehl | Beschreibung | Typischer Einsatz |
|--------|-------------|-------------------|
| `revolte:deploy:content:pull <env>` | Zieht Datenbank und Upload-Dateien vom Server lokal | Lokalen Stand aktuell halten, nach einem Live-Deploy |
| `revolte:deploy:content:push <env>` | Überträgt lokale Datenbank und Dateien auf den Server | Lokal aufgebaute Inhalte auf Stage oder Live übertragen |

### Legacy

| Befehl | Beschreibung | Typischer Einsatz |
|--------|-------------|-------------------|
| `revolte:legacy:code:pull <env>` | Synct Code eines Legacy-Projekts vom Server lokal | Szenario 4: Erstmalige lokale Einrichtung eines Legacy-Projekts |

---

## Sicherheitsmechanismus: Deploy-Freigabe

`revolte:deploy:code` und `revolte:deploy:full` prüfen vor der Ausführung, ob auf dem
Server eine Freigabedatei existiert:

- `.allow_deploy_code` — Freigabe für Code-Deployments
- `.allow_deploy_full` — Freigabe für Full-Deployments

Diese Dateien müssen manuell auf dem Server angelegt werden. Das verhindert versehentliche
Deployments auf falsche Umgebungen und stellt sicher, dass der Entwickler die Umgebung
bewusst für Deployments freigeschaltet hat.

```bash
# Auf dem Server anlegen (z. B. nach revolte:deploy:init):
touch /pfad/zur/umgebung/.allow_deploy_code
touch /pfad/zur/umgebung/.allow_deploy_full
```

---

## Anleitungen

Zu jedem Szenario gibt es zwei Versionen:

**Manuelle Anleitung** — für Entwickler, die den Prozess selbst durchführen. Enthält
Erklärungen warum ein Schritt nötig ist, konkrete Befehle und Hinweise zur Lösung
häufiger Probleme.

**KI-Anleitung** — für Entwickler, die sich von einer KI durch den Prozess führen lassen.
Enthält einen Start-Prompt und eine Übersicht aller Schritte. Die KI schlägt jeden Befehl
vor — ausführen tust immer du.

---

## Szenarien

### [Szenario 1 — Neues Projekt](szenario-1-neues-projekt-manuell.md)

Du startest von Grund auf: kein lokales Projekt, kein GitHub-Repository, kein Server-Setup.

→ [Manuell](szenario-1-neues-projekt-manuell.md) · [Mit KI](szenario-1-neues-projekt-ki.md)

---

### [Szenario 2 — Bestehendes lokales Projekt](szenario-2-bestehendes-projekt-manuell.md)

Das Projekt läuft bereits lokal mit ddev, hat aber noch kein GitHub-Repository und keine
Deployment-Infrastruktur. revolte-deploy-tools wird nachträglich eingerichtet.

→ [Manuell](szenario-2-bestehendes-projekt-manuell.md) · [Mit KI](szenario-2-bestehendes-projekt-ki.md)

---

### [Szenario 3 — Projekt übernehmen / zweiter Entwickler](szenario-3-projekt-uebernehmen-manuell.md)

Git-Repository und Server-Setup existieren bereits. Du richtest das Projekt auf deinem
Rechner ein. Vor dem Start zwingend mit dem bisherigen Entwickler absprechen —
Branching-Strategie und laufende Arbeiten klären.

→ [Manuell](szenario-3-projekt-uebernehmen-manuell.md) · [Mit KI](szenario-3-projekt-uebernehmen-ki.md)

---

### [Szenario 4 — Legacy-Projekt](szenario-4-legacy-projekt-manuell.md)

Das Projekt existiert bisher nur auf dem Server: kein Git-Repository, keine Deploy-Struktur.
Das Projekt wird lokal gespiegelt, in Git überführt und mit einer sauberen
Deploy-Infrastruktur versehen. Der bestehende Server-Ordner wird dabei nicht verändert.

→ [Manuell](szenario-4-legacy-projekt-manuell.md) · [Mit KI](szenario-4-legacy-projekt-ki.md)

---

### [Szenario 5 — Zweite Umgebung hinzufügen](szenario-5-zweite-umgebung-manuell.md)

Stage läuft bereits — jetzt soll eine Live-Umgebung (oder umgekehrt) dazukommen.
`revolte-ssh-setup` wird erneut ausgeführt, die neue Umgebung wird initialisiert und
der erste Deploy ausgeführt.

→ [Manuell](szenario-5-zweite-umgebung-manuell.md) · [Mit KI](szenario-5-zweite-umgebung-ki.md)

---

### [Szenario 6 — Server-Migration](szenario-6-server-migration-manuell.md)

Das Projekt soll auf einen neuen Server umgezogen werden. Temporär existieren drei
Umgebungen gleichzeitig: Stage, Live (alt) und Live (neu). Der Wechsel erfolgt durch
Umbiegen der Domain am Ende.

→ [Manuell](szenario-6-server-migration-manuell.md) · [Mit KI](szenario-6-server-migration-ki.md)
