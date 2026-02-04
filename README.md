# LeadCollect Abandoned Cart Plugin fuer Shopware 6

[![Shopware Version](https://img.shields.io/badge/Shopware-6.5%20%7C%206.6%20%7C%206.7-blue)](https://www.shopware.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.4.0-orange)](https://github.com/hirnworx/leadcollect-shopware-plugin/releases)

Dieses Shopware 6 Plugin ermoeglicht die **automatische Erkennung von abgebrochenen Warenkoerben** durch [LeadCollect](https://leadcollect.de). LeadCollect generiert dann personalisierte Postkarten zur Kundenrueckgewinnung.

> **Plug and Play:** Keine Cronjobs oder Scheduled Tasks noetig - LeadCollect holt sich die Daten automatisch!

---

## Features

- Automatische Erkennung - LeadCollect pollt die Shopware API fuer abgebrochene Warenkoerbe
- Automatische Gutschein-Erstellung in Shopware
- QR-Code Warenkorb-Wiederherstellung - Kunden koennen ihren Warenkorb per QR-Code wiederherstellen
- Recovery-Tracking bei Bestellungen mit Gutschein
- Vollstaendig konfigurierbar im Shopware Admin
- Deutsch und Englisch lokalisiert
- Kompatibel mit Shopware 6.5, 6.6 und 6.7

---

## Installation

### Option 1: Composer (empfohlen)

\`\`\`bash
composer require leadcollect/shopware-abandoned-cart-plugin
bin/console plugin:refresh
bin/console plugin:install --activate MailCampaignsAbandonedCart
bin/console cache:clear
\`\`\`

### Option 2: ZIP-Upload

1. Neueste Release ZIP herunterladen: https://github.com/hirnworx/leadcollect-shopware-plugin/releases
2. In Shopware Admin: Erweiterungen - Meine Erweiterungen - Plugin hochladen
3. Plugin aktivieren
4. Cache leeren

---

## Konfiguration

### 1. Plugin-Einstellungen oeffnen

Gehe zu: Einstellungen - Erweiterungen - LeadCollect - Abandoned Cart Recovery

### 2. LeadCollect Integration aktivieren

| Einstellung | Beschreibung |
|-------------|--------------|
| Webhook aktivieren | Aktivieren |
| Webhook URL | https://leadcollect.de/api/webhook/ecommerce (Standard) |
| Webhook Secret | Aus deinen LeadCollect Shop-Einstellungen kopieren |

### 3. Gutschein konfigurieren (optional)

| Einstellung | Beschreibung | Beispiel |
|-------------|--------------|----------|
| Gutschein-Typ | Prozent oder Festbetrag | Prozent |
| Gutschein-Wert | Rabatthoehe | 10 |
| Gueltigkeit (Tage) | Wie lange ist der Code gueltig? | 30 |
| Mindestbestellwert | Ab welchem Wert gilt der Gutschein? | 50 |

---

## LeadCollect Einrichtung

1. Logge dich bei LeadCollect ein: https://leadcollect.de
2. Gehe zu E-Commerce - Einstellungen
3. Klicke auf Shop verbinden
4. Waehle Shopware 6 als Plattform
5. Trage deine Shop-URL ein (z.B. https://mein-shop.de)
6. Kopiere das angezeigte Webhook Secret
7. Trage es in den Shopware Plugin-Einstellungen ein

Fertig! LeadCollect pollt jetzt automatisch deinen Shop nach abgebrochenen Warenkoerben.

---

## QR-Code Warenkorb-Wiederherstellung

Das Plugin stellt automatisch einen Endpunkt unter /leadcollect/restore bereit:

1. Produkte aus dem QR-Code-Link werden zum Warenkorb hinzugefuegt
2. Der Gutscheincode wird automatisch eingeloest
3. Der Kunde wird zur Kasse weitergeleitet

URL-Format:
https://dein-shop.de/leadcollect/restore?sku=SKU1,SKU2&q=1,2&c=GUTSCHEINCODE

| Parameter | Beschreibung |
|-----------|--------------|
| sku | Komma-getrennte Artikelnummern |
| q | Komma-getrennte Mengen |
| c | Gutscheincode |

---

## Technische Details

### API-Endpunkt fuer LeadCollect

Das Plugin stellt eine API unter /leadcollect-api/carts bereit, die von LeadCollect abgefragt wird:

GET https://dein-shop.de/leadcollect-api/carts?min_age=3600&secret=DEIN_SECRET

| Parameter | Beschreibung |
|-----------|--------------|
| min_age | Mindest-Alter des Warenkorbs in Sekunden |
| secret | Das Webhook Secret zur Authentifizierung |
| limit | Maximale Anzahl Warenkoerbe (Standard: 100) |

---

## Fehlerbehebung

### API-Endpunkt gibt 401 Unauthorized

1. Pruefe ob das Webhook Secret in LeadCollect und Shopware uebereinstimmt
2. Das Secret wird als URL-Parameter ?secret=... uebertragen

### Warenkoerbe werden nicht erkannt

1. Stelle sicher, dass der Warenkorb eine Rechnungsadresse enthaelt
2. Pruefe ob das min_age korrekt gesetzt ist (Warenkoerbe muessen alt genug sein)
3. Teste die API manuell:
   curl 'https://dein-shop.de/leadcollect-api/carts?min_age=60&secret=DEIN_SECRET'

### QR-Code fuehrt zu 404

1. Cache leeren: bin/console cache:clear
2. Die korrekte URL ist /leadcollect/restore (ohne .php)

---

## Lizenz

MIT License - basierend auf dem MailCampaigns Plugin: https://github.com/mailcampaigns/shopware-6-abandoned-cart-plugin

---

## Support

- E-Mail: support@leadcollect.de
- Issues: https://github.com/hirnworx/leadcollect-shopware-plugin/issues
- Website: https://leadcollect.de

---

## Changelog

### v1.4.0 (2026-02-04)
- NEU: Polling-basierte Warenkorb-Erkennung (kein Cronjob mehr noetig!)
- NEU: API-Endpunkt /leadcollect-api/carts fuer LeadCollect Polling
- FIX: Kompatibilitaet mit Shopware 6.5 und 6.6+ verbessert
- FIX: QR-Code URL korrigiert (/leadcollect/restore)
- FIX: Webhook URL Standard auf leadcollect.de geaendert

### v1.3.0 (2026-02-04)
- LeadCollect API Controller hinzugefuegt
- SKU-basierte Warenkorb-Wiederherstellung

### v1.0.0 (2026-02-04)
- Initial Release
- LeadCollect Webhook Integration
- Automatische Gutschein-Erstellung
- Recovery-Tracking
- Deutsch/Englisch Lokalisierung
