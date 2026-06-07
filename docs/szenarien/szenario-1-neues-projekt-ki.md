# Szenario 1: Neues Projekt — Mit KI-Unterstützung

Die KI begleitet die Einrichtung und schlägt alle Befehle vor.
Ausführen tust immer du — kein Schritt läuft ohne deine Bestätigung.

---

## Vorbereitung (du, ohne KI)

Bevor du die KI hinzuziehst, erledigst du folgendes selbst:

1. **Server-Verzeichnis anlegen** — auf dem Server ein leeres Verzeichnis für das Projekt
   anlegen, Pfad notieren
2. **GitHub-Repository anlegen** — auf GitHub ein neues (leeres) privates Repository erstellen,
   URL notieren

---

## Start-Prompt für die KI

```
Ich möchte ein neues Contao-Projekt einrichten. Führe mich durch die komplette Einrichtung
mit revolte-deploy-tools.

Projektname: PROJEKTNAME
Mein Entwicklerkürzel: XX
Stage-Umgebung:
  SSH-Profil: PROJEKTNAME-stage
  Server: IP-ADRESSE
  Port: 222
  User: SERVERACCOUNT
  Remote-Pfad: /usr/www/users/SERVERACCOUNT/PROJEKTNAME
GitHub-Repo: git@github.com:ORGANISATION/PROJEKTNAME.git

Das Verzeichnis auf dem Server ist bereits angelegt. Das GitHub-Repository existiert bereits.
```

---

## Ablauf

Die KI führt dich durch diese Schritte — sie schlägt jeden Befehl vor, du führst ihn aus:

1. **revolte-setup** — lokale ddev- und Contao-Installation (du führst aus)
2. **revolte-ssh-setup** — SSH-Key, Profil, homeadditions und revolve_deploy.yaml (du führst aus)
   - Passphrase für den neuen Key wählst und merkst du dir selbst
   - Wenn `ddev auth ssh` abgefragt wird: du gibst die Passphrase ein
3. **Public Key auf dem Server hinterlegen** — die KI gibt dir den Befehl, du führst ihn auf
   dem Server aus
4. **Deploy Key auf dem Server erstellen** — die KI gibt dir alle Befehle, du führst sie auf
   dem Server aus und hinterlegst den Public Key auf GitHub
5. **Git-Remote setzen und initialen Commit pushen** — die KI gibt dir die Befehle
6. **`revolte:deploy:init stage --env=dev`** — nur mit deiner ausdrücklichen Freigabe
7. **`revolte:deploy:full stage --env=dev`** — nur mit deiner ausdrücklichen Freigabe

---

## Hinweise

- Die KI führt keine Befehle auf dem Server aus — alles was auf dem Server passiert, machst du
- Die KI führt keine Deploy-Befehle (init, code, full) ohne dein explizites "mach weiter"
- Wenn ein Schritt eine Passphrase erfordert, sagt die KI dir das — du führst ihn dann selbst aus
