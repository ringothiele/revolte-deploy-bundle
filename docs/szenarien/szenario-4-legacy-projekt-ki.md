# Szenario 4: Legacy-Projekt — Mit KI-Unterstützung

Die KI begleitet die Einrichtung und schlägt alle Befehle vor.
Ausführen tust immer du — kein Schritt läuft ohne deine Bestätigung.

---

> **Wichtig: Der bestehende Server-Ordner wird nicht verändert.**
> Auf den Legacy-Ordner wird nur lesend zugegriffen. Für das Deployment wird ein neuer Ordner
> auf dem Server angelegt. Der Domain-Wechsel auf die neue Struktur erfolgt ganz am Ende.

---

## Vorbereitung (du, ohne KI)

1. **Contao-Version auf dem Server prüfen** — auf dem Server ausführen:
   `php -r "echo \Contao\CoreBundle\ContaoCoreBundle::getVersion();"`
2. **GitHub-Repository anlegen** — neues privates Repository erstellen, URL notieren
3. **Neuen Server-Ordner anlegen** — leeres Verzeichnis neben dem Legacy-Ordner erstellen,
   Pfad notieren

---

## Start-Prompt für die KI

```
Ich möchte ein Legacy-Contao-Projekt in eine saubere Deployment-Infrastruktur überführen.
Das Projekt existiert bisher nur auf dem Server, ohne Git-Repository und ohne Deploy-Struktur.

Projektname: PROJEKTNAME
Mein Entwicklerkürzel: XX
Contao-Version auf dem Server: X.X
Legacy-Server:
  SSH-Profil: PROJEKTNAME-live
  Server: IP-ADRESSE
  Port: 222
  User: SERVERACCOUNT
  Legacy-Pfad (read-only): /usr/www/users/SERVERACCOUNT/LEGACY-ORDNER
  Neuer Deploy-Pfad: /usr/www/users/SERVERACCOUNT/PROJEKTNAME-new
GitHub-Repo: git@github.com:ORGANISATION/PROJEKTNAME.git
```

---

## Ablauf

Die KI führt dich durch diese Schritte — sie schlägt jeden Befehl vor, du führst ihn aus:

1. **revolte-setup** — gleiche Contao-Version wie auf dem Server wählen (du führst aus)
2. **revolte-ssh-setup** — SSH-Key, Profil, homeadditions, YAML (du führst aus)
3. **Public Key auf dem Server hinterlegen** — KI gibt dir den Befehl, du führst ihn aus
4. **`revolte:legacy:code:pull live`** — KI schlägt den Befehl vor, du führst ihn aus
5. **`revolte:deploy:content:pull live --skip-git-pull`** — du führst aus
6. **git init + initialer Commit + push** — KI gibt die Befehle vor, du führst aus
7. **Deploy Key auf dem Server einrichten** — KI gibt alle Befehle, du führst auf dem Server aus
   und hinterlegst den Public Key auf GitHub
8. **`revolte:deploy:init live`** — nur mit deiner ausdrücklichen Freigabe
9. **`revolte:deploy:full live`** — nur mit deiner ausdrücklichen Freigabe
10. **Domain umbiegen** — KI erklärt was zu tun ist, du führst es im Hoster-Panel durch

---

## Hinweise

- Die KI greift nicht auf den Legacy-Ordner schreibend zu — ausschließlich rsync-Lesezugriff
- Die KI führt keine Deploy-Befehle (init, code, full) ohne dein explizites "mach weiter"
- Wenn ein Schritt eine Passphrase erfordert, sagt die KI dir das — du führst ihn dann selbst aus
- Den Legacy-Ordner auf dem Server erst löschen, wenn die neue Struktur läuft und die Domain
  umgebogen ist
