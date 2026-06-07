# Anweisung: KI-gestützte Einrichtung von revolte-deploy-tools

Diese Datei ist eine Anweisung an die KI. Sie beschreibt, wie die KI einen Entwickler durch die Einrichtung von revolte-deploy-tools führt.

---

## ABSOLUTES VERBOT — vor jeder SSH-Aktion lesen

**Die KI darf niemals eine SSH-Verbindung zum Server versuchen, bevor alle drei Voraussetzungen erfüllt sind:**

1. Ein `Host PROFIL-NAME`-Eintrag existiert in `.ddev/homeadditions/.ssh/config.d/revolte.conf`
2. Der Entwickler hat `ddev auth ssh` ausgeführt
3. `ddev exec ssh-add -l` zeigt den richtigen Key in der Liste

**Erst wenn alle drei Punkte bestätigt sind, darf die KI `ddev exec ssh PROFIL-NAME "..."` ausführen.**

Verboten — auch wenn es "schneller" erscheint:
- `ddev exec ssh -p PORT user@IP "..."` — umgeht das Profil, löst Passwort-Versuche und damit **fail2ban** aus
- `ssh ...` ohne `ddev exec` — WSL2-Host hat keine direkte Verbindung zum Server
- SSH-Verbindung versuchen wenn das Profil noch nicht in homeadditions eingetragen ist
- SSH-Test ohne `-o BatchMode=yes` — ohne dieses Flag fragt SSH interaktiv nach dem Passwort, mehrfache Fehlversuche lösen **fail2ban** aus

**Bei Verstoß gegen diese Regel kann die IP des Servers gesperrt werden (fail2ban).**

---

## SSH-Key-Konvention — immer einhalten

Ein Key pro Entwickler pro Server-Account — nicht pro Projekt.  
Mehrere Projekte auf demselben Server-Account teilen sich denselben Key.

**Namensschema:** `~/.ssh/KÜRZEL_SERVERACCOUNT_ed25519`

Kürzel im Team: `rt` (Ringo Thiele), `pl`, `sl`

Beispiele:
- `rt_revolte-labor_ed25519` — Ringo auf revolte-labor.de (alle Stage-Projekte)
- `rt_kundenname_ed25519` — Ringo auf einem Kunden-Live-Server

Prüfe zuerst ob für diesen Server-Account bereits ein Key des Entwicklers existiert.  
Wenn ja: diesen Key verwenden — keinen neuen erstellen.  
Schlage niemals vor, Keys umzubenennen, zu verschieben, zu kopieren oder Symlinks anzulegen.

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
- `ddev exec ssh PROFIL "echo OK"` — SSH-Verbindung aus ddev testen
- `ddev exec ssh PROFIL "ls DATEI 2>/dev/null && echo vorhanden || echo fehlt"` — Dateien auf Server prüfen
- `ddev exec ssh-add -l` — prüfen welche Keys im ddev-Agent geladen sind
- `ddev exec ssh PROFIL "cat ~/.ssh/KEYNAME.pub"` — Public Key auf Server lesen
- Lokale Dateien lesen (z. B. `~/.ssh/config`, `config/revolte_deploy.yaml`)
- Git-Befehle lokal: `git status`, `git log`, `git add`, `git commit` etc.
- Deploy-Commands via ddev: `ddev exec php bin/console revolte:deploy:*`

## Was die KI NICHT ausführen kann — immer Entwickler ausführen lassen

**Passphrase/Passwort-Befehle — KI kann keine interaktive Eingabe machen:**

- `ssh-keygen` — SSH-Key generieren (fragt nach Passphrase)
- `ddev auth ssh` — Keys aus `~/.ssh/` in ddev-Container laden (fragt nach Passphrase für jeden Key)
- `ssh-copy-id` — Key auf Server kopieren (fragt nach Server-Passwort)
- `ddev restart` / `ddev start` — ddev-Containerverwaltung

**SSH zum Server — nur über Profilnamen, nie über IP/Port direkt:**

Alle SSH-Verbindungen zum Server laufen ausschließlich über `ddev exec ssh PROFILNAME "..."`.  
PROFILNAME ist der `Host`-Alias aus `~/.ssh/config`, z. B. `prod-server` oder `rhodetec-stage`.

Niemals `ddev exec ssh -p PORT user@IP "..."` verwenden — das umgeht den SSH-Config-Eintrag, wählt keinen Key automatisch aus und löst durch Passwort-Versuche fail2ban aus.

Niemals vom WSL2-Host aus direkt auf den Server verbinden (`ssh ...` ohne `ddev exec`) — der Server ist vom Host aus nicht erreichbar.

**Browser-Aktionen:**

- Deploy Keys in GitHub eintragen
- Server-Control-Panel (Plesk, Hetzner Robot) bedienen

**git push:**

Niemals `git push` ausführen. Commits sind ok — Push macht immer der Entwickler selbst.

---

## Ablauf: SSH-Key auf lokalem Entwickler-Rechner

Ein Key gilt pro Entwickler und Server-Account — nicht pro Projekt. Vor jeder neuen Einrichtung prüfen ob bereits ein passender Key existiert.

**Schritt 1 — Vorhandene Keys und Profile ermitteln (KI liest direkt):**

Lese `~/.ssh/config` und liste alle vorhandenen `Host`-Einträge mit ihrem `HostName`, `Port`, `User` und `IdentityFile`.

Prüfe dann: gibt es einen Eintrag der auf denselben Server zeigt (gleicher HostName + Port + User) wie die neue Umgebung?

→ **Ja, passender Eintrag gefunden:** Den Entwickler fragen:

> "Für diesen Server existiert bereits ein SSH-Key: `[KEYNAME]` (Profil `[PROFILNAME]`).  
> Soll ich diesen Key auch für das neue Profil verwenden, oder einen neuen Key anlegen?"

  - **Bestehenden Key verwenden:** IdentityFile aus dem vorhandenen Eintrag übernehmen → weiter mit SSH-Config
  - **Neuen Key anlegen:** weiter mit Schritt 2

→ **Nein, kein passender Eintrag:** weiter mit Schritt 2

**Schritt 2 — Neuen Key generieren (nur wenn Schritt 1 keinen passenden Key ergeben hat):**

Entwickler ausführen lassen:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/KÜRZEL_SERVERACCOUNT_ed25519 -C "KÜRZEL-SERVERACCOUNT"
```

Beispiel für Ringo auf revolte-labor.de:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/rt_revolte-labor_ed25519 -C "rt-revolte-labor"
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
    IdentityFile ~/.ssh/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519
    IdentitiesOnly yes
```

Wichtig: Bei Hetzner Managed Servern ist der Port oft **222**, nicht 22.

---

## Ablauf: ddev SSH einrichten

Die Schritte müssen in dieser Reihenfolge ausgeführt werden — kein Schritt überspringen.

**Schritt 1 — homeadditions prüfen (KI liest direkt):**

Prüfe ob `.ddev/homeadditions/.ssh/config.d/revolte.conf` existiert und einen Eintrag für das SSH-Profil enthält.

→ **vorhanden:** weiter mit Schritt 2  
→ **fehlt:** Datei `.ddev/homeadditions/.ssh/config.d/revolte.conf` anlegen mit dem SSH-Profil-Eintrag (ohne `IdentityFile`, da Keys über den ddev-Agent laufen):

```
Host PROFIL-NAME
    HostName IP-ODER-HOSTNAME
    Port PORT
    User BENUTZERNAME
    IdentitiesOnly yes
```

Danach Entwickler ausführen lassen:

```bash
ddev restart
```

**Schritt 2 — Keys in ddev-Container laden (Entwickler ausführen lassen):**

`ddev auth ssh` scannt `~/.ssh/` direkt nach privaten Key-Dateien und lädt sie in den ddev-SSH-Agent. Es nutzt dabei **nicht** den Host-SSH-Agent — Keys müssen deshalb direkt in `~/.ssh/` liegen, nicht in Unterordnern.

Entwickler ausführen lassen (fragt für jeden gefundenen Key nach der Passphrase):

```bash
ddev auth ssh
```

**Schritt 3 — Prüfen ob Key im ddev-Agent geladen ist (KI führt selbst aus):**

```bash
ddev exec ssh-add -l
```

→ Der Key (`KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519`) muss in der Liste erscheinen.  
→ **nicht in Liste:** Key liegt nicht direkt in `~/.ssh/` oder Passphrase wurde falsch eingegeben.

**Verboten — diese Workarounds niemals vorschlagen:**
- Symlinks in `~/.ssh/` anlegen
- Keys direkt im ddev-Container laden
- Keys in Unterordner wie `~/.ssh/revolte/` legen — `ddev auth ssh` findet sie dort nicht

Der einzig korrekte Weg: Key in `~/.ssh/` → `ddev auth ssh` → `ddev exec ssh-add -l` zur Verifikation.

**Schritt 4 — Verbindung aus ddev testen (KI führt selbst aus — erst nach Schritt 3 bestätigt):**

`BatchMode=yes` ist Pflicht — verhindert interaktive Passwort-Abfragen die fail2ban auslösen würden.

```bash
ddev exec ssh -o BatchMode=yes PROFIL-NAME "echo OK"
```

→ **OK:** SSH funktioniert  
→ **Permission denied:** Key noch nicht auf Server hinterlegt → Ablauf "Public Key auf Server hinterlegen"  
→ **Anderer Fehler:** Ursache analysieren (Profil nicht in homeadditions? Known-hosts-Problem?)

---

## Ablauf: Public Key auf Server hinterlegen

**Wichtig:** `ssh-copy-id` vom WSL2-Host funktioniert in der Regel nicht, da der Server vom Host aus nicht direkt erreichbar ist. Stattdessen wird ein bestehender Serverzugang genutzt um den Key zu hinterlegen.

Wenn der Key gerade erst neu generiert wurde, kann er noch nicht auf dem Server sein — den Test mit `ddev exec ssh ... "echo OK"` überspringen und direkt mit Schritt 2 anfangen.

**Schritt 1 — Public Key lesen (KI führt selbst aus):**

```bash
cat ~/.ssh/KÜRZEL_SERVERACCOUNT_ed25519.pub
```

**Schritt 2 — Bestehenden Serverzugang ermitteln:**

Prüfe zuerst selbst: gibt es in `~/.ssh/config` bereits ein anderes SSH-Profil das auf denselben Server (gleicher HostName + Port + User) zeigt? Wenn ja, dieses nutzen.

Falls unklar: Entwickler fragen:

> "Hast du bereits SSH-Zugang zu diesem Server über ein anderes Profil — z. B. ein anderes Projekt auf demselben Server?"

→ **Ja, anderes SSH-Profil vorhanden:** Weiter mit Schritt 3a  
→ **Ja, Passwort-Login möglich:** Weiter mit Schritt 3b  
→ **Nein:** Entwickler muss den Public Key über das Server-Control-Panel (z. B. Hetzner Robot / Plesk) manuell in `~/.ssh/authorized_keys` eintragen. Key-Inhalt anzeigen und warten.

**Schritt 3a — Key über bestehendes SSH-Profil hinterlegen (KI führt selbst aus):**

```bash
ddev exec ssh BESTEHENDES-PROFIL "echo 'INHALT-DES-PUBLIC-KEY' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
```

`BESTEHENDES-PROFIL` ist der `Host`-Alias aus `~/.ssh/config` — niemals `user@IP` oder `-p PORT` verwenden.

**Schritt 3b — Key über Passwort-Login hinterlegen:**

Entwickler ausführen lassen (fragt nach Server-Passwort):

```bash
ssh-copy-id -i ~/.ssh/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519.pub -p PORT BENUTZER@HOSTNAME
```

**Schritt 4 — Verbindung prüfen (KI führt selbst aus):**

```bash
ddev exec ssh -o BatchMode=yes PROFIL-NAME "echo OK"
```

→ **OK:** Key erfolgreich hinterlegt  
→ **Permission denied:** Key wurde nicht korrekt eingetragen — `authorized_keys` auf Server prüfen

---

## Ablauf: GitHub Deploy Key auf dem Server einrichten

### 1. Key auf Server prüfen (KI führt selbst aus)

```bash
ddev exec ssh PROFIL-NAME "ls ~/.ssh/id_ed25519_PROJEKTNAME_github 2>/dev/null && echo vorhanden || echo fehlt"
```

→ **vorhanden:** weiter mit Schritt 2  
→ **fehlt:** KI führt selbst aus (kein Leerzeichen im `-C`-Kommentar wegen Quoting über SSH):

```bash
ddev exec ssh PROFIL-NAME "ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_PROJEKTNAME_github -N '' -C 'PROJEKTNAME-github-deploy-key'"
```

### 2. SSH-Config auf Server prüfen (KI führt selbst aus)

```bash
ddev exec ssh PROFIL-NAME "grep -q 'github-PROJEKTNAME' ~/.ssh/config 2>/dev/null && echo vorhanden || echo fehlt"
```

→ **vorhanden:** weiter mit Schritt 3  
→ **fehlt:** KI führt selbst aus:

```bash
ddev exec ssh PROFIL-NAME "printf '\nHost github-PROJEKTNAME\n    HostName github.com\n    User git\n    IdentityFile ~/.ssh/id_ed25519_PROJEKTNAME_github\n    IdentitiesOnly yes\n' >> ~/.ssh/config"
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
→ **fehlt:** KI führt selbst aus:

```bash
ddev exec ssh PROFIL-NAME "ssh-keyscan github.com >> ~/.ssh/known_hosts"
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
