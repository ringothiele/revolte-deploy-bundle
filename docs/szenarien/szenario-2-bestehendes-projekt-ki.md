# Szenario 2: Bestehendes Projekt — Mit KI-Unterstützung

Die KI begleitet die Einrichtung und schlägt alle Befehle vor.
Ausführen tust immer du — kein Schritt läuft ohne deine Bestätigung.

---

## Vorbereitung (du, ohne KI)

1. **Server-Verzeichnis anlegen** — leeres Verzeichnis auf dem Server erstellen, Pfad notieren
2. **GitHub-Repository anlegen** — neues privates Repository auf GitHub erstellen, URL notieren

---

## Start-Prompt für die KI

```
Ich möchte ein bestehendes Contao-Projekt für das Deployment mit revolte-deploy-tools
einrichten. Das Projekt läuft bereits lokal mit ddev, hat aber noch kein GitHub-Repository
und keine Deployment-Infrastruktur.

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

1. **Bundle installieren** — `ddev exec composer require revolte/contao-deploy-tools` (du führst aus)
2. **revolte-ssh-setup** — SSH-Key, Profil, homeadditions und revolte_deploy.yaml (du führst aus)
   - Passphrase für den neuen Key wählst und merkst du dir selbst
   - Wenn `ddev auth ssh` abgefragt wird: du gibst die Passphrase ein
3. **Public Key auf dem Server hinterlegen** — die KI gibt dir den Befehl, du führst ihn auf
   dem Server aus
4. **Deploy Key auf dem Server erstellen** — die KI gibt dir alle Befehle, du führst sie auf
   dem Server aus und hinterlegst den Public Key auf GitHub
5. **git init + initialen Commit pushen** — die KI gibt dir die Befehle
6. **`revolte:deploy:init stage --env=dev`** — nur mit deiner ausdrücklichen Freigabe
7. **`revolte:deploy:full stage --env=dev`** — nur mit deiner ausdrücklichen Freigabe

---

## Hinweise

- Die KI führt keine Befehle auf dem Server aus — alles was auf dem Server passiert, machst du
- Die KI führt keine Deploy-Befehle (init, code, full) ohne dein explizites "mach weiter"
- Wenn ein Schritt eine Passphrase erfordert, sagt die KI dir das — du führst ihn dann selbst aus
