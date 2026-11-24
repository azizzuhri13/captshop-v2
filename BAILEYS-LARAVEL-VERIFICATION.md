# Baileys Laravel adapter — verification notes

Scope: local isolated Laravel worktree only. No production access, database writes, WhatsApp pairing, or real sends.

## Exact workspaces
- Feature: `/root/.hermes/cache/scratch/captshop-baileys-laravel`, base `61ef6c25b2ca599d05bacdc38ca8d4b4e7c30683`.
- Baseline: `/root/.hermes/cache/scratch/captshop-baileys-baseline`, unchanged detached base `61ef6c25b2ca599d05bacdc38ca8d4b4e7c30683`.
- Same PHP CLI (`/www/server/php/82/bin/php`, PHP 8.2.35), PHPUnit 10.5.64, `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=`, `INTERNAL_API_KEY=test-internal-key`, Asia/Jakarta timezone, in-memory SQLite, and independently installed dependencies/build manifest.

## Focused checks
- `WhatsAppGatewayTest` + `WhatsAppGatewayStatusTest` after the final localhost URL guard: **21 tests / 86 assertions**, exit 0. PHPUnit's warning instrumentation prints file_get_contents-related WARN labels; there are no test failures.
- Broader impacted feature tests before the URL guard: **41 tests / 160 assertions**, exit 0 (notification, payment webhook, manual invoice WA, security hardening, audit logging).
- PHP syntax checks and `git diff --check`: pass.
- Source search: no Fonnte references in `app`, `config`, `resources`, `tests`, `.env.example`; historical literals exist only in the new cleanup migration.
- Roblox/manual invoice support links remain unchanged and tests still cover per-product contact override.

## Full-suite and baseline comparison
The exact same full PHPUnit command was run from each isolated worktree with the same explicit test-only environment keys.

- Unchanged baseline: **383 tests / 1,881 assertions**, 0 failures, exit 0.
- Feature after final URL-guard change: **403 tests / 1,966 assertions**, 0 failures, exit 0.
- Delta: +20 tests / +85 assertions; no failure-count regression.

Commands:
```bash
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
INTERNAL_API_KEY='test-internal-key' \
/www/server/php/82/bin/php vendor/bin/phpunit --display-warnings --display-errors
```

The output reports `OK` for both runs; no test failures or deprecations were present in the final full-suite output. Focused Laravel runs still emit WARN labels from test instrumentation; those are runner warnings and not failing assertions.

## Gateway integration smoke
A temporary local process ran on 127.0.0.1 with Baileys auto-connect disabled. The real Laravel adapter posted three requests: first accepted, identical replay deduplicated, changed payload rejected as conflict. SQLite read-back showed exactly one queued row with deterministic idempotency key and normalized destination. Temporary smoke helper, DB and process were removed. No real WhatsApp message was sent.

## Security review
- Initial review identified a LOW finding: Laravel send adapter lacked a loopback-only URL allowlist.
- Fixed with `LocalGatewayUrl::sanitize()` used by both send and status paths; invalid URLs fail closed before attaching/sending the internal key.
- Narrow security re-review card `t_081bd759`: **APPROVED** for the reviewed local snapshot; no deployment approval implied.
- Carried residuals: SQLite outbox stores destination/message plaintext (directory/file modes 0700/0600 are best-effort); no real Baileys pairing/send was exercised.

## Bundled gateway and release anchor
- The gateway source now ships inside this repository at `wa-gateway/` (non-public directory; no `node_modules`, no `dist/`, no `data/`), so the deployed Laravel code and its gateway are reproducible from one SHA.
- Port default reconciled to `127.0.0.1:3213` (gateway `src/config.ts`, `.env.example`, README) and Laravel `config/services.php` / `.env.example`; `3211` is occupied by an unrelated `next-server` and is not used.
- Release anchor: commit `110ff63` on branch `feat/baileys-gateway-laravel` (pushed to `origin`, not merged to `master`).

## Operational status
Deployment state for this card is recorded on the kanban task; no WhatsApp account was paired and `next.captshop.id` remains untouched. Production use still requires explicit pairing authorization and the owner's dedicated WhatsApp number/device.
