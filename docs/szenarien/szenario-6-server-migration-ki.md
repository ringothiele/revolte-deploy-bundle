# Szenario 6: Server-Migration — Mit KI-Unterstützung

Die KI begleitet die Migration und schlägt alle Befehle vor.
Ausführen tust immer du — kein Schritt läuft ohne deine Bestätigung.

---

## Vorbereitung (du, ohne KI)

1. **Verzeichnis auf dem neuen Server anlegen** — leeres Verzeichnis erstellen, Pfad notieren
2. **SSH-Zugang zum neuen Server** sicherstellen

---

## Start-Prompt für die KI

```
Ich möchte ein bestehendes Projekt auf einen neuen Server migrieren. Stage und Live laufen
bereits mit revolte-deploy-tools. Die neue Live-Umgebung soll parallel eingerichtet und
nach dem Test durch Domain-Umschaltung aktiviert werden.

Projektname: PROJEKTNAME
Neue Live-Umgebung:
  Name: live-new
  SSH-Profil: PROJEKTNAME-live-new
  Server: NEUE-IP
  Port: 222
  User: NEUER-SERVERACCOUNT
  Remote-Pfad: /usr/www/users/NEUER-SERVERACCOUNT/PROJEKTNAME
```

---

## Ablauf

1. **revolte-ssh-setup** — neue Umgebung `live-new` in YAML eintragen (du führst aus)
2. **SSH-Zugang einrichten** — KI gibt Befehle, du führst auf dem neuen Server aus
3. **Deploy Key auf neuem Server** — KI gibt alle Befehle, du führst aus und hinterlegst
   Key auf GitHub
4. **Freigabedateien anlegen** — KI gibt Befehle, du führst auf neuem Server aus
5. **`revolte:deploy:init live-new`** — nur mit deiner ausdrücklichen Freigabe
6. **`revolte:deploy:full live-new`** — nur mit deiner ausdrücklichen Freigabe
7. **Content übertragen** — content:pull von live, content:push auf live-new
   (beide nur mit deiner Freigabe)
8. **Domain umbiegen** — du im Hoster-Panel
9. **Aufräumen** — KI schlägt Anpassungen in revolte_deploy.yaml vor, du entscheidest

---

## Hinweise

- Der alte Live-Server wird während der gesamten Migration nicht verändert
- Die KI führt keine Deploy-Befehle ohne dein explizites "mach weiter"
- Domain erst umbiegen wenn die neue Umgebung vollständig geprüft ist
- Den alten Server-Ordner erst nach einer Übergangszeit abbauen
