# Szenario 3: Projekt übernehmen / zweiter Entwickler — Manuelle Einrichtung

Dieses Szenario beschreibt die lokale Einrichtung eines Projekts, das bereits vollständig mit
revolte-deploy-tools eingerichtet ist: Git-Repository existiert, Server läuft, revolte_deploy.yaml
ist im Repo. Du richtest das Projekt auf deinem Rechner ein, um daran zu arbeiten.

---

## Voraussetzungen

- Zugang zum GitHub-Repository
- SSH-Zugang zum Server (du bekommst deinen eigenen Key hinterlegt)
- ddev ist lokal installiert

---

## 1. Repository klonen

```bash
git clone git@github.com:ORGANISATION/PROJEKTNAME.git
cd PROJEKTNAME
```

Die `.ddev/`-Konfiguration ist im Repository enthalten — du brauchst ddev nicht neu
einzurichten.

---

## 2. ddev starten

```bash
ddev start
```

ddev liest die vorhandene Konfiguration aus `.ddev/config.yaml` und startet die Container.
Das funktioniert plattformübergreifend — WSL2 und Mac werden gleich behandelt.

**Mögliche Probleme:**
- Port-Konflikt: In `.ddev/config.yaml` `router_http_port` und `router_https_port` anpassen
- Docker läuft nicht: Docker Desktop starten

---

## 3. Abhängigkeiten installieren

```bash
ddev exec composer install
```

---

## 4. .env.local anlegen

Die Datei `.env.local` liegt nicht im Repository und muss lokal angelegt werden. Für eine
Standard-ddev-Installation reicht folgendes:

```bash
# .env.local
DATABASE_URL=mysql://db:db@db/db
```

Falls das Projekt weitere lokale Einstellungen benötigt (z. B. API-Keys, Mailer), besprich
das mit dem bisherigen Entwickler.

---

## 5. SSH-Einrichtung (revolte-ssh-setup)

```bash
vendor/bin/revolte-ssh-setup
```

Das Skript erkennt, dass `config/revolte_deploy.yaml` bereits vorhanden ist, und richtet
nur deinen persönlichen SSH-Key und das lokale Profil ein:

- **Neuen SSH-Key erstellen** — Namenskonvention: `DEINKUERZEL_PROJEKTNAME-UMGEBUNG_ed25519`
  (z. B. `jd_meinprojekt-stage_ed25519`)
- **SSH-Profil in `~/.ssh/config`** — der Profilname (z. B. `meinprojekt-stage`) ist
  identisch mit dem des anderen Entwicklers, zeigt aber auf deinen Key
- **homeadditions** — SSH-Konfiguration im ddev-Container

Die `revolte_deploy.yaml` wird nicht verändert — Server-Pfad, Branch und Profilname
bleiben wie sie sind.

**Mögliche Probleme:**
- Key wird vom ddev-Agent nicht erkannt: `ddev auth ssh` ausführen und Passphrase eingeben

---

## 6. Public Key auf dem Server hinterlegen

Das Skript zeigt deinen Public Key am Ende an. Dieser muss auf dem Server in
`~/.ssh/authorized_keys` eingetragen werden — entweder du hast selbst Zugang oder der
bisherige Entwickler trägt ihn ein:

```bash
ssh BESTEHENDESPROFIL "echo 'DEIN_PUBLIC_KEY' >> ~/.ssh/authorized_keys"
```

Verbindung testen:

```bash
ssh PROFILNAME exit
```

---

## 7. Content vom Server holen

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull stage --env=dev
```

Zieht Datenbank und Dateien vom Server. Damit ist die lokale Umgebung vollständig
initialisiert — kein separater Datenbank-Setup nötig.

> **Hinweis:** `--env=dev` ist immer erforderlich.

---

## Ergebnis

Die lokale Umgebung ist eingerichtet. Workflow für Deployments:

```bash
# Code deployen
ddev exec php vendor/bin/contao-console revolte:deploy:code stage --env=dev

# Content aktuell halten
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull stage --env=dev
```

---

## Wichtig: Absprache mit dem bisherigen Entwickler

Bevor du anfängst zu arbeiten, ist eine Absprache mit dem bisherigen Entwickler zwingend
erforderlich. Klärt mindestens folgendes:

- **Branching-Strategie** — wird für neue Features ein eigener Branch angelegt oder wird
  direkt auf `main` gearbeitet? Legt das gemeinsam fest, bevor der erste Commit passiert.
- **Wer deployt wann** — auf welchem Branch wird auf Stage deployt, wer gibt Deployments frei?
- **Offene Arbeiten** — gibt es laufende Branches oder Änderungen, die noch nicht gemergt sind
  und mit denen du in Konflikt kommen könntest?

Ohne diese Absprache riskiert ihr überschriebene Änderungen oder Deployments, die den
jeweils anderen Entwickler überraschen.
