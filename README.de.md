# Paymos für OpenCart

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielle Paymos-Zahlungserweiterung für OpenCart 4.0.2.0 und neuer. Die Kundschaft bestätigt
die Bestellung, gelangt zur gehosteten Paymos-Zahlungsseite und bezahlt in dem Token und Netzwerk,
die Ihr Projekt aktiviert hat — Tron, Ethereum, BSC, Solana und die übrigen unterstützten Netze.
Ihre OpenCart-Bestellung schreitet danach selbstständig voran, gesteuert durch einen signierten
Callback statt durch die Rückkehr des Browsers. Die Rechnung lautet auf die Währung der Bestellung,
und der Kurs bleibt in dem Moment fixiert, in dem bezahlt wird.

Das Wichtigste vorweg: Sie entscheiden, welchen *Ihrer* Bestellstatus jeder Zahlungszustand
schreibt. Die Erweiterung bringt Standardwerte mit, aber alle fünf Zuordnungen sind Dropdowns aus
Ihrer eigenen Statusliste — Paymos passt sich also Ihrem bestehenden Shop-Ablauf an, statt einen
aufzuzwingen.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- OpenCart 4.0.2.0 oder neuer; auf 4.0.0.0–4.0.1.1 lässt sich die Erweiterung nicht installieren;
- PHP 8.0 oder neuer mit den Erweiterungen `curl`, `hash`, `json` und `openssl`;
- eine öffentlich über das Internet erreichbare Store-URL per HTTPS;
- ein Paymos-Konto, in dessen Dashboard das Projekt für diesen Shop bereits geöffnet ist.

Das Paymos PHP SDK ist im Paket enthalten — es muss nichts über Composer installiert werden. Texte
für Administration und Storefront sind auf Englisch, Russisch, Deutsch, Spanisch, Türkisch und
vereinfachtem Chinesisch enthalten.

## Installieren und verbinden

1. Laden Sie das neueste Paket unter [GitHub Releases](https://github.com/paymos-labs/opencart/releases/latest) herunter.
2. Laden Sie `paymos.ocmod.zip` unter **Extensions → Installer** hoch.
3. Öffnen Sie **Extensions → Extensions**, wählen Sie **Payments** und installieren Sie Paymos.
4. Bearbeiten Sie die Erweiterung und öffnen Sie im Paymos Dashboard das gewünschte Projekt; dieses aktuelle Projekt wird automatisch übernommen.
5. Klicken Sie in den Erweiterungseinstellungen auf **Connect Paymos**.
6. Bestätigen Sie in Paymos die angezeigte Shop-URL und das aktuelle Projekt.

**Benennen Sie das Archiv nicht um.** OpenCart leitet das Erweiterungsverzeichnis vom hochgeladenen
Dateinamen ab, und jeder Controller-Namespace im Paket baut auf diesem Verzeichnis auf. Ein
umbenanntes ZIP landet in einem Ordner, den intern nichts adressieren kann, sodass die Erweiterung
stillschweigend nicht geladen wird.

Das Release ist für alle Händler dieselbe Datei und enthält keinerlei Zugangsdaten: keinen
API-Schlüssel oder Secret, keine Projekt-ID, kein Webhook-Geheimnis, kein OAuth-Token und keinen
Gerätecode. Vor Abschluss von Schritt 6 ist nichts aktiv.

Die Bestätigung richtet Sandbox und Live gemeinsam ein. Pro Umgebung nutzt Paymos Ihren einen
aktiven Payment-Schlüssel weiter — oder legt einen an, falls keiner existiert — und richtet einen
Invoice-Webhook auf diesen Shop ein: Ein vorhandener Webhook wird nur dann wiederverwendet, wenn
URL, Kategorie und Projekt exakt übereinstimmen; ein anderer Webhook unter derselben Adresse bleibt
unverändert bestehen. Die Geräteautorisierung ist lediglich ein einmaliger Übertragungsweg; ihr
Token läuft rasch ab und wird verworfen, und alle laufenden Merchant-API-Aufrufe sind HMAC-signiert.

## Ablage der Geheimnisse

Zugangsdaten und vorübergehender Gerätezustand werden als mit AES-256-GCM verschlüsselte
OpenCart-Einstellung gespeichert, verschlüsselt über `config_encryption`. Gespeicherte Geheimnisse
werden nicht zurück in die Admin-Seite ausgegeben und lassen sich dort nirgends eingeben; beim
Speichern des Formulars bleibt der versiegelte Umschlag unberührt. Verbinden Sie nach einer
Schlüssel- oder Webhook-Secret-Rotation einfach neu.

## Einstellungen

| Einstellung | Funktion |
|---|---|
| Status | Ob die Zahlungsart im Checkout angeboten wird |
| Mode | Sandbox oder Live |
| Credentials | Schreibgeschützt — ob dieser Shop verbunden ist |
| Webhook URL | Schreibgeschützt — automatisch registriert, zum Testen angezeigt |
| Title | Name der Zahlungsart im Checkout |
| Button text | Beschriftung der Schaltfläche zur gehosteten Zahlungsseite |
| Pending / Confirming / Paid / Failed / Cancelled status | Ihr Bestellstatus für jeden Zahlungszustand |
| Sort order | Position unter den anderen Zahlungsarten |

Registrierte Webhook-Adresse für OpenCart:

```text
https://your-store.example/index.php?route=extension/paymos/payment/paymos.callback
```

## Bestellstatus

Die Bestellung wird von OpenCart wie gewohnt angelegt, in Ihren **Pending status** mit dem
Verlaufseintrag *Awaiting Paymos payment* gesetzt und die Kundschaft zur gehosteten Zahlungsseite
geleitet. Ab dort übernimmt der Callback.

| Zahlungszustand | Verwendeter Status | Standard einer frischen Installation |
|---|---|---|
| Transfer erkannt, Bestätigungen laufen | Confirming status | Processing |
| Teilbetrag eingegangen oder gezählter Transfer durch Reorg zurückgenommen | Pending status | Pending |
| Rechnung bezahlt oder überzahlt | Paid status | Complete |
| Nach Fristablauf unterbezahlt | Failed status | Failed |
| Abgelaufen oder storniert | Cancelled status | Canceled |

Jeder Übergang schreibt eine Verlaufszeile mit der Paymos-Rechnung, und der Bezahlt-Eintrag führt
bei On-Chain-Abwicklung den Transaktions-Hash samt Explorer-Link auf.

Zwei Schutzregeln überschreiben diese Tabelle. Sobald eine Bestellung Ihren Paid status erreicht
hat, kann kein verspäteter oder unsortierter Callback sie zurücksetzen — der veraltete Status wird
als Verlaufsnotiz protokolliert und ignoriert. Und ein Bezahlt-Callback, dessen Betrag nicht mehr
zur Bestellung passt, wechselt mit der Notiz *needs manual review* in den Confirming status statt
abzuschließen, da eine nachträglich geänderte Bestellsumme nicht automatisch von Software verbucht
werden sollte.

## In der Sandbox testen

1. Lassen Sie **Mode** auf Sandbox, aktivieren Sie die Zahlungsart und geben Sie eine Bestellung über Ihre Storefront auf.
2. Suchen Sie diese Rechnung im Paymos Dashboard und lösen Sie das gewünschte Ergebnis aus — bezahlt, überzahlt, unterbezahlt oder storniert. In der Sandbox bestätigt sich nichts per Timer.
3. Prüfen Sie den Verlaufs-Tab der Bestellung: Jedes Ereignis sollte der Reihe nach mit dem von Ihnen zugeordneten Status erscheinen.
4. Stellen Sie **Mode** auf Live um. Beide Umgebungen entstanden durch dieselbe Verbindung, es ist keine weitere Einrichtung nötig.

Sandbox-Zahlungen haben keine Blockchain im Hintergrund, daher erscheinen in der Bezahlt-Zeile
weder Transaktions-Hash noch Explorer-Link. Das ist normal.

## Callbacks und Abgleich

Jede Zustellung trägt `X-Webhook-Signature` im Format `t={timestamp},v1={hmac_hex}` — HMAC-SHA256
über die exakt gesendeten Bytes, in konstanter Zeit verglichen und während einer Rotation mit
beiden Geheimnissen gültig. Ereignis-IDs werden gespeichert, sodass eine Wiederholung quittiert,
aber nicht erneut auf die Bestellung angewendet wird.

Einem endgültigen Callback wird nie allein aufgrund seiner Signatur vertraut: Vor dem
Statuswechsel wird die Rechnung erneut aus der Merchant API abgerufen und mit dem gespeicherten
Snapshot verglichen.

Der Rechnungsabgleich ist hier eine Schaltfläche, kein Cronjob. **Reconcile Paymos orders** oben
auf der Einstellungsseite lädt bis zu 50 offene Rechnungen der letzten 24 Stunden aus der API,
prüft sie über dieselbe Zuordnung und Schutzlogik, gleicht ausstehende Erstattungen ab und meldet,
wie viele Datensätze aktualisiert wurden. Nutzen Sie die Funktion nach einem Ausfall oder immer
dann, wenn eine Bestellung festzustecken scheint.

## Erstattungen

Die Erweiterung unterstützt native Erstattungen über die Paymos Merchant API (`/v1/refunds`) in
zwei serverseitig berechneten Modi: `full` (vollständige Rechnungserstattung) und `overpayment`
(Erstattung des Überschusses einer `paid_over`-Rechnung). Die Mittel gehen als neue ausgehende
Blockchain-Transaktion strikt an die verifizierte `first_sender`-Adresse zurück, die Paymos auf
der bezahlten Rechnung gespeichert hat — eine Empfänger-Wallet wird nicht manuell eingegeben, und
beliebige Teilbeträge werden nicht unterstützt.

So starten oder prüfen Sie eine Erstattung in OpenCart:

1. Öffnen Sie im OpenCart-Admin **Extensions → Extensions → Payments → Paymos**.
2. Geben Sie im Panel **Paymos refunds** (`refundPanel`) die OpenCart-Bestell-ID ein, um erstattungsfähige Beträge, die verifizierte `first_sender`-Adresse und bestehende Vorgänge zu laden.
3. Wählen Sie entweder die Aktion `full` oder `overpayment` (`extension/paymos/payment/paymos.refund`).

Beim Installieren oder Öffnen der Erweiterung wird ein einminütiger OpenCart-Cronjob registriert
(`paymos_refunds` → `extension/paymos/payment/paymos.refundCron`); zusätzlich treiben sowohl
signierte Callbacks als auch **Reconcile Paymos orders** ausstehende Erstattungen voran.
Abgeschlossene Erstattungen werden nach Prüfung gegen die Merchant API in der
OpenCart-Bestellhistorie dokumentiert, und im Sandbox-Modus lassen sich Endzustände (`completed`,
`failed`, `cancelled`) simulieren.

## Fehlerbehebung

**Paymos bleibt nicht installiert.** Der Shop läuft auf einer OpenCart-Version vor 4.0.2.0. Die
Erweiterung entfernt sich selbst, legt keine Tabellen an und schreibt eine Zeile `[Paymos]
Installation refused` mit der erkannten Version ins OpenCart-Fehlerprotokoll. Aktualisieren Sie
OpenCart und installieren Sie Paymos danach erneut.

**Connect meldet, dass der Shop über HTTPS erreichbar sein muss.** Bei einer frischen
OpenCart-Installation kann die Store-URL leer sein; dann wird stattdessen die Katalog-URL
herangezogen. Ist diese noch auf HTTP eingestellt, wird die Verbindung abgelehnt statt halbfertig
abgeschlossen. Die Meldung nennt die gefundene URL — tragen Sie deren HTTPS-Form in Ihrer
OpenCart-Datei `config.php` ein und versuchen Sie es erneut.

**Die Bestellung bleibt dauerhaft auf Pending.** Rufen Sie die obige Callback-URL von außerhalb
Ihres Netzwerks ab. Sie muss ohne HTTP-Authentifizierung und ohne Weiterleitung antworten —
Weiterleitungen werden nicht verfolgt. Prüfen Sie anschließend das OpenCart-Fehlerprotokoll auf
Zeilen mit `[Paymos]`.

**Der Checkout meldet "Paymos is temporarily unavailable".** Die Rechnung konnte nicht angelegt
werden. Der Protokolleintrag nennt den Grund; häufige Ursachen sind eine nicht vollständig
verbundene Umgebung oder eine Bestellwährung, in der das Projekt keine Rechnungen ausstellt.

**Eine Bestellung benötigt manuelle Prüfung.** Ihre Gesamtsumme hat sich zwischen
Rechnungserstellung und Zahlungseingang geändert. Die Verlaufsnotiz nennt beide Beträge — klären
Sie den Fall manuell.

- [Dokumentation](https://paymos.io/de/docs/cms-opencart)
- [Quellcode](https://github.com/paymos-labs/opencart)
- [Änderungsprotokoll](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
