<!-- markdownlint-disable MD033 -->
<div align="center">

![moneymotion Hero](hero.png)

<!--
To get rounded top corners, use this CSS in your markdown renderer:
img[alt="moneymotion Hero"] {
  width: 100%;
  border-top-left-radius: 40px;
  border-top-right-radius: 40px;
  border-bottom-left-radius: 0;
  border-bottom-right-radius: 0;
  display: block;
  margin-bottom: 24px;
}
-->
</div>
<!-- markdownlint-enable MD033 -->

<h1 align="center">Invision Community moneymotion Plugin</h1>

<p align="center">
  Accept payments with <a href="https://moneymotion.io">moneymotion</a> on your Invision Community site.<br>
  Creates checkout sessions, redirects customers to pay, and handles webhooks to complete orders.
</p>

---

## How It Works

```
Customer clicks Pay -> Plugin creates moneymotion checkout session -> Customer pays on moneymotion
                                                                              |
Plugin marks order complete <- Webhook received <- moneymotion confirms payment
```

## Two Versions Included

| | Standalone | IPS Application |
|---|---|---|
| **Requires** | Any PHP hosting | Full IPS license (non-demo) |
| **Install** | Upload files to any server | Upload .tar via ACP |
| **Database** | SQLite (auto-created) | MySQL (IPS managed) |
| **Best for** | IPS Cloud / demo / quick setup | Self-hosted IPS with Commerce |

---

## Standalone Version (Recommended for IPS Cloud)

### Requirements

- PHP 8.0+ with `curl`, `pdo_sqlite`, `openssl` extensions
- moneymotion account with API key
- IPS Community with Commerce and REST API key

### Quick Start (localhost)

**1. Install PHP** (Windows)

```bash
winget install PHP.PHP.8.3
```

**2. Configure**

Edit `standalone/config.php`:

```php
'moneymotion' => array(
    'api_key'        => 'mk_live_YOUR_KEY_HERE',
    'webhook_secret' => 'YOUR_WEBHOOK_SECRET',
),
'ips' => array(
    'base_url' => 'https://your-community.invisionservice.com/',
    'api_key'  => 'YOUR_IPS_API_KEY',
),
'app_url' => 'http://localhost:8000',
```

**3. Start the server**

```bash
php -S localhost:8000 -t standalone/
```

**4. Test a checkout**

Open in your browser:
```
http://localhost:8000/checkout.php?invoice_id=2&email=customer@example.com
```

This will:
1. Fetch the invoice from your IPS community
2. Create a moneymotion checkout session
3. Redirect you to `https://moneymotion.io/checkout/{sessionId}`

### Production Deployment

1. Upload the `standalone/` folder to any PHP hosting with HTTPS
2. Update `app_url` in `config.php` to your public HTTPS URL
3. In moneymotion dashboard, set webhook URL to:
   ```
   https://your-domain.com/standalone/webhook.php
   ```
4. Subscribe to the `checkout_session:complete` event

### File Structure

```
standalone/
├── config.php              <- Configuration (API keys, URLs)
├── checkout.php            <- Creates session + redirects to moneymotion
├── webhook.php             <- Receives payment confirmations
├── success.php             <- Success return page
├── cancel.php              <- Cancel return page
├── failure.php             <- Failure return page
├── MoneyMotionClient.php   <- moneymotion API client
├── IPSClient.php           <- IPS REST API client
├── Database.php            <- SQLite session tracking
├── cacert.pem              <- SSL certificates (for Windows)
├── check_db.php            <- Debug: view stored sessions
├── data/                   <- SQLite database (auto-created)
└── tests/                  <- Test suite
```

### Running Tests

With the PHP server running on `localhost:8000`:

```bash
# All tests
php standalone/tests/test_moneymotion_client.php
php standalone/tests/test_ips_client.php
php standalone/tests/test_webhook.php
php standalone/tests/test_full_flow.php
```

---

## IPS Application Version (for self-hosted IPS)

### Requirements

- Invision Community 4.7+ with Commerce (nexus) enabled
- Full IPS license (not demo mode)
- FTP/file access or ACP application install

### Installation

**Option A: Upload via ACP**
1. Use the pre-built `moneymotion.tar` from the project root
2. Go to ACP > System > Site Features > Applications
3. Click Install and upload `moneymotion.tar`

**Option B: Manual upload**
1. Upload the `applications/moneymotion/` folder to your IPS root's `applications/` directory
2. Go to ACP and run the application installer

### Configuration

1. Go to **ACP > Commerce > Payment Methods > Create New**
2. Select **moneymotion** as the gateway
3. Enter your **moneymotion API Key** (starts with `mk_live_` or `mk_test_`)
4. Enter your **Webhook Signing Secret** (from moneymotion dashboard)
5. Save

### Webhook Setup

In your moneymotion dashboard:
1. Create a new webhook
2. Set URL to: `https://your-community.com/moneymotion/webhook/`
3. Subscribe to: `checkout_session:complete`

### File Structure

```
applications/moneymotion/
├── Application.php                          <- Main app class
├── extensions/nexus/Gateway/MoneyMotion.php <- Payment gateway
├── modules/front/gateway/webhook.php       <- Webhook handler
├── sources/Api/Client.php                  <- moneymotion API client
├── dev/lang.php                            <- Language strings
├── dev/html/front/gateway/paymentScreen.phtml <- Payment button
├── data/schema.json                        <- Database schema
├── data/application.json                   <- App metadata
├── data/extensions.json                    <- Registers gateway
├── data/furl.json                          <- Friendly URLs
├── data/modules.json                       <- Module definitions
├── data/settings.json                      <- Settings definitions
├── data/versions.json                      <- Version history
└── setup/install.php                       <- Installation routine
```

---

## moneymotion API Reference

| | |
|---|---|
| **Create Checkout** | `POST https://api.moneymotion.io/checkoutSessions.createCheckoutSession` |
| **Auth Header** | `X-API-Key: your-api-key` |
| **Currency Header** | `x-currency: USD` |
| **Checkout URL** | `https://moneymotion.io/checkout/{sessionId}` |
| **Webhook Event** | `checkout_session:complete` |
| **Signature** | HMAC-SHA512 (base64 encoded) |

### Webhook Events Handled

| Event | Action |
|---|---|
| `checkout_session:complete` | Marks order as paid |
| `checkout_session:refunded` | Marks order as refunded |
| `checkout_session:expired` | Marks session as failed |
| `checkout_session:disputed` | Marks session as failed |

---
