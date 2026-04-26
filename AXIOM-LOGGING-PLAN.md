# Axiom Logging — Integration Plan

## The core problem

You can't ship your Axiom ingest token inside a PHP plugin. Anyone who installs the `.tar` can read `applications/moneymotion/...` and extract it. Once leaked, anyone can dump garbage into your dataset and burn your Axiom quota. So the design has to be: **the plugin never talks to Axiom directly.**

## Recommended architecture: relay through your existing backend

You already own a backend (`/rpc`, Effect RPC). Add a thin log-ingest endpoint there. It becomes the only thing that holds the Axiom token.

```
[IPS site running plugin]
        │
        │  POST /rpc/logs.ingest (or /logs/ingest)
        │  Auth: per-install bearer (already issued during plugin auth flow)
        │  Body: NDJSON batch of log events
        ▼
[Your Effect RPC backend]
        │  • verify install token
        │  • rate-limit / quota per install
        │  • drop/redact PII fields
        │  • enrich (install_id, plugin_version, ips_version)
        │  • forward to Axiom
        ▼
[Axiom dataset: moneymotion-plugin]
```

Why this shape:

- Your Axiom token lives only in backend env vars (e.g. `AXIOM_TOKEN`, `AXIOM_DATASET`). Never on disk on a customer's IPS server.
- You can revoke a single misbehaving install without rotating Axiom credentials.
- You can change log destinations later (Axiom → Datadog → S3) without redeploying the plugin.
- It reuses the auth you already built for the RPC migration — no new key-distribution problem.

## Auth model

- Each install already authenticates to your backend (the recent commits show install-bound auth + User-Agent versioning). **Reuse the same token** for log ingest. No second secret to ship.
- Backend rejects log writes from installs that aren't in good standing (revoked, banned, exceeded quota).
- Optional hardening: HMAC-sign each batch with a per-install secret derived at install activation time, so a stolen bearer alone can't write logs from elsewhere.

## What gets logged (plugin-side responsibilities)

Define a small, intentional event taxonomy — don't ship a generic logger that drains everything:

- **Errors / exceptions**: gateway failures, RPC failures, webhook validation failures, refund path errors.
- **Lifecycle events**: install / upgrade / uninstall, settings changes (values redacted).
- **Domain events** (without PII): checkout started, completed, refunded, webhook received (event id + type, not payload), with currency + amount bucket.
- **Diagnostics**: PHP version, IPS version, plugin version, locale.

Explicitly **out of scope**:

- Customer email, full name, IP (or hash if you really need it)
- Raw webhook payloads
- Stripe customer ids, payment method ids
- Any value from the IPS member record beyond `member_id` if necessary

Have a single `redact()` allowlist on the backend as a second wall — never trust the plugin to scrub correctly.

## Transport from plugin → backend

- **Batched, async, fire-and-forget.** Logging must never slow checkout. Buffer events (e.g. up to 50 events or 5 seconds) into a small queue table or transient, flush via `IPS\Task` (cron-like) every minute.
- **NDJSON** body, gzipped. Matches the rest of your `/rpc` shape.
- **Bounded retry**: 1 retry with backoff, then drop. Logging that can block the gateway is worse than missing logs.
- **Hard caps** plugin-side: max events/min, max bytes/min, max queue size — to protect the customer's site if your backend is down.
- **Local fallback**: on flush failure, drop oldest events, never grow unbounded. Optionally write a single line to PHP `error_log` so the customer's admin can see "logging degraded" without it spamming disk.

## Backend → Axiom

- Use Axiom's `/v1/datasets/{name}/ingest` with NDJSON.
- **Per-install rate limit** (token bucket in Redis or your existing store) — e.g. 100 events/min sustained, 500 burst. Hard refusal beyond that, surfaced as a 429 the plugin respects.
- **Global circuit breaker**: if Axiom 5xx's or you hit cost ceiling, stop forwarding and drop, don't pile up.
- **Sampling tiers**: errors and lifecycle = 100%; high-volume domain events = sampled (e.g. 10%) with a `sample_rate` field so Axiom queries can re-weight.
- **Cost guardrails**: monitor Axiom `monthly-allowed-usage`; alert at 70/85%; auto-throttle at 95%.

## Opt-in / consent (this is a payments plugin — important)

- Default = **off** in the plugin's ACP settings. Flip on by admin action.
- ACP setting: "Send anonymized diagnostic logs to MoneyMotion to improve the plugin." Link a short privacy note describing exactly the fields collected.
- Provide a "Disable & purge" action — once they uninstall or disable, plugin stops emitting and you delete on request via Axiom's delete API. Document this; with EU forum admins running this, it matters.
- For high-trust customers, expose a "Bring your own Axiom" mode later — they put their own token in ACP and logs go directly to their dataset, bypassing your relay. This is a future feature, not v1.

## Configuration surface (ACP)

- Toggle: enable/disable
- Verbosity: errors / errors+lifecycle / full (the three buckets above)
- Last successful flush timestamp + queued event count (for admin debugging)
- "Send a test event" button

No token field in v1 — auth is implicit from existing install credentials.

## Production-readiness checklist

- [ ] Axiom token only in backend env, never in repo, never in plugin tarball
- [ ] Per-install rate limits + global cap
- [ ] PII redaction allowlist on backend (defense in depth)
- [ ] Opt-in default-off, with clear privacy text
- [ ] Async, bounded, drop-on-overflow plugin queue
- [ ] Versioned event schema (`schema_version` field) so you can evolve later
- [ ] Axiom dataset retention configured (e.g. 30 days for events, 90 for errors via separate dataset)
- [ ] Alerts: ingest stopped, error-rate spike per plugin version, quota nearing limit
- [ ] Runbook: how to disable a noisy install, how to honor a deletion request
- [ ] Tests: unit (redaction, sampling, backoff), integration (queue → flush → relay → mock Axiom)

## Main risks to weigh up front

1. **Cost blowup** — one buggy customer site loops and emits millions of events. Mitigation: per-install rate limit + circuit breaker, both plugin-side and backend-side.
2. **GDPR** — if anything PII slips through, you're a data controller for EU forum members. Mitigation: opt-in, redaction allowlist (not blocklist), explicit privacy notice.
3. **Customer trust** — admins will read your tarball. If they find an SDK that phones home by default, you'll be flamed in the IPS marketplace. Mitigation: default-off + visible toggle + clear docs.
4. **Vendor lock** — wrap Axiom in a backend-side `LogSink` interface so you can swap providers without touching the plugin.

## Suggested rollout order

1. Backend `/logs/ingest` endpoint + auth + per-install rate limit + Axiom forwarder behind a feature flag.
2. Plugin-side `Logger` with async queue + `IPS\Task` flusher, behind ACP opt-in.
3. Error events only (highest signal, lowest volume) for the first 2 weeks.
4. Add lifecycle + domain events with sampling.
5. Add admin-visible diagnostics panel + "test event" button.
6. Document privacy + deletion process before public release.
