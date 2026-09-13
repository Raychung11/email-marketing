# Roadmap

Status legend: **DONE** shipped · **PARTIAL** scaffolded/interfaces in place ·
**TODO** not started.

## Phase 1 — Foundation  ✅ DONE (this change)

| Item                                    | Status |
|-----------------------------------------|--------|
| Lightweight modular core (router, DI, config, views) | DONE |
| Dual-grammar schema builder + migrator (MySQL canonical, SQLite for tests) | DONE |
| Full database schema for all phases     | DONE |
| Authentication (register, login, logout, password reset) | DONE |
| Login throttling, CSRF, session hardening, security headers | DONE |
| Organisations + multi-tenancy (`TenantContext`, middleware) | DONE |
| Workspaces/brands (schema + default row)  | DONE |
| RBAC: roles, permissions, permission-based gates | DONE |
| Organisation settings, brand profile      | DONE |
| Contacts CRM + Customer 360 profile       | DONE |
| Companies                                 | DONE |
| Tags, static lists                        | DONE |
| Custom field definitions + values         | DONE |
| Dynamic segmentation engine (nested AND/OR compiler) | DONE |
| Consent (append-only history)             | DONE |
| Suppression list                          | DONE |
| `ComplianceService` with AU + US rule sets | DONE |
| CSV import wizard incl. consent declaration | DONE |
| Signed unsubscribe + preference centre    | DONE |
| Audit log + activity log                  | DONE |
| Onboarding/setup wizard                   | DONE |
| Test suite (tenancy, RBAC, compliance, security) | DONE |

## Phase 2 — Email  ✅ DONE

| Item | Status |
|------|--------|
| `EmailProviderInterface`, `ChannelProviderInterface` | DONE |
| `LogEmailProvider` (dev/test)                        | DONE |
| Queue abstraction + Redis/DB/sync drivers            | DONE |
| Worker + Supervisor config + scheduler with locking  | DONE |
| `AmazonSesProvider` (send, validateIdentity, getQuota, getReputationMetrics) | PARTIAL — requires `aws/aws-sdk-php` |
| Sending domain verification wizard (DKIM/SPF/DMARC)  | DONE |
| Block-based template builder                         | DONE |
| Campaign CRUD + approval workflow                    | DONE |
| Recipient snapshot + chunked enqueue + send worker    | DONE |
| Send throttling against tenant limit and live provider quota | DONE |
| Second compliance check immediately before the provider call | DONE |
| SNS event webhook `/webhooks/aws/ses` (signature verified, idempotent) | DONE |
| Bounce/complaint → suppression                        | DONE |
| Open/click tracking, link tracking, UTM builder       | DONE |
| Deliverability dashboard + reputation alerts          | DONE |
| Campaign reporting (funnel, top links, skip reasons)  | DONE |

## Phase 3 — AI

| Item | Status |
|------|--------|
| `AiProviderInterface` + `OpenAiProvider` + `NullAiProvider` | DONE |
| `ai_requests` token accounting                      | DONE |
| AI Campaign Studio (structured JSON campaign draft) | DONE |
| Subject line / preview text generation              | DONE |
| AI segment generator → validated `SegmentDefinition` | DONE |
| AI campaign post-mortem analysis                    | DONE |
| AI assistant command interface (tool-restricted)    | TODO |
| Recommendation panel                                | DONE — measured recommendations; AI reviews stored alongside |

## Phase 4 — Automation  ✅ DONE

| Item | Status |
|------|--------|
| Journey schema (nodes, connections, runs, logs) | DONE |
| Trigger registry + dispatcher                   | DONE |
| Condition evaluator (reuses segment compiler)    | DONE |
| Action executors (email, tag, list, contact, task, notify, webhook, wait) | DONE |
| Timer processing in scheduler                    | DONE |
| Journey templates (welcome, lead recovery, win back) | DONE |

## Phase 5 — Revenue  ✅ DONE

| Item | Status |
|------|--------|
| Pipelines, stages, leads, stage history, tasks, notes | DONE |
| Lead pipeline UI + explainable lead scoring           | DONE |
| Website event tracking snippet + `/api/v1/events`     | DONE |
| Conversion API (idempotent on external id)            | DONE |
| Revenue attribution (last/first click, configurable window) | DONE |
| Revenue + engagement reports with Chart.js            | DONE |

## Phase 6 — Advanced

| Item | Status |
|------|--------|
| Hosted + embedded signup forms with versioned consent evidence | DONE |
| A/B testing with a significance gate                  | DONE |
| Landing pages                                         | TODO — schema exists |
| Content library (approved claims for AI)              | TODO — schema exists |
| Agency multi-brand UI                                 | TODO — workspace column exists |

## Phase 7 — Multichannel  ✅ DONE

| Item | Status |
|------|--------|
| `TextProviderInterface` behind `ChannelProviderInterface` | DONE |
| Twilio provider (SMS + WhatsApp), log provider for dev/test | DONE |
| Per-channel consent — email consent never authorises a text | DONE |
| STOP handling, quiet hours, cost estimate before sending | DONE |
| `send_sms` automation action                          | DONE |
| WhatsApp template messages                            | PARTIAL — provider supports it, no UI |

## Non-negotiables carried through every phase

- No bulk sending inside an HTTP request.
- No cross-tenant query, ever.
- Compliance re-checked at send time, not just at preview.
- Suppression never cleared by import.
- AI never sends, never mutates consent, never bypasses approval.
- Chunked processing; no 100k-row arrays in memory.
