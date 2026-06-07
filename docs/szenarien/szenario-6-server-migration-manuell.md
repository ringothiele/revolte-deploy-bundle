# Szenario 6: Server-Migration — Manuelle Einrichtung

Dieses Szenario beschreibt den Umzug eines laufenden Projekts auf einen neuen Server.
Während der Migration existieren temporär drei Umgebungen gleichzeitig:

- **live** — bestehender Server, läuft produktiv (wird nicht verändert)
- **live-new** — neuer Server, wird eingerichtet und parallel befüllt
- **stage** — bleibt unverändert

Der Wechsel erfolgt am Ende durch Umbiegen der Domain. Erst danach wird die alte Umgebung
abgebaut.

---

## Voraussetzungen

- Projekt läuft bereits mit revolte-deploy-tools
- Zugang zum neuen Server
- Leeres Verzeichnis auf dem neuen Server

---

## 1. Verzeichnis auf dem neuen Server anlegen

```
/usr/www/users/NEUER-SERVERACCOUNT/PROJEKTNAME
```

---

## 2. revolte-ssh-setup für neue Umgebung ausführen

```bash
vendor/bin/revolte-ssh-setup
```

Neue Umgebung `live-new` mit den Daten des neuen Servers eintragen. Die bestehenden
Umgebungen `live` und `stage` bleiben unverändert.

```yaml
environments:
  stage:
    ssh_profile: meinprojekt-stage
    remote_path: /usr/www/users/SERVERACCOUNT/PROJEKTNAME
    branch: main
  live:
    ssh_profile: meinprojekt-live
    remote_path: /usr/www/users/SERVERACCOUNT/PROJEKTNAME
    branch: main
  live-new:
    ssh_profile: meinprojekt-live-new
    remote_path: /usr/www/users/NEUER-SERVERACCOUNT/PROJEKTNAME
    branch: main
```

---

## 3. SSH-Zugang zum neuen Server einrichten

Public Key auf dem neuen Server hinterlegen und Verbindung testen:

```bash
ssh meinprojekt-live-new exit
```

---

## 4. Deploy Key auf dem neuen Server einrichten

Auf dem neuen Server einen GitHub Deploy Key anlegen — gleicher Ablauf wie bei der
Ersteinrichtung (Szenario 1, Schritt 5). Der bestehende Deploy Key auf dem alten Server
bleibt unverändert.

---

## 5. Freigabedateien auf dem neuen Server anlegen

```bash
touch /usr/www/users/NEUER-SERVERACCOUNT/PROJEKTNAME/.allow_deploy_code
touch /usr/www/users/NEUER-SERVERACCOUNT/PROJEKTNAME/.allow_deploy_full
```

---

## 6. Neue Umgebung initialisieren

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:init live-new --env=dev
```

---

## 7. Code auf neuen Server deployen

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:full live-new --env=dev
```

---

## 8. Content auf neuen Server übertragen

Datenbank und Dateien vom alten Live-Server holen und auf den neuen übertragen:

```bash
# Content vom alten Server lokal holen
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull live --env=dev

# Content auf neuen Server übertragen
ddev exec php vendor/bin/contao-console revolte:deploy:content:push live-new --env=dev
```

---

## 9. Neue Umgebung prüfen

Die neue Umgebung über die temporäre URL oder per `hosts`-Eintrag testen, bevor die Domain
umgebogen wird. Dabei insbesondere prüfen:

- Alle Inhalte vorhanden
- Bilder und Dateien korrekt
- Formulare und externe Dienste funktionieren

---

## 10. Domain umbiegen

Im Hoster-Panel die Domain auf das neue Server-Verzeichnis umstellen. Ab diesem Zeitpunkt
ist der neue Server produktiv.

---

## 11. Aufräumen

Nach erfolgreichem Wechsel:

1. `live-new` in `revolte_deploy.yaml` umbenennen zu `live`
2. Alten `live`-Eintrag entfernen oder auf `live-old` umbenennen
3. Alte Umgebung auf dem alten Server nach einer Übergangszeit abbauen

> Den alten Server-Ordner erst löschen, wenn die neue Umgebung stabil läuft und
> mindestens einige Tage produktiv ist.
