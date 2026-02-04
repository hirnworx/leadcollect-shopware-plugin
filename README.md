# 🛒 LeadCollect Abandoned Cart Plugin für Shopware 6

[![Shopware Version](https://img.shields.io/badge/Shopware-6.5%20%7C%206.6%20%7C%206.7-blue)](https://www.shopware.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Dieses Shopware 6 Plugin erkennt automatisch abgebrochene Warenkörbe und sendet die Daten an [LeadCollect](https://leadcollect.de), wo personalisierte Postkarten zur Kundenrückgewinnung generiert und versendet werden.

> **Basiert auf:** [MailCampaigns Abandoned Cart Plugin](https://github.com/mailcampaigns/shopware-6-abandoned-cart-plugin)

---

## 🎯 Features

- 🔍 **Automatische Erkennung** von abgebrochenen Warenkörben
- 📬 **Webhook an LeadCollect** mit Kundendaten + Produkten
- 🎁 **Automatische Gutschein-Erstellung** in Shopware
- 📊 **Recovery-Tracking** bei Bestellungen mit Gutschein
- ⚙️ **Vollständig konfigurierbar** im Shopware Admin
- 🇩🇪 **Deutsch & Englisch** lokalisiert

---

## 📦 Installation

### Option 1: Composer (empfohlen)

```bash
composer require leadcollect/shopware-abandoned-cart-plugin
bin/console plugin:refresh
bin/console plugin:install --activate MailCampaignsAbandonedCart
bin/console cache:clear
```

### Option 2: ZIP-Upload

1. [Neueste Release ZIP herunterladen](https://github.com/hirnworx/leadcollect-shopware-plugin/releases)
2. In Shopware Admin: **Erweiterungen → Meine Erweiterungen → Plugin hochladen**
3. Plugin aktivieren
4. Cache leeren

### Option 3: Git Clone

```bash
cd custom/plugins
git clone https://github.com/hirnworx/leadcollect-shopware-plugin.git MailCampaignsAbandonedCart
bin/console plugin:refresh
bin/console plugin:install --activate MailCampaignsAbandonedCart
bin/console cache:clear
```

---

## ⚙️ Konfiguration

### 1. Plugin-Einstellungen öffnen

Gehe zu: **Einstellungen → Erweiterungen → LeadCollect - Abandoned Cart Recovery**

### 2. Warenkorbabbruch konfigurieren

| Einstellung | Beschreibung | Empfehlung |
|-------------|--------------|------------|
| **Nach wie vielen Sekunden?** | Zeit bis ein Warenkorb als "abgebrochen" gilt | 3600 (1 Stunde) |

> ⚠️ **Wichtig:** Diese Zeit muss **kürzer** sein als die Shopware-Einstellung "Zeit in Minuten für Kaufabschluss"

### 3. LeadCollect Integration aktivieren

| Einstellung | Beschreibung |
|-------------|--------------|
| **Webhook aktivieren** | ✅ Aktivieren |
| **Webhook URL** | `https://api.leadcollect.de/api/webhook/ecommerce` |
| **Webhook Secret** | Aus deinen LeadCollect Shop-Einstellungen kopieren |

### 4. Gutschein konfigurieren (optional)

| Einstellung | Beschreibung | Beispiel |
|-------------|--------------|----------|
| **Gutschein-Typ** | Prozent oder Festbetrag | Prozent |
| **Gutschein-Wert** | Rabatthöhe | 10 |
| **Gültigkeit (Tage)** | Wie lange ist der Code gültig? | 30 |
| **Mindestbestellwert** | Ab welchem Wert gilt der Gutschein? | 50 |

---

## 🔗 LeadCollect Einrichtung

1. Logge dich bei [LeadCollect](https://leadcollect.de) ein
2. Gehe zu **E-Commerce → Einstellungen**
3. Klicke auf **Shop verbinden**
4. Wähle **Shopware 6** als Plattform
5. Kopiere das angezeigte **Webhook Secret**
6. Trage es in den Plugin-Einstellungen ein

---

## 📊 So funktioniert es

```
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│   SHOPWARE 6                                LEADCOLLECT                 │
│                                                                         │
│   1. Kunde legt Produkte                                                │
│      in den Warenkorb                                                   │
│            │                                                            │
│            ▼                                                            │
│   2. Kunde verlässt Shop                                                │
│      (ohne zu kaufen)                                                   │
│            │                                                            │
│            ▼                                                            │
│   3. Plugin erkennt Abbruch  ─────────────► 4. Warenkorb wird           │
│      (nach 1 Stunde)                           gespeichert              │
│                                                     │                   │
│                                                     ▼                   │
│                                             5. Postkarte wird           │
│                                                generiert                │
│                                                     │                   │
│                                                     ▼                   │
│                                             6. Postkarte wird           │
│                                                gedruckt                 │
│                                                     │                   │
│                                                     ▼                   │
│   7. Kunde erhält Postkarte ◄────────────── 7. Postkarte wird           │
│      mit Gutscheincode                         versendet                │
│            │                                                            │
│            ▼                                                            │
│   8. Kunde bestellt mit                                                 │
│      Gutscheincode                                                      │
│            │                                                            │
│            ▼                                                            │
│   9. Recovery-Webhook ────────────────────► 10. Erfolg wird             │
│                                                 getrackt                │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 🔧 Technische Details

### Webhook Payload: `cart_abandoned`

```json
{
  "eventType": "cart_abandoned",
  "externalCartId": "abc123...",
  "externalCustomerId": "def456...",
  "abandonedAt": "2026-02-04T10:30:00+00:00",
  "customer": {
    "salutation": "Herr",
    "firstName": "Max",
    "lastName": "Mustermann",
    "email": "max@example.de",
    "phone": "+49 123 456789",
    "address": {
      "street": "Musterstraße 123",
      "zipcode": "12345",
      "city": "Musterstadt",
      "country": "DE"
    }
  },
  "cart": {
    "totalPrice": 249.99,
    "currency": "EUR",
    "lineItems": [
      {
        "name": "Produkt XYZ",
        "sku": "SKU-12345",
        "price": 99.99,
        "quantity": 2,
        "imageUrl": "https://shop.de/media/product.jpg"
      }
    ]
  }
}
```

### Webhook Payload: `order_placed`

```json
{
  "eventType": "order_placed",
  "orderId": "xyz789...",
  "orderValue": 224.99,
  "couponCode": "COMEBACK-ABC123",
  "customerId": "def456...",
  "customerEmail": "max@example.de"
}
```

---

## ⏰ Cron-Job einrichten (WICHTIG!)

Das Plugin benötigt einen Cron-Job, damit abgebrochene Warenkörbe automatisch erkannt werden:

```bash
# Crontab bearbeiten
crontab -e

# Diese Zeile hinzufügen (jede Minute):
* * * * * cd /pfad/zu/shopware && php bin/console scheduled-task:run --time-limit=50 > /dev/null 2>&1
```

> ⚠️ **Ohne Cron-Job werden keine abgebrochenen Warenkörbe erkannt!**

---

## 📱 QR-Code Warenkorb-Wiederherstellung

Das Plugin installiert automatisch eine Seite unter `/leadcollect/restore.php`, die:

1. Produkte aus dem QR-Code-Link zum Warenkorb hinzufügt
2. Den Gutscheincode automatisch einlöst
3. Den Kunden zur Kasse weiterleitet

**URL-Format:**
```
https://dein-shop.de/leadcollect/restore.php?sku=SKU1,SKU2&q=1,2&c=GUTSCHEINCODE
```

| Parameter | Beschreibung |
|-----------|--------------|
| `sku` | Komma-getrennte Artikelnummern |
| `q` | Komma-getrennte Mengen |
| `c` | Gutscheincode |

---

## 🛠️ Console Commands

```bash
# Warenkörbe manuell als abgebrochen markieren
bin/console mailcampaigns:mark-abandoned-cart

# Scheduler neu starten
bin/console mailcampaigns:relaunch-scheduler

# Abgebrochene Warenkörbe aktualisieren
bin/console mailcampaigns:update-abandoned-cart
```

---

## 🐛 Fehlerbehebung

### Webhooks werden nicht gesendet

1. Prüfe ob **LeadCollect Webhook aktivieren** eingeschaltet ist
2. Prüfe die Webhook URL und das Secret
3. Prüfe die Shopware Logs:
   ```bash
   tail -f var/log/prod-*.log | grep LeadCollect
   ```

### Warenkörbe werden nicht erkannt

1. Stelle sicher, dass die **Message Queue** läuft:
   ```bash
   bin/console messenger:consume async
   ```
2. Prüfe die Scheduled Tasks:
   ```bash
   bin/console scheduled-task:list
   ```

### Gutscheine funktionieren nicht

1. Prüfe unter **Marketing → Aktionen** ob die LeadCollect Promotion existiert
2. Die Promotion muss **aktiv** und **Codes verwenden** aktiviert haben

---

## 📄 Lizenz

MIT License - basierend auf dem [MailCampaigns Plugin](https://github.com/mailcampaigns/shopware-6-abandoned-cart-plugin)

---

## 🤝 Support

- **E-Mail:** support@leadcollect.de
- **Issues:** [GitHub Issues](https://github.com/hirnworx/leadcollect-shopware-plugin/issues)
- **Website:** [leadcollect.de](https://leadcollect.de)

---

## 📈 Changelog

### v1.0.0 (2026-02-04)
- Initial Release
- LeadCollect Webhook Integration
- Automatische Gutschein-Erstellung
- Recovery-Tracking
- Deutsch/Englisch Lokalisierung
