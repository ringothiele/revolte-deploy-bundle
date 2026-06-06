# Einrichtung mit KI-Unterstützung

Du kannst die Einrichtung von revolte-deploy-tools mit einer KI (z. B. Claude Code) durchführen.  
Die KI führt dich Schritt für Schritt durch den Prozess, prüft selbst was sie kann, und gibt dir Befehle für alles was eine Passphrase oder Passwort-Eingabe braucht.

---

## Wie es funktioniert

- Die KI prüft jeden Schritt **bevor** sie ihn ausführt — keine Annahmen, keine blinden Aktionen
- **Was die KI selbst erledigt:** Status abfragen, Verbindungen testen, Deploy-Befehle ausführen
- **Was du ausführst:** Alles mit SSH-Passphrase (`ddev auth ssh`, `ssh-keygen`, `ssh-copy-id`) und GitHub-Aktionen im Browser

Damit die KI weiß wie sie vorgehen soll, referenziert sie die Datei  
`packages/revolte-deploy-tools/docs/ki-anweisung.md` in diesem Repo.

---

## Session starten

Öffne Claude Code im Projektordner:

```bash
cd ~/projekte/mein-projekt
claude
```

Dann einen der Prompts unten kopieren und anpassen.

---

## Empfohlene Prompts

### Vollständige Ersteinrichtung (neues Projekt)

Für ein frisch geklontes Projekt das noch nie deployed wurde:

```
Ich möchte revolte-deploy-tools für dieses Projekt einrichten.
Projektname: [NAME]
Stage-Umgebung:
  - SSH-Profil: [z. B. kundea-stage]
  - Server: [IP oder Hostname], Port [PORT], User [BENUTZERNAME]
  - Remote-Pfad: [z. B. /usr/www/users/...]
  - GitHub-Repo: [z. B. git@github.com:revolte/kundea.git]

Führe mich durch die komplette Einrichtung.
Orientiere dich dabei an packages/revolte-deploy-tools/docs/ki-anweisung.md.
```

---

### Neue Umgebung zu bestehendem Projekt hinzufügen

Für ein Projekt das bereits eine Stage-Umgebung hat und jetzt eine Live-Umgebung bekommt:

```
Ich möchte für dieses Projekt eine neue Umgebung einrichten.
Umgebungsname: [z. B. live]
SSH-Profil: [z. B. kundea-live]
Server: [IP oder Hostname], Port [PORT], User [BENUTZERNAME]
Remote-Pfad: [z. B. /usr/www/users/...]

Prüfe zuerst ob die Umgebung bereits in config/revolte_deploy.yaml eingetragen ist.
Führe mich dann durch SSH-Einrichtung, GitHub Deploy Key und Init.
Orientiere dich dabei an packages/revolte-deploy-tools/docs/ki-anweisung.md.
```

---

### Nur SSH-Zugang einrichten

Wenn du dich gerade zum ersten Mal mit einem Server verbindest:

```
Ich möchte SSH-Zugang zum Server für das Profil [PROFIL-NAME] einrichten.
Server: [IP oder Hostname], Port [PORT], User [BENUTZERNAME]

Prüfe zuerst ob bereits ein Key und ein Config-Eintrag vorhanden sind.
Führe mich dann durch alles was noch fehlt.
Orientiere dich dabei an packages/revolte-deploy-tools/docs/ki-anweisung.md.
```

---

### Nur GitHub Deploy Key einrichten

Wenn Init mit "Could not resolve hostname github-..." fehlschlägt:

```
Das revolte:deploy:init ist mit einem GitHub-Fehler fehlgeschlagen.
Das SSH-Profil für den Server ist [PROFIL-NAME], der Projektname ist [PROJEKTNAME].

Prüfe welche Schritte für den GitHub Deploy Key noch fehlen und führe mich durch die Einrichtung.
Orientiere dich dabei an packages/revolte-deploy-tools/docs/ki-anweisung.md.
```

---

### ddev SSH-Probleme beheben

Wenn Deploy-Commands mit "SSH fehlgeschlagen" scheitern:

```
Meine Deploy-Commands schlagen mit SSH-Fehlern fehl.
Ich habe ddev start ausgeführt.

Prüfe ob ddev auth ssh ausgeführt wurde, ob die SSH-Profile in homeadditions vorhanden sind,
und ob die Verbindung zu den konfigurierten Umgebungen funktioniert.
Orientiere dich dabei an packages/revolte-deploy-tools/docs/ki-anweisung.md.
```

---

## Hinweise

- Die KI bittet dich an bestimmten Stellen, Befehle selbst auszuführen — das sind immer Stellen wo eine Passphrase nötig ist
- GitHub Deploy Keys musst du selbst im Browser eintragen (Repository → Settings → Deploy keys)
- Nach `ddev restart` immer `ddev auth ssh` erneut ausführen
