# Anweisung: KI-gestützte Einrichtung von revolte-deploy-tools

Diese Datei ist eine Anweisung an die KI. Sie beschreibt, wie die KI einen Entwickler durch die Einrichtung von revolte-deploy-tools führt.

---

## Grundprinzipien

1. **Keine Annahmen treffen.** Prüfe jeden Zustand bevor du eine Aktion vorschlägst. Frag nicht "soll ich X einrichten?" wenn du X selbst prüfen kannst.

2. **Prüfen vor Handeln.** Für jeden Einrichtungsschritt gibt es einen Prüf-Befehl. Führe ihn zuerst aus. Nur wenn der Zustand fehlt, gib den Einrichtungsbefehl vor.

3. **Ein Schritt nach dem anderen.** Warte auf die Bestätigung oder das Ergebnis des Entwicklers, bevor du den nächsten Schritt gibst.

4. **Lieber einen Schritt mehr als eine Fehlermeldung.** Wenn unklar ist ob etwas funktioniert, teste es zuerst.

5. **Bei Fehler: Ursache klären.** Analysiere die Fehlermeldung, bevor du weitermachst. Schlag nicht einfach den nächsten Befehl vor.

---

## Was die KI selbst ausführen kann

- `ddev exec php bin/console revolte:deploy:status` — Deploy-Stand prüfen
- `ddev exec php bin/console revolte:deploy:doctor` — Lokale Umgebung prüfen
- `ddev exec php bin/console revolte:deploy:check <env>` — Zielumgebung prüfen
- `ddev exec ssh PROFIL "echo OK"` — SSH-Verbindung aus ddev testen (nach ddev auth ssh)
- `ddev exec ssh PROFIL "ls DATEI 2>/dev/null && echo vorhanden || echo fehlt"` — Dateien auf Server prüfen
- Lokale Dateien lesen (z. B. `~/.ssh/config`, `config/revolte_deploy.yaml`)
- Git-Befehle lokal: `git status`, `git log`, etc.

## Was der Entwickler ausführen muss

Diese Befehle brauchen eine Passphrase oder Passwort-Eingabe — die KI kann sie nicht selbst ausführen:

- `ssh-keygen` — SSH-Key auf dem lokalen Rechner generieren
- `ssh-copy-id` — Public Key auf Server kopieren (braucht einmalig Passwort-Login)
- `ddev auth ssh` — SSH-Keys in den ddev-Container laden
- `ddev restart` — nach Änderungen an `.ddev/homeadditions/`
- `ssh PROFIL "echo OK"` — SSH-Verbindungstest vom Host (außerhalb ddev)
- Deploy Keys in GitHub eintragen (Browser)

---

## Ablauf: SSH-Key auf lokalem Entwickler-Rechner

**Prüfen (KI liest die Datei direkt):**

Lese `~/.ssh/config` und prüfe ob ein passender `IdentityFile`-Eintrag für das Projekt vorhanden ist.  
Alternativ: lass den Entwickler ausführen:

```bash
ls ~/.ssh/revolte/PROJEKTNAME_UMGEBUNG_ed25519 2>/dev/null && echo "vorhanden" || echo "fehlt"
```

→ **vorhanden:** weiter mit SSH-Config prüfen  
→ **fehlt:** Entwickler ausführen lassen:

```bash
mkdir -p ~/.ssh/revolte
ssh-keygen -t ed25519 -f ~/.ssh/revolte/PROJEKTNAME_UMGEBUNG_ed25519 -C "PROJEKTNAME-UMGEBUNG-deploy-key"
```

---

## Ablauf: SSH-Config auf lokalem Rechner

**Prüfen (KI liest `~/.ssh/config`):**

Suche nach einem `Host PROFIL-NAME`-Eintrag mit dem korrekten Hostname, Port und User.

→ **vorhanden:** weiter mit Public Key auf Server prüfen  
→ **fehlt:** Entwickler anleiten, diesen Eintrag in `~/.ssh/config` einzutragen:

```
Host PROFIL-NAME
    HostName IP-ODER-HOSTNAME
    Port PORT
    User BENUTZERNAME
    IdentityFile ~/.ssh/revolte/PROJEKTNAME_UMGEBUNG_ed25519
    IdentitiesOnly yes
```

Wichtig: Bei Hetzner Managed Servern ist der Port oft **222**, nicht 22.

---

## Ablauf: ddev SSH einrichten

**Schritt 1 — homeadditions prüfen (KI liest direkt):**

Prüfe ob `.ddev/homeadditions/.ssh/config.d/` existiert und einen Eintrag für das SSH-Profil enthält.

→ **vorhanden:** weiter mit Schritt 2  
→ **fehlt:** Datei `.ddev/homeadditions/.ssh/config.d/revolte.conf` anlegen mit den SSH-Profil-Einträgen (ohne `IdentityFile`, da Keys über den ddev-Agent laufen), dann Entwickler ausführen lassen:

```bash
ddev restart
```

**Schritt 2 — SSH-Keys in ddev laden:**

Die KI kann nicht prüfen ob Keys bereits geladen sind. Immer nach `ddev start` oder `ddev restart`:

```bash
ddev auth ssh
```

Entwickler wird nach Passphrase gefragt — das ist normal.

**Schritt 3 — Verbindung aus ddev testen (KI führt selbst aus):**

```bash
ddev exec ssh PROFIL-NAME "echo OK"
```

→ **OK:** SSH funktioniert  
→ **Fehler:** Ursache analysieren (Key nicht geladen? Profil nicht in homeadditions? Known-hosts-Problem?)

---

## Ablauf: Public Key auf Server hinterlegen

**Prüfen (KI führt selbst aus, nach ddev auth ssh):**

```bash
ddev exec ssh PROFIL-NAME "echo OK"
```

→ **OK:** Key ist bereits hinterlegt, weiter  
→ **Fehler "Permission denied":** Key fehlt auf Server, Entwickler ausführen lassen:

```bash
ssh-copy-id PROFIL-NAME
```

Falls `ssh-copy-id` nicht funktioniert (Passwort-Login deaktiviert): Public Key anzeigen und Entwickler bitten, ihn manuell in `~/.ssh/authorized_keys` auf dem Server einzutragen.

---

## Ablauf: GitHub Deploy Key auf dem Server einrichten

### 1. Key auf Server prüfen (KI führt selbst aus)

```bash
ddev exec ssh PROFIL-NAME "ls ~/.ssh/id_ed25519_PROJEKTNAME_github 2>/dev/null && echo vorhanden || echo fehlt"
```

→ **vorhanden:** weiter mit Schritt 2  
→ **fehlt:** Entwickler ausführen lassen (kein Leerzeichen im `-C`-Kommentar wegen Quoting über SSH):

```bash
ssh PROFIL-NAME "ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_PROJEKTNAME_github -N '' -C 'PROJEKTNAME-github-deploy-key'"
```

### 2. SSH-Config auf Server prüfen (KI führt selbst aus)

```bash
ddev exec ssh PROFIL-NAME "grep -q 'github-PROJEKTNAME' ~/.ssh/config 2>/dev/null && echo vorhanden || echo fehlt"
```

→ **vorhanden:** weiter mit Schritt 3  
→ **fehlt:** Entwickler ausführen lassen:

```bash
ssh PROFIL-NAME "printf '\nHost github-PROJEKTNAME\n    HostName github.com\n    User git\n    IdentityFile ~/.ssh/id_ed25519_PROJEKTNAME_github\n    IdentitiesOnly yes\n' >> ~/.ssh/config"
```

### 3. Deploy Key bei GitHub prüfen

Public Key anzeigen lassen (KI führt selbst aus):

```bash
ddev exec ssh PROFIL-NAME "cat ~/.ssh/id_ed25519_PROJEKTNAME_github.pub"
```

Den Inhalt dem Entwickler zeigen und fragen ob dieser Key bereits in GitHub unter Repository → **Settings → Deploy keys** eingetragen ist.

→ **ja:** weiter mit Schritt 4  
→ **nein:** Entwickler anleiten, den Key in GitHub einzutragen (kein Write access nötig)

### 4. GitHub in known_hosts prüfen (KI führt selbst aus)

```bash
ddev exec ssh PROFIL-NAME "ssh-keygen -F github.com 2>/dev/null && echo vorhanden || echo fehlt"
```

→ **vorhanden:** weiter mit Schritt 5  
→ **fehlt:** Entwickler ausführen lassen:

```bash
ssh PROFIL-NAME "ssh-keyscan github.com >> ~/.ssh/known_hosts"
```

### 5. Verbindung zu GitHub testen (KI führt selbst aus)

```bash
ddev exec ssh PROFIL-NAME "ssh -o BatchMode=yes -T git@github-PROJEKTNAME 2>&1"
```

Erwartete Ausgabe enthält: `You've successfully authenticated`

→ **Erfolg:** GitHub Deploy Key ist vollständig eingerichtet  
→ **Fehler:** Ursache analysieren (Key nicht in GitHub? Config falsch? known_hosts fehlt?)

---

## Ablauf: revolte:deploy:init

**Voraussetzungen prüfen (KI führt selbst aus):**

```bash
ddev exec php bin/console revolte:deploy:status
```

Prüfe ob die Umgebung als "Nicht initialisiert" angezeigt wird.

**Init ausführen (KI führt selbst aus):**

```bash
ddev exec php bin/console revolte:deploy:init UMGEBUNG
```

Häufige Fehler nach Init:
- **Git Clone fehlgeschlagen / hostname not found:** GitHub Deploy Key fehlt auf dem Server → Ablauf "GitHub Deploy Key" durchführen
- **Permission denied:** SSH-Key nicht auf Server hinterlegt

**Nach erfolgreichem Init:**

Entwickler anleiten, `.env.local` auf dem Server anzulegen (→ `server-einrichtung.md`).  
Danach Status erneut prüfen:

```bash
ddev exec php bin/console revolte:deploy:status
```

---

## Ablauf: Erster Deploy

**Dry-run zuerst (KI führt selbst aus):**

```bash
ddev exec php bin/console revolte:deploy:code UMGEBUNG --dry-run
```

Ausgabe mit dem Entwickler besprechen — zeigt welche Commits deployed werden und ob composer.lock sich ändert.

**Full Deploy ausführen (KI führt selbst aus):**

```bash
ddev exec php bin/console revolte:deploy:full UMGEBUNG
```

**Danach Status prüfen:**

```bash
ddev exec php bin/console revolte:deploy:status
```

Die Umgebung sollte jetzt `✓ aktuell` zeigen.
