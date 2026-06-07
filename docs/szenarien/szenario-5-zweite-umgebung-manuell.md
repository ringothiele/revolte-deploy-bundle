# Szenario 5: Zweite Umgebung hinzufügen — Manuelle Einrichtung

Dieses Szenario beschreibt das Hinzufügen einer weiteren Deployment-Umgebung zu einem
bereits laufenden Projekt. Typisch: Stage existiert bereits, Live soll dazukommen — oder
umgekehrt.

---

## Voraussetzungen

- Projekt ist bereits mit revolte-deploy-tools eingerichtet (mind. eine Umgebung läuft)
- SSH-Zugang zum Server
- Leeres Verzeichnis auf dem Server für die neue Umgebung

---

## 1. Server-Verzeichnis anlegen

Auf dem Server ein leeres Verzeichnis für die neue Umgebung anlegen:

```
/usr/www/users/SERVERACCOUNT/PROJEKTNAME-live
```

---

## 2. revolte-ssh-setup erneut ausführen

```bash
vendor/bin/revolte-ssh-setup
```

Das Skript erkennt die bestehende `revolte_deploy.yaml` und bietet an, eine neue Umgebung
hinzuzufügen oder eine bestehende zu aktualisieren. Die neue Umgebung (z. B. `live`) mit
SSH-Profil, Server-Pfad und Branch eintragen.

Nach dem Durchlauf ist in `config/revolte_deploy.yaml` ein neuer Eintrag vorhanden, z. B.:

```yaml
environments:
  stage:
    ssh_profile: meinprojekt-stage
    remote_path: /usr/www/users/SERVERACCOUNT/PROJEKTNAME-stage
    branch: main
  live:
    ssh_profile: meinprojekt-live
    remote_path: /usr/www/users/SERVERACCOUNT/PROJEKTNAME-live
    branch: main
```

**Mögliche Probleme:**
- SSH-Profil bereits vorhanden mit anderem Key: Skript fragt nach, mit `j` bestätigen

---

## 3. Public Key auf dem Server hinterlegen

Den Public Key, den das Skript ausgibt, in `~/.ssh/authorized_keys` auf dem Server eintragen.

Verbindung testen:

```bash
ssh meinprojekt-live exit
```

---

## 4. Deploy Key für die neue Umgebung prüfen

Falls Stage und Live auf demselben Server-Account laufen, ist der Deploy Key bereits
vorhanden. Verbindung prüfen:

```bash
ssh -T github-PROJEKTNAME
```

Falls die neue Umgebung auf einem anderen Server läuft, muss ein neuer Deploy Key angelegt
werden — siehe Szenario 1 oder 2, Schritt "Deploy Key einrichten".

---

## 5. Deploy-Freigabedateien anlegen

Auf dem Server die Freigabedateien für die neue Umgebung anlegen:

```bash
touch /usr/www/users/SERVERACCOUNT/PROJEKTNAME-live/.allow_deploy_code
touch /usr/www/users/SERVERACCOUNT/PROJEKTNAME-live/.allow_deploy_full
```

---

## 6. Neue Umgebung initialisieren (revolte:deploy:init)

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:init live --env=dev
```

---

## 7. Ersten Deploy auf die neue Umgebung (revolte:deploy:full)

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:full live --env=dev
```

---

## Ergebnis

Beide Umgebungen laufen. Der Workflow bleibt gleich — nur der Umgebungsname ändert sich:

```bash
ddev exec php vendor/bin/contao-console revolte:deploy:code live --env=dev
ddev exec php vendor/bin/contao-console revolte:deploy:content:pull live --env=dev
```
