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
