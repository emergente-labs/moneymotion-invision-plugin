<!-- markdownlint-disable MD041 -->
![moneymotion Logo](images/hero.png)
<!-- markdownlint-enable MD041 -->

# moneymotion Payment Gateway for Invision Community

Accept global payments seamlessly with moneymotion on your Invision Community platform.

---

## 🚀 Quick Start

### Requirements

- **Invision Community** 4.7+ with Commerce enabled
- **Full IPS License** (not demo mode)
- **moneymotion Account** with API key
- **HTTPS** (required by moneymotion)

### Installation

1. **Download** `moneymotion.tar` from [Releases](https://github.com/emergente-labs/moneymotion-invision-plugin/releases)
2. Go to **ACP > System > Site Features > Applications**
3. Click **Install** and upload `moneymotion.tar`
4. Done! The application auto-creates the database table

---

## ⚙️ Setup

### In Your IPS Community

1. Go to **ACP > Commerce > Payment Methods > Add Method**
2. Select **moneymotion** as gateway
3. Enter your **API Key** (starts with `mk_live_` or `mk_test_`)
4. Enter your **Webhook Secret** (from moneymotion dashboard)
5. Save

### In Your moneymotion Dashboard

1. Go to **Webhooks**
2. Create webhook: `https://your-community.com/moneymotion/webhook/`
3. Subscribe to: `checkout_session:complete`
4. Copy the signing secret to your IPS settings

---

## 💳 Payment Flow

```text
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│  1. Customer clicks "Pay with moneymotion"                 │
│                         ↓                                  │
│  2. Creates checkout session at moneymotion API            │
│                         ↓                                  │
│  3. Redirects to moneymotion checkout page                 │
│         (https://moneymotion.io/checkout/{id})             │
│                         ↓                                  │
│  4. Customer enters payment details & confirms             │
│                         ↓                                  │
│  5. moneymotion processes payment                          │
│                         ↓                                  │
│  6. Webhook sent to your IPS community                     │
│         (checkout_session:complete event)                  │
│                         ↓                                  │
│  7. Transaction marked as PAID ✅                          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## 📡 Webhook Events

| Event | Action |
| --- | --- |
| `checkout_session:complete` | ✅ Marks transaction as paid |
| `checkout_session:refunded` | 🔄 Marks transaction as refunded |
| `checkout_session:expired` | ❌ Marks session as failed |
| `checkout_session:disputed` | ❌ Marks session as failed |

## 🛠️ Troubleshooting

### "Invalid API Key"

- Verify key starts with `mk_live_` (production) or `mk_test_`
- Check your moneymotion account is active

### Webhook Not Being Called

- Confirm webhook URL: `https://your-domain.com/moneymotion/webhook/`
- Verify `checkout_session:complete` event is subscribed
- Check moneymotion dashboard webhook logs

### "URL must use HTTPS"

- moneymotion requires HTTPS for all callback URLs
- Use valid SSL certificate (self-signed may fail)

---

## 📚 Resources

- [moneymotion Documentation](https://docs.moneymotion.io)
- [Invision Community Forums](https://invisioncommunity.com)
- [REST API Reference](https://invisioncommunity.com/developers/rest-api)

---

## 📄 License

Open source - use freely for your Invision Community installation.

---

**Made for Invision Community** ❤️
