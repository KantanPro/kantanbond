# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**KantanBond** — the official WordPress connector plugin for **KantanBiz** (`https://kantanbiz.cloud/`, the Laravel SaaS at `kantanpro-saas/` in this workspace). It talks to KantanBiz's REST API (`/api/v1/...`) to surface a tenant's clients, orders, products/services, and reports on a WordPress site via shortcodes, plus an inbound-token flow for public product pages/inquiries. GitHub: `KantanPro/kantanbond`.

This is a **separate product** from KantanPro/KantanProEX (unrelated WP plugins in this same workspace) — it is specifically the WP-side companion to **KantanBiz**. When changing the API contract on either side, check the other repo: this plugin's `includes/class-api.php` request/response shape must match KantanBiz's `routes/api.php` + `EnsureApiTenant`/`EnsureTenantApiBusinessPlan` middleware (see `kantanpro-saas/CLAUDE.md`).

## Architecture

`kantanbond.php` (`declare(strict_types=1)`) defines constants, `require_once`s each `includes/class-*.php`, then wires everything together manually in `kantanbond_run()` (constructor-injected, no DI container) — `Settings` → `Logger` → `API` → `Shortcodes`/`Public_Products`/`Public_Purchase_Thank_You`/`Billing_Plans`/`Admin` → `Loader::init()`.

- **`includes/class-api.php`** — the API client. `request()` wraps `wp_remote_get`/`wp_remote_post` against the tenant's configured KantanBiz Base URL + access token, with typed helpers (`GET /api/v1/clients`, `/api/v1/orders`, `/api/v1/reports?...`). `inbound_request()` is a **separate authenticated path** using an inbound token (for public/no-login flows like public product pages and inquiry submission) — don't conflate the two auth schemes.
- **`includes/class-settings.php`** — stores Base URL / API access token / API Secret (API Secret = the KantanBiz office/tenant ID for this integration, not a generic shared secret — see `readme.txt` FAQ).
- **`includes/class-shortcodes.php`** — `[kantanbond_customers]`, `[kantanbond_projects]`, `[kantanbond_products]` (alias `[kantanbond_services]`), `[kantanbond_reports type="..." period="..."]`, `[kantanbond_version]`.
- **`includes/class-reference.php`** — `[kantanbond_reference]`, the KantanBiz reference guide (ビズちゃん／ビズ博士 dialogue) rendered in full on one page, chapters as `<details>` accordions. Fetched from KantanBiz's **public, unauthenticated** `GET /api/v1/reference` via `KantanBond_API::get_reference()` → `public_request()` — a third auth path alongside `request()` (PAT) and `inbound_request()` (inbound token): it sends **no** credentials, so don't route tenant data through it. The response is cached in the `kantanbond_reference_v1` transient (12h default, `cache="no"` to disable) and dropped when API settings are saved.
- **`includes/class-public-products.php`** / **`class-public-purchase-thank-you.php`** — public-facing (no WP login) product listing + inquiry/purchase flow, gated by the inbound token from KantanBiz's contact-form/inquiry settings.
- **`includes/class-billing-plans.php`** — renders KantanBiz's pricing plans via `[kantanbond_billing_plans unlock="..."]`, intended for the KantanBiz marketing site specifically; gated behind an "unlock phrase" unless `KANTANBOND_ENABLE_BILLING_PLANS` is defined true in `wp-config.php`. Don't remove this gate casually — it exists to keep the shortcode from being usable on arbitrary sites.
- **`includes/class-github-updater.php`** — self-update via GitHub Releases (`KantanPro/kantanbond`), only runs in admin or WP-Cron context. Rate-limit avoidance via `KANTANBOND_GITHUB_TOKEN` constant.
- **`includes/class-installer.php`** — activation/deactivation hooks.
- **`includes/class-logger.php`** — sync/API call logging, viewable from the admin screen.

## Conventions

- API Base URL must be the **app itself**, not the marketing site — `https://kantanbiz.cloud` (no `www`), since `https://www.kantanbiz.cloud` is a different (WordPress) site. Don't "fix" this by allowing `www.` transparently; it's a deliberate distinction per the readme FAQ.
- Never log or display the API access token / API Secret in plaintext (error messages, debug output) — `class-logger.php` should redact these, matching the "secrets never in output" expectation shared across this whole workspace.
- Requires PHP 8.1 / WP 6.8+ (stricter than the sibling WP plugins here, which target PHP 7.4) — don't introduce PHP 7.4-only syntax workarounds.

## Commands

No automated test suite — verify manually against a real KantanBiz tenant (Base URL + token from the tenant's `/profile` screen) and check each shortcode renders, plus the public-product/inquiry flow with an inbound token issued from KantanBiz's contact-form settings.

## Commit messages

Always write commit messages in Japanese, concise form like `〇〇を追加` / `〇〇を修正` / `〇〇のバグを修正` — never English one-liners.
