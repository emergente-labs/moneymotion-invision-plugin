# moneymotion IPS Payment Gateway — Complete Technical Reference

## 1. How the Plugin Gets Registered

There are **3 registration mechanisms** that make the plugin visible to IPS:

### A. Application Registration

```
applications/moneymotion/data/application.json
  → Tells IPS: "I'm an app called moneymotion, version 3.0.17"

applications/moneymotion/Application.php
  → extends \IPS\Application (the base class at ips_4.7.20/applications/core/)
  → Registers icon: 'credit-card'
  → No front navigation (payment gateway has no menu items)
```

### B. Nexus Gateway Extension

```
applications/moneymotion/data/extensions.json:
  { "nexus": { "Gateway": ["moneymotion"] } }

  → Tells IPS: "I provide a nexus Gateway extension called moneymotion"
  → Maps to: applications/moneymotion/extensions/nexus/Gateway/moneymotion.php
  → This class extends \IPS\nexus\Gateway (from Commerce add-on)
```

### C. Class Hook on Gateway

```
applications/moneymotion/data/hooks.json:
  { "Gateway": { "type": "C", "class": "\\IPS\\nexus\\Gateway" } }
```

```php
// applications/moneymotion/hooks/Gateway.php
class moneymotion_hook_Gateway extends _HOOK_CLASS_
{
    public static function gateways()
    {
        $gateways = parent::gateways();
        $gateways['moneymotion'] = 'IPS\moneymotion\extensions\nexus\Gateway\moneymotion';
        return $gateways;
    }
}
```

### How IPS Hooks Work

- `_HOOK_CLASS_` is a magic placeholder — IPS's autoloader replaces it with the original class at runtime
- `type: "C"` = Code hook (vs `"S"` = Skin/template hook)
- The hook class extends the original via dynamic class substitution
- Hook is stored in `core_hooks` DB table with columns: `id`, `plugin`, `app`, `type`, `class`, `filename`
- When IPS loads `\IPS\nexus\Gateway`, it actually loads the hooked version that includes `moneymotion` in the `gateways()` return

If the hook is NOT active (disabled hooks, cache issue), `gateways()` won't include `moneymotion` — this is why `webhook.php:findGateway()` has a fallback with `stdClass`.

---

## 2. Complete Payment Flow

```
STEP 1: Customer clicks "Pay with moneymotion"
├── IPS renders paymentScreen.phtml template
│   └── Shows: "You will be redirected to moneymotion" + amount + submit button
├── Form submits to IPS checkout controller
└── IPS calls: $gateway->auth($transaction, $values, ...)

STEP 2: auth() in Gateway/moneymotion.php (line 118)
├── Loads invoice from $transaction->invoice
├── Reads gateway settings: json_decode($this->settings) → {api_key, webhook_secret}
├── Ensures transaction has DB ID: $transaction->save()
├── Calculates amount:
│   ├── Uses $transaction->amount (what customer saw)
│   ├── Recalculates invoice via $invoice->recalculateTotal()
│   ├── Compares amounts, logs mismatches but KEEPS transaction amount
│   └── Converts to cents: (int) round((float)(string) $amount->amount * 100)
├── Builds single line item:
│   └── {name: "Invoice #X", description: "...", pricePerItemInCents: totalCents, quantity: 1}
├── Generates CSRF tokens (HMAC-SHA256):
│   └── hash_hmac('sha256', "$txnId:$action:$memberId:$cookieKey", $cookieKey)
├── Builds callback URLs (forced HTTPS):
│   ├── success → /moneymotion/webhook/success?t={id}&csrf_token={token}
│   ├── cancel  → /moneymotion/webhook/cancel?t={id}&csrf_token={token}
│   └── failure → /moneymotion/webhook/failure?t={id}&csrf_token={token}
├── Calls API: $client->createCheckoutSession(...)
│   └── POST https://api.moneymotion.io/checkoutSessions.createCheckoutSession
│       Headers: x-api-key, x-currency, Content-Type: application/json
│       Body: {json: {description, urls, userInfo: {email}, lineItems, metadata}}
│       Returns: {result: {data: {json: {checkoutSessionId: "..."}}}}
├── Stores in DB:
│   └── INSERT INTO moneymotion_sessions (session_id, transaction_id, invoice_id,
│       amount_cents, currency, status='pending', created_at, updated_at)
├── Saves to IPS: $transaction->gw_id = $sessionId
└── REDIRECTS to: https://moneymotion.io/checkout/{sessionId}

STEP 3: Customer pays on moneymotion's hosted checkout page
└── moneymotion sends webhook to IPS site

STEP 4: Webhook arrives at webhook.php::manage() → webhook()
├── Reads raw POST body: file_get_contents('php://input')
├── Validates payload has 'event' key
├── Replay protection:
│   ├── Reads payload.timestamp
│   ├── Converts from ms to seconds if > 2000000000
│   └── Rejects if |current_time - timestamp| > 300 seconds (5 min window)
├── Finds gateway: findGateway()
│   ├── SELECT * FROM nexus_paymethods WHERE m_gateway='moneymotion'
│   ├── Tries Gateway::constructFromData() (needs hook active)
│   └── Falls back to stdClass with raw m_settings (when hook inactive)
├── Reads webhook_secret from gateway settings
├── Signature verification:
│   ├── Checks HTTP_X_WEBHOOK_SIGNATURE or HTTP_X_SIGNATURE header
│   ├── Computes: base64_encode(hash_hmac('sha512', $rawBody, $secret, TRUE))
│   └── Compares with hash_equals() (timing-safe)
├── Routes event:
│   ├── checkout_session:complete  → handleCheckoutComplete()
│   ├── checkout_session:refunded  → handleCheckoutRefunded()
│   ├── checkout_session:expired   → handleCheckoutFailed()
│   └── checkout_session:disputed  → handleCheckoutFailed()
└── Returns 200 OK

STEP 5: handleCheckoutComplete() (line 153)
├── Extracts checkoutSession.id from payload
├── Looks up: SELECT * FROM moneymotion_sessions WHERE session_id=?
├── Idempotency check: skips if status='complete'
├── Loads IPS transaction: \IPS\nexus\Transaction::load($session['transaction_id'])
├── AMOUNT VALIDATION:
│   ├── extractPaidAmountCents(): checks keys amountInCents, amount_cents,
│   │   amountCents, totalAmountInCents, total_amount_cents — OR sums lineItems
│   ├── extractPaidCurrency(): checks keys currency, currencyCode, currency_code
│   ├── Compares paid vs expected (from moneymotion_sessions)
│   └── BLOCKS if mismatch → status='failed'
├── $transaction->approve()  ← IPS handles invoice status, purchase generation, etc.
├── UPDATE moneymotion_sessions SET status='complete'
└── Logs success

STEP 6: Customer returns to success/cancel/failure URL
├── Validates CSRF token (regenerated, compared with hash_equals)
├── success → Redirects to invoice URL with success message
├── cancel  → Marks transaction STATUS_REFUSED, session 'cancelled', redirects to checkout
└── failure → Marks transaction STATUS_REFUSED, session 'failed', redirects to checkout
```

---

## 3. Database Schema

### Plugin's Own Table: `moneymotion_sessions`

```sql
CREATE TABLE moneymotion_sessions (
  session_id     VARCHAR(255) PRIMARY KEY,   -- moneymotion checkout session ID
  transaction_id BIGINT UNSIGNED NOT NULL,   -- IPS nexus_transactions.t_id
  invoice_id     BIGINT UNSIGNED NOT NULL,   -- IPS nexus_invoices.i_id
  amount_cents   INT(11) NOT NULL DEFAULT 0, -- Expected payment in cents
  currency       VARCHAR(3) NOT NULL DEFAULT 'EUR',
  status         VARCHAR(32) NOT NULL DEFAULT 'pending',
  created_at     INT(10) NOT NULL DEFAULT 0, -- Unix timestamp
  updated_at     INT(10) NOT NULL DEFAULT 0, -- Unix timestamp
  INDEX (transaction_id),
  INDEX (invoice_id),
  INDEX (status)
);
```

Status values: `pending`, `complete`, `failed`, `cancelled`, `refunded`

### IPS Tables the Plugin Reads/Writes

| Table | Operation | Where |
|-------|-----------|-------|
| `nexus_paymethods` | SELECT (find gateway) | `m_gateway='moneymotion'` |
| `nexus_transactions` | LOAD/SAVE (via `\IPS\nexus\Transaction`) | `auth()`, webhook handlers |
| `nexus_invoices` | LOAD (via `$transaction->invoice`) | `auth()`, return URLs |

### Nexus `nexus_paymethods` Columns

```
m_id         - Auto-increment ID
m_gateway    - Gateway type string (e.g., 'moneymotion', 'Stripe', 'PayPal')
m_settings   - JSON blob with gateway config (api_key, webhook_secret)
m_active     - Active flag (0/1)
m_position   - Sort order
m_countries  - Country restrictions ('*' = all)
```

### Nexus `nexus_transactions` Columns

```
t_id, t_member, t_invoice, t_method, t_status, t_amount, t_date,
t_extra, t_fraud, t_gw_id (stores moneymotion session ID),
t_ip, t_fraud_blocked, t_currency, t_partial_refund, t_credit,
t_auth, t_billing_agreement
```

### Nexus `nexus_invoices` Columns

```
i_id, i_status, i_title, i_member, i_items (JSON), i_total, i_date,
i_return_uri, i_paid, i_status_extra, i_discount, i_renewal_ids,
i_po, i_notes, i_shipaddress, i_billaddress, i_currency,
i_guest_data, i_billcountry
```

---

## 4. IPS Hook System Details

The `core_hooks` DB table stores:

```
id       - BIGINT auto-increment
plugin   - Plugin ID (NULL for app hooks)
app      - App key ('moneymotion')
type     - 'C' (code) or 'S' (skin)
class    - Target class ('\\IPS\\nexus\\Gateway')
filename - PHP file in hooks/ ('Gateway')
```

### Hook Loading Chain

1. IPS autoloader loads a class (e.g., `\IPS\nexus\Gateway`)
2. Checks `core_hooks` for any hooks targeting that class
3. For each active hook, dynamically creates a class chain:
   - Original class -> `_HOOK_CLASS_` placeholder -> hook class
4. The hook class extends the dynamically substituted parent
5. Can override any method via `parent::method()` chaining

The moneymotion hook overrides `Gateway::gateways()` to add `'moneymotion'` to the available gateways list. Without this, IPS checkout wouldn't show moneymotion as a payment option.

---

## 5. Security Chain

### Webhook Security (3 layers)

```
Layer 1: Timestamp validation (replay protection)
  └── Rejects if > 5 minutes old

Layer 2: HMAC-SHA512 signature verification
  └── base64(hmac_sha512(body, secret))
  └── Timing-safe comparison (hash_equals)

Layer 3: Amount/currency validation
  └── Paid amount must match stored amount
  └── Prevents underpayment attacks
```

### Return URL Security (CSRF)

```
HMAC-SHA256 CSRF token per action
  └── Data: txnId:action:memberId:cookieKey
  └── Key: cookie_login_key from IPS settings
  └── Prevents forged return URL visits
```

---

## 6. Edge Cases and Fallbacks

| Scenario | How it's handled |
|----------|-----------------|
| Hook not active (cache issue) | `findGateway()` falls back to `stdClass` with raw settings |
| Duplicate webhook (idempotent) | Skips if `status='complete'` already |
| Amount mismatch | Blocks approval, marks session `'failed'` |
| Missing paid amount in webhook | Blocks approval |
| Transaction not in DB yet | `$transaction->save()` called early in `auth()` |
| API failure | Throws `\LogicException` -> user sees `moneymotion_error_api` |
| Timestamp in milliseconds | Auto-converts if `> 2000000000` |

---

## 7. File Map

### Plugin Files (19 files)

| Path | Purpose |
|------|---------|
| `Application.php` | App registration with IPS framework |
| `extensions/nexus/Gateway/moneymotion.php` | Core gateway class (auth, capture, void, settings) |
| `hooks/Gateway.php` | Class hook injecting into `Gateway::gateways()` |
| `modules/front/gateway/webhook.php` | Webhook controller + return URL handlers |
| `sources/Api/Client.php` | API client for `api.moneymotion.io` |
| `setup/install.php` | Creates `moneymotion_sessions` table |
| `setup/upg_30013/upgrade.php` | Ensures lang keys exist on upgrade |
| `dev/lang.php` | Language strings (EN) |
| `dev/html/front/gateway/paymentScreen.phtml` | Payment button template |
| `data/application.json` | App metadata (v3.0.17) |
| `data/versions.json` | Version history (1.0.0 - 3.0.16) |
| `data/settings.json` | Settings: api_key, webhook_secret |
| `data/schema.json` | DB schema for moneymotion_sessions |
| `data/modules.json` | Front module: gateway -> webhook controller |
| `data/extensions.json` | Nexus Gateway extension registration |
| `data/hooks.json` | Hook on `\IPS\nexus\Gateway` |
| `data/furl.json` | Friendly URLs for webhook endpoints |
| `data/lang.xml` | Compiled language data |
| `data/theme.xml` | Compiled theme data |

### IPS 4.7.20 Structure

```
ips_4.7.20/
├── admin/              # ACP: install, upgrade, UTF8 converter
├── api/                # REST API + GraphQL
├── applications/
│   ├── core/           # 21M - framework (847+ PHP files)
│   ├── blog/           # 3.6M
│   ├── calendar/       # 4.0M
│   ├── cms/            # 5.6M
│   ├── convert/        # 4.7M
│   ├── nexus/          # REQUIRED (Commerce add-on, not included)
│   └── moneymotion/    # PLUGIN GOES HERE
└── 404error.php
```

### Key IPS Framework Classes Used by Plugin

| Class | Purpose |
|-------|---------|
| `\IPS\Application` | Base app class |
| `\IPS\nexus\Gateway` | Payment gateway base (hooked) |
| `\IPS\nexus\Transaction` | Transaction model (load, save, approve) |
| `\IPS\nexus\Invoice` | Invoice model (summary, amountToPay) |
| `\IPS\nexus\Money` | Money value object |
| `\IPS\Db` | Database abstraction (select, insert, update, delete) |
| `\IPS\Http\Url` | URL builder (internal, external, friendly) |
| `\IPS\Output` | Response output + redirects |
| `\IPS\Theme` | Template engine |
| `\IPS\Member` | User/customer model |
| `\IPS\Settings` | Global settings singleton |
| `\IPS\Request` | HTTP request handler |
| `\IPS\Log` | Logging system |
| `\IPS\Lang` | Language string management |
| `\IPS\Dispatcher\Controller` | Base controller class |

### API Communication

```
Endpoint: POST https://api.moneymotion.io/checkoutSessions.createCheckoutSession
Headers:
  Content-Type: application/json
  x-api-key: {api_key from settings}
  x-currency: {currency code, e.g. BRL}
  User-Agent: moneymotion IPS Plugin/3.0.16 (PHP X.Y.Z)

Request Body:
{
  "json": {
    "description": "Invoice #123",
    "urls": {
      "success": "https://site.com/moneymotion/webhook/success?t=456&csrf_token=abc",
      "cancel":  "https://site.com/moneymotion/webhook/cancel?t=456&csrf_token=def",
      "failure": "https://site.com/moneymotion/webhook/failure?t=456&csrf_token=ghi"
    },
    "userInfo": { "email": "customer@example.com" },
    "lineItems": [
      {
        "name": "Invoice #123",
        "description": "Payment for Invoice #123",
        "pricePerItemInCents": 5000,
        "quantity": 1
      }
    ],
    "metadata": {
      "invoice_id": 123,
      "transaction_id": 456,
      "gateway_id": 1
    }
  }
}

Response:
{
  "result": {
    "data": {
      "json": {
        "checkoutSessionId": "cs_abc123..."
      }
    }
  }
}
```

### Webhook Payload (inbound from moneymotion)

```
Headers:
  X-Webhook-Signature: {base64(hmac_sha512(body, webhook_secret))}
  (or X-Signature as fallback)

Body:
{
  "event": "checkout_session:complete",
  "timestamp": 1712890000,
  "checkoutSession": {
    "id": "cs_abc123...",
    "amountInCents": 5000,
    "currency": "BRL",
    "lineItems": [...]
  }
}

Supported events:
  - checkout_session:complete   → approve transaction
  - checkout_session:refunded   → mark transaction refunded
  - checkout_session:expired    → mark session failed
  - checkout_session:disputed   → mark session failed
```
