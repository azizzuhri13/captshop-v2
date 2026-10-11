# Belibayar-only rollout

This branch removes Tripay code, webhook, configuration, seeded channels and checkout selection. It is **not live** until a merchant sandbox run and a controlled production cutover are completed. Never treat mocked tests as a real payment confirmation.

## Preflight

1. Stop new orders for a brief maintenance window. Back up the production database and runtime configuration privately; do not commit credentials.
2. Reconcile all old-gateway payments and refunds in the previous provider's dashboard. Do not deploy this branch while an old-gateway transaction might still be paid or refunded. The removed webhook cannot receive those callbacks. Historical rows remain in the database with `payment_gateway = NULL` and are **not** interpreted as Belibayar; preserve them for audit. The database inspected during development contained zero transactions, but recheck immediately before deployment; that observation may become stale.
3. Obtain a Belibayar merchant account with sandbox API key, API secret, dedicated webhook secret and merchant ID. Configure these via secure server environment or the super-admin settings page **after** deployment. Set the mode to `sandbox` and whitelist the server's outgoing IP in the Belibayar dashboard. Set the payment callback URL to `https://topup.kidbomba.space/webhook/belibayar`.
4. Ensure Belibayar's merchant dashboard enables the intended channels. Seed the new channels; they begin **inactive**. Set real merchant fees per channel in the admin panel before enabling them. Do not publish a zero fee as a verified provider tariff.
5. Test sandbox: create VA, QRIS, e-wallet redirect and OVO push orders as applicable; simulate paid and expired; check signed webhook and invoice inquiry, expiry, amount, and fulfillment idempotency. Record real transaction IDs, HTTP results and any failures without storing secrets in the repository.

## Production cutover

Only after sandbox checks pass: reconcile the old gateway again, take a final backup, run the migration and channel seeder, set `BELIBAYAR_MODE=live` with corresponding live credentials, configure the live webhook URL/IP whitelist and fees, enable only confirmed Belibayar channels, clear config caches, and perform a small controlled live payment. Verify the invoice, receipt, merchant dashboard, webhook processing and supplier fulfillment. If any step fails, disable checkout channels, reconcile payments and restore a safe state; never silently fall back to Tripay because its integration is removed.

The production `.env`, database, checkout and Nginx were not changed by development in the separate worktree. Do not copy this branch to the live webroot until these checks are satisfied.
