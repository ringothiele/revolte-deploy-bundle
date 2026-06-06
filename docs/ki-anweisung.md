# Anweisung: KI-gestützte Einrichtung von revolte-deploy-tools

Diese Datei ist eine Anweisung an die KI. Sie beschreibt, wie die KI einen Entwickler durch die Einrichtung von revolte-deploy-tools führt.

---

## SSH-Key-Konvention — immer einhalten

Jeder Entwickler hat pro Projekt und Umgebung einen eigenen SSH-Key.  
**Namensschema:** `~/.ssh/revolte/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519`

Kürzel im Team: `rt` (Ringo Thiele), `pl`, `sl`

Beispiel: `rt_rhodetec_stage_ed25519` — nicht `rhodetec_stage_ed25519`.

Prüfe zuerst ob ein Key mit dem richtigen Namen existiert, bevor du einen neuen vorschlägst.  
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
- `ssh-add ~/.ssh/revolte/KÜRZEL_...` — Key in Host-SSH-Agent laden (fragt nach Passphrase); muss vor `ddev auth ssh` ausgeführt werden
- `ddev auth ssh` — geladene Host-Keys in ddev-Container übertragen (keine Passphrase, aber Schritt 2 muss vorher passiert sein)
- `ssh-copy-id` — Key auf Server kopieren (fragt nach Server-Passwort)
- `ddev restart` / `ddev start` — ddev-Containerverwaltung

**Direkter SSH-Zugriff vom WSL2-Host auf Server — funktioniert grundsätzlich nicht:**

Managed Server (z. B. Hetzner) sind vom WSL2-Host aus in der Regel nicht direkt erreichbar. Alle SSH-Verbindungen zum Server laufen ausschließlich über `ddev exec ssh PROFIL ...` (aus dem ddev-Container heraus). Niemals versuchen, vom Host aus per SSH auf den Server zuzugreifen — das schlägt fehl und kann fail2ban auslösen.

**Browser-Aktionen:**

- Deploy Keys in GitHub eintragen
- Server-Control-Panel (Plesk, Hetzner Robot) bedienen

**git push:**

Niemals `git push` ausführen. Commits sind ok — Push macht immer der Entwickler selbst.

---

## Ablauf: SSH-Key auf lokalem Entwickler-Rechner

**Namensschema:** `KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519`  
Kürzel im Team: `rt` (Ringo), `pl`, `sl`

**Prüfen (KI liest die Datei direkt):**

Lese `~/.ssh/config` und prüfe ob ein passender `IdentityFile`-Eintrag für Entwickler + Projekt + Umgebung vorhanden ist.  
Alternativ: lass den Entwickler ausführen:

```bash
ls ~/.ssh/revolte/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519 2>/dev/null && echo "vorhanden" || echo "fehlt"
```

→ **vorhanden:** weiter mit SSH-Config prüfen  
→ **fehlt:** Entwickler ausführen lassen:

```bash
mkdir -p ~/.ssh/revolte
ssh-keygen -t ed25519 -f ~/.ssh/revolte/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519 -C "KÜRZEL-PROJEKTNAME-UMGEBUNG"
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
    IdentityFile ~/.ssh/revolte/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519
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

**Schritt 2 — Key in Host-SSH-Agent laden:**

`ddev auth ssh` leitet nur Keys weiter, die bereits im SSH-Agent des Hosts geladen sind. Keys in Unterordnern wie `~/.ssh/revolte/` werden nicht automatisch gefunden.

Entwickler ausführen lassen (fragt nach Passphrase):

```bash
ssh-add ~/.ssh/revolte/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519
```

Prüfen ob der Key geladen ist (KI führt selbst aus):

```bash
ssh-add -l
```

→ Der Key sollte in der Liste erscheinen. Falls nicht: `ssh-add`-Befehl wiederholen.

**Schritt 3 — Keys in ddev-Container übertragen:**

Entwickler ausführen lassen:

```bash
ddev auth ssh
```

`ddev auth ssh` leitet alle im Host-Agent geladenen Keys in den ddev-Container weiter — keine erneute Passphrase-Eingabe.

**Schritt 4 — Prüfen ob Key im ddev-Agent geladen ist (KI führt selbst aus):**

```bash
ddev exec ssh-add -l
```

→ Der Key (`KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519`) muss in der Liste erscheinen.  
→ **nicht in Liste:** Schritt 2 und 3 wiederholen.

**Verboten — diese Workarounds niemals vorschlagen:**
- Symlinks von `~/.ssh/` auf `~/.ssh/revolte/` anlegen
- Keys direkt im ddev-Container laden
- Keys nach `~/.ssh/` verschieben oder kopieren

Der einzig korrekte Weg: `ssh-add` auf dem Host, dann `ddev auth ssh`.

**Schritt 4 — Verbindung aus ddev testen (KI führt selbst aus):**

```bash
ddev exec ssh PROFIL-NAME "echo OK"
```

→ **OK:** SSH funktioniert  
→ **Permission denied:** Key noch nicht auf Server hinterlegt → Ablauf "Public Key auf Server hinterlegen"  
→ **Anderer Fehler:** Ursache analysieren (Profil nicht in homeadditions? Known-hosts-Problem?)

---

## Ablauf: Public Key auf Server hinterlegen

**Wichtig:** `ssh-copy-id` vom WSL2-Host funktioniert in der Regel nicht, da der Server vom Host aus nicht direkt erreichbar ist. Stattdessen wird ein bestehender Serverzugang genutzt um den Key zu hinterlegen.

**Schritt 1 — Public Key lesen (KI liest direkt):**

Lese die Datei `~/.ssh/revolte/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519.pub` und merke dir den Inhalt.

**Schritt 2 — Bestehenden Serverzugang ermitteln:**

Frag den Entwickler:

> "Hast du bereits SSH-Zugang zu diesem Server über ein anderes Profil — z. B. ein anderes Projekt auf demselben Server oder einen temporären Passwort-Login?"

→ **Ja, anderes SSH-Profil vorhanden:** Weiter mit Schritt 3a  
→ **Ja, Passwort-Login möglich:** Weiter mit Schritt 3b  
→ **Nein:** Entwickler muss den Public Key über das Server-Control-Panel (z. B. Hetzner Robot / Plesk) manuell in `~/.ssh/authorized_keys` eintragen. Key-Inhalt anzeigen und warten.

**Schritt 3a — Key über bestehendes SSH-Profil hinterlegen (KI führt selbst aus):**

```bash
ddev exec ssh BESTEHENDES-PROFIL "echo 'INHALT-DES-PUBLIC-KEY' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
```

(Ersetze `INHALT-DES-PUBLIC-KEY` durch den tatsächlichen Inhalt aus Schritt 1.)

**Schritt 3b — Key über Passwort-Login hinterlegen:**

Entwickler ausführen lassen (fragt nach Server-Passwort):

```bash
ssh-copy-id -i ~/.ssh/revolte/KÜRZEL_PROJEKTNAME_UMGEBUNG_ed25519.pub -p PORT BENUTZER@HOSTNAME
```

**Schritt 4 — Verbindung prüfen (KI führt selbst aus):**

```bash
ddev exec ssh PROFIL-NAME "echo OK"
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
