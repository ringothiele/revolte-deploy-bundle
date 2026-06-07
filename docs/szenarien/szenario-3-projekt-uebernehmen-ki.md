# Szenario 3: Projekt übernehmen / zweiter Entwickler — Mit KI-Unterstützung

Die KI begleitet die Einrichtung und schlägt alle Befehle vor.
Ausführen tust immer du — kein Schritt läuft ohne deine Bestätigung.

---

## Vorbereitung (du, ohne KI)

1. **Zugang zum GitHub-Repository** sicherstellen
2. **Public Key** — nach Schritt 5 wird dein Public Key benötigt. Besprich mit dem bisherigen
   Entwickler, dass er ihn auf dem Server hinterlegt, oder kläre deinen eigenen SSH-Zugang

---

## Start-Prompt für die KI

```
Ich möchte ein bestehendes Contao-Projekt lokal einrichten. Das Projekt ist bereits vollständig
mit revolte-deploy-tools aufgesetzt — Git-Repo, Server und revolte_deploy.yaml existieren bereits.
Ich bin ein neuer Entwickler und richte das Projekt auf meinem Rechner ein.

Projektname: PROJEKTNAME
Mein Entwicklerkürzel: XX
GitHub-Repo: git@github.com:ORGANISATION/PROJEKTNAME.git
Stage-Umgebung:
  SSH-Profil: PROJEKTNAME-stage
  Server: IP-ADRESSE
  Port: 222
  User: SERVERACCOUNT
```

---

## Ablauf

Die KI führt dich durch diese Schritte — sie schlägt jeden Befehl vor, du führst ihn aus:

1. **`git clone` + `ddev start`** — KI gibt die Befehle vor (du führst aus)
2. **`ddev exec composer install`** — du führst aus
3. **`.env.local` anlegen** — KI erstellt die Datei mit ddev-Standard-Datenbankverbindung
4. **`revolte-ssh-setup`** — erkennt bestehende YAML, richtet nur deinen Key + Profil ein
   (du führst aus, Passphrase wählst und merkst du dir selbst)
5. **Public Key auf dem Server hinterlegen** — KI gibt dir den Befehl oder den Key zum
   Weitergeben; du oder der bisherige Entwickler trägt ihn ein
6. **`revolte:deploy:content:pull stage --env=dev`** — nur mit deiner ausdrücklichen Freigabe

---

## Wichtig: Absprache vor dem Start

Bevor du die KI hinzuziehst, sprich dich zwingend mit dem bisherigen Entwickler ab:

- **Branching-Strategie** — wird für neue Features ein eigener Branch angelegt oder wird
  direkt auf `main` gearbeitet?
- **Wer deployt wann** — wer gibt Deployments frei, auf welchem Branch?
- **Offene Arbeiten** — gibt es laufende Branches, die noch nicht gemergt sind?

Ohne diese Absprache riskiert ihr überschriebene Änderungen oder gegenseitig überraschende
Deployments.

---

## Hinweise

- Die `revolte_deploy.yaml` wird nicht verändert — sie bleibt wie sie ist
- Der SSH-Profilname ist identisch mit dem des anderen Entwicklers, zeigt aber auf deinen Key
- Die KI führt keine Befehle auf dem Server aus
- Wenn ein Schritt eine Passphrase erfordert, sagt die KI dir das — du führst ihn dann selbst aus
