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
| AI segment generator → validated `SegmentDefinition` | TODO |
| AI campaign post-mortem analysis                    | TODO |
| AI assistant command interface (tool-restricted)    | TODO |
| Recommendation panel                                | TODO |

## Phase 4 — Automation

| Item | Status |
|------|--------|
| Journey schema (nodes, connections, runs, logs) | DONE (schema) |
| Trigger registry + dispatcher                   | TODO |
| Condition evaluator (reuses segment compiler)    | TODO |
| Action executors (email, tag, list, contact, lead, task, webhook, wait) | TODO |
| Timer processing in scheduler                    | TODO |
| Lead recovery journey templates                  | TODO |

## Phase 5 — Revenue

| Item | Status |
|------|--------|
| Pipelines, stages, leads, stage history, tasks, notes | DONE (schema) |
| Lead pipeline UI + lead scoring rules                 | TODO |
| Website event tracking snippet + `/api/v1/events`     | TODO |
| Conversion API                                        | TODO |
| Revenue attribution (last click, configurable window) | TODO |
| BI dashboard + Chart.js reports                       | TODO |

## Phase 6 — Advanced

A/B testing · landing pages · hosted + embedded forms · content library ·
advanced recommendations · agency multi-brand UI.
Schema for all of these already exists; UI/engine TODO.

## Phase 7 — Multichannel

SMS and WhatsApp providers behind `ChannelProviderInterface`, new automation
actions, per-channel consent (the `channel` column already exists on
`contact_consents`).

## Non-negotiables carried through every phase

- No bulk sending inside an HTTP request.
- No cross-tenant query, ever.
- Compliance re-checked at send time, not just at preview.
- Suppression never cleared by import.
- AI never sends, never mutates consent, never bypasses approval.
- Chunked processing; no 100k-row arrays in memory.
