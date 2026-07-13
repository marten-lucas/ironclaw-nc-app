# Integrationspunkt-Recherche (Nextcloud Talk)

## Gepruefte Optionen

1. `OCA\\Talk\\Events\\ChatMessageSentEvent`
- Status: dokumentierter PHP Event in Talk Event-Doku.
- Seit: Talk 18.
- Bewertung: beste Option fuer bot-freien, serverseitigen Trigger.

2. `OCA\\Talk\\Events\\BotInvokeEvent`
- Status: dokumentiert und offiziell.
- Seit: Talk 21.
- Einschraenkung: an Bot-Lifecycle gekoppelt (Install/Enable pro Chat-Kontext).
- Bewertung: technisch gut, aber weniger passend fuer das Ziel "kein klassischer Bot-pro-Raum-Zwang".

3. DB Polling / direkte Tabellenabfrage als Trigger
- Status: intern, nicht als stabile API dokumentiert.
- Bewertung: hohes Upgrade-Risiko, nicht bevorzugt.

## Entscheidung

Primaerer Integrationspunkt: `ChatMessageSentEvent`.

## Stabilitaets-Einordnung

- Offizielle, stabile API:
  - Talk PHP Event `ChatMessageSentEvent`
- Dokumentierte Event-Schnittstellen:
  - Talk Events Doku (`events`)
  - Bot/Webhook und App-Bot Events (`BotInvokeEvent`)
- Interne Implementierungsdetails (nur kapseln, nicht als Primärpfad):
  - Tabellenstruktur von Talk
  - nicht-dokumentierte Service-Interna

## Konsequenz fuer Umsetzung

- Trigger und Mention-Gating an `ChatMessageSentEvent`.
- Interne Kopplung auf ein Listener-Modul begrenzt.
- Direkte synchrone Weiterleitung an Ironclaw (kein Outbox/Worker-Zweig).
- Ironclaw-Inbound als signierter, idempotenter Contract.
