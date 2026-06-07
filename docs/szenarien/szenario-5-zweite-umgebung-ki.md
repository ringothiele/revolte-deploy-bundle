# Szenario 5: Zweite Umgebung hinzufügen — Mit KI-Unterstützung

Die KI begleitet die Einrichtung und schlägt alle Befehle vor.
Ausführen tust immer du — kein Schritt läuft ohne deine Bestätigung.

---

## Vorbereitung (du, ohne KI)

1. **Server-Verzeichnis anlegen** — leeres Verzeichnis für die neue Umgebung erstellen,
   Pfad notieren

---

## Start-Prompt für die KI

```
Ich möchte zu einem bestehenden Projekt eine zweite Deployment-Umgebung hinzufügen.
Stage läuft bereits, jetzt soll Live dazukommen.

Projektname: PROJEKTNAME
Mein Entwicklerkürzel: XX
Neue Umgebung:
  Name: live
  SSH-Profil: PROJEKTNAME-live
  Server: IP-ADRESSE
  Port: 222
  User: SERVERACCOUNT
  Remote-Pfad: /usr/www/users/SERVERACCOUNT/PROJEKTNAME-live
  Branch: main
```

---

## Ablauf

1. **revolte-ssh-setup** — neue Umgebung in bestehende YAML eintragen (du führst aus)
2. **Public Key auf dem Server hinterlegen** — KI gibt dir den Befehl, du führst ihn aus
3. **Deploy Key prüfen** — KI prüft ob der bestehende Key ausreicht oder ein neuer nötig ist
4. **Freigabedateien anlegen** — KI gibt dir die Befehle, du führst sie auf dem Server aus
5. **`revolte:deploy:init live`** — nur mit deiner ausdrücklichen Freigabe
6. **`revolte:deploy:full live`** — nur mit deiner ausdrücklichen Freigabe

---

## Hinweise

- Die bestehende Stage-Konfiguration wird nicht verändert
- Die KI führt keine Deploy-Befehle ohne dein explizites "mach weiter"
