# Baustein für die Projekt-AGENTS.md (Codex u. a.)

Codex kennt keine projektweiten Deny-Regeln wie Claude Code. Diesen Abschnitt
in die `AGENTS.md` des Projekts übernehmen — zusammen mit der Standard-Sandbox
(Netzwerk aus) ist das die Absicherung für Codex:

---

## SSH und Deployments — absolutes Tabu für die KI

Der Server dieses Projekts sperrt IPs nach **einem einzigen** fehlgeschlagenen
SSH-Login für 30+ Minuten (fail2ban, eskalierend). Deshalb gilt ausnahmslos:

- **Niemals** `ssh`, `scp`, `sftp`, `rsync`, `ssh-keygen`, `ssh-add`,
  `ssh-copy-id`, `ddev auth ssh` oder `vendor/bin/revolte-ssh-setup` ausführen —
  auch nicht "nur zum Testen" und auch nicht innerhalb von `ddev exec`.
- **Niemals** `revolte:deploy:*`- oder `revolte:legacy:*`-Commands ausführen
  (Ausnahmen: `revolte:deploy:doctor` und `revolte:deploy:explain`, die sind
  rein lokal).
- **Niemals** nach Passphrasen, Passwörtern oder Key-Inhalten fragen oder
  damit umgehen.

Stattdessen: Den fertigen Befehl als Text vorschlagen — ausgeführt wird er
ausschließlich vom Entwickler in dessen Terminal. Für die SSH-Einrichtung den
Entwickler auf `vendor/bin/revolte-ssh-setup <umgebung>` verweisen, für die
Diagnose auf `vendor/bin/revolte-ssh-setup <umgebung> --check`.
