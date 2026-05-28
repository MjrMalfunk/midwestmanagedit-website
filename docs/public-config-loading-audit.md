# Public Config Loading Audit (Audit-Only)

Date: 2026-05-27  
Scope: Public website repo config loading paths for secrets + scheduler integrations.  
Mode: Audit-only (no runtime behavior changes).

## 1) Current active config paths

### General secrets (Brevo for subscribe / estimate / scheduler status update)
- Hardcoded path in runtime handlers:
  - `/home/mjrmstlj/private/mmit-secrets.php`
- Loaded by:
  - `subscribe.php`
  - `estimate-request.php`
  - `api/scheduler/book.php` (Brevo contact status update helper)

### Scheduler config (Microsoft Graph / M365 calendar)
- Candidate load order from `mmit_scheduler_config_candidates()`:
  1. `getenv('MMIT_SCHEDULER_CONFIG')` if present/non-empty.
  2. On staging host `test.midwestmanagedit.com`: `/home/mjrmstlj/private/mmit/scheduler.staging.php` only.
  3. On production/default hosts: `/home/mjrmstlj/private/mmit/scheduler.php`.
  4. Production-only deprecated fallback: `/home/mjrmstlj/private/mmit-scheduler.php`.
  5. Local/dev fallback candidates: `<repo>/api/scheduler/config.local.php`, `<repo>/private/mmit-scheduler.php`, and `<parent-of-repo>/private/mmit-scheduler.php`.
- Loaded by:
  - `api/scheduler/status.php`
  - `api/scheduler/availability.php`
  - `api/scheduler/book.php`
  - (all through `includes/scheduler-lib.php`)

### Estimate token storage / estimate-link retrieval (non-secret private path usage)
- Uses HOME-derived location:
  - `{$HOME}/private/mmit/estimate-links`
- Used by:
  - `estimate-request.php` (token creation/write)
  - `api/estimate-link.php` (token read/validation)

## 2) Which config is active per flow

- **Public site general secrets:** `/home/mjrmstlj/private/mmit-secrets.php` (array required).
- **Scheduler core (availability + booking + status):** scheduler config array from `scheduler_load_config()` candidate chain.
- **Estimate request flow (`estimate-request.php`):** Brevo API key/list IDs + attribute map from `/home/mjrmstlj/private/mmit-secrets.php`.
- **Subscribe flow (`subscribe.php`):** Brevo API key + pending list ID from `/home/mjrmstlj/private/mmit-secrets.php`.
- **Contact/scheduler booking flow (`api/scheduler/book.php`):**
  - Microsoft Graph booking from scheduler config array.
  - Brevo status-mark update (`NEXT_STEP`, `ESTIMATE_STATUS`) from `/home/mjrmstlj/private/mmit-secrets.php`.
- **Brevo integrations:** `subscribe.php`, `estimate-request.php`, and helper inside `api/scheduler/book.php`.
- **Microsoft Graph scheduler integrations:** `includes/scheduler-lib.php` via scheduler endpoints.

## 3) Expected config format by file

### `/home/mjrmstlj/private/mmit-secrets.php` (current)
- Expected format: `return [...]` PHP array (not `define(...)`).
- Required keys observed:
  - `brevo.api_key`
  - `brevo.pending_list_id`
  - `brevo.estimate_list_id` (or fallback to pending list)
  - optional `brevo.estimate_attribute_map` (array)

### Scheduler config file(s) (current candidates incl. legacy `mmit-scheduler.php`)
- Expected format: `return [...]` PHP array (not `define(...)`).
- Required keys validated by `scheduler_is_configured()`:
  - `provider`, `calendar_user`, `tenant_id`, `client_id`, `client_secret`, `timezone`, `graph_timezone`
- Expected nested/optional keys used:
  - `availability_schedules` array of mailbox/calendar addresses checked through Microsoft Graph `getSchedule`; when missing or empty, scheduler falls back to `[calendar_user]`.
  - `booking_attendees` array of internal attendee email strings or attendee arrays (`email`/`address`, optional `name`, optional `type`) added to created booking events.
  - `meeting` sub-array (`duration_minutes`, `buffer_minutes`, `min_notice_minutes`, etc.)
  - `working_hours`, `blackout_dates`, `blackout_ranges`, `timezone_label`

#### Non-secret scheduler config shape example

```php
<?php
return [
    'provider' => 'm365',
    'calendar_user' => 'scheduling@midwestmanagedit.com',
    'availability_schedules' => [
        'scheduling@midwestmanagedit.com',
        'consultant@example.com',
        'service-desk@example.com',
    ],
    'booking_attendees' => [
        ['email' => 'consultant@example.com', 'name' => 'MMIT Consultant'],
        'service-desk@example.com',
    ],
    'tenant_id' => 'YOUR_TENANT_ID',
    'client_id' => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    'timezone' => 'America/Indiana/Indianapolis',
    'graph_timezone' => 'Eastern Standard Time',
    'timezone_label' => 'Eastern time',
    'meeting' => [
        'duration_minutes' => 30,
        'buffer_minutes' => 15,
        'min_notice_minutes' => 120,
    ],
    'working_hours' => [
        1 => [['start' => '09:00', 'end' => '16:00']],
        2 => [['start' => '09:00', 'end' => '16:00']],
        3 => [['start' => '09:00', 'end' => '16:00']],
        4 => [['start' => '09:00', 'end' => '16:00']],
        5 => [['start' => '09:00', 'end' => '16:00']],
    ],
    'blackout_dates' => [],
    'blackout_ranges' => [],
];
```

### Other private files in-scope
- `{$HOME}/private/mmit/estimate-links/*.json` are JSON records written/read at runtime; not PHP config.

## 4) App code that would need updates for desired final private structure

Desired target paths:
- Production secrets: `/home/mjrmstlj/private/mmit/secrets.php`
- Staging secrets: `/home/mjrmstlj/private/mmit/secrets.staging.php`
- Production scheduler: `/home/mjrmstlj/private/mmit/scheduler.php`
- Staging scheduler: `/home/mjrmstlj/private/mmit/scheduler.staging.php`

### Files requiring code changes in implementation pass

1. **`estimate-request.php`**
   - Replace hardcoded `/home/mjrmstlj/private/mmit-secrets.php` with environment/hostname-aware loader and fallback chain for new paths.
2. **`subscribe.php`**
   - Same secrets loader migration as above.
3. **`api/scheduler/book.php`**
   - `mmit_mark_estimate_scheduled()` currently loads legacy secrets path; migrate to shared secrets loader.
4. **`includes/scheduler-lib.php`**
   - `scheduler_load_config()` candidate list must include new production/staging scheduler files and preserve temporary backward-compatible fallback during migration.
5. **(Recommended new shared utility file, implementation pass)**
   - e.g., `includes/private-config.php` to centralize host/env detection and candidate path resolution to avoid drift.

## 5) Hostname-aware staging detection status

- **Not currently implemented as staging control logic**.
- Only hostname usage found is `mmit_base_url()` in `estimate-request.php`, which builds links from `$_SERVER['HTTP_HOST']`; this is URL composition, not environment gating.
- No explicit checks for `test.midwestmanagedit.com` found.

## 6) Existing staging/test safety controls status

### Outbound email / Brevo submissions
- **No staging safety gating found** (no allowlist/denylist/sink mode toggle by hostname).
- Current behavior sends directly to Brevo if API key + list IDs are present.

### Scheduler booking / Graph actions
- **No staging hostname safety gating found**.
- Safety currently depends on whichever scheduler config file is loaded; if pointed at live calendar credentials, staging would perform live Graph actions.

### Estimate submissions
- **No staging-specific suppression found**.
- Estimate endpoint posts to Brevo when secrets are configured.

## 7) Migration plan (no implementation in this pass)

1. Add a shared private-config resolver helper (`includes/private-config.php`) with:
   - canonical host detection from `HTTP_HOST` (normalized/trimmed/lowercased)
   - `is_staging_host()` check for exact `test.midwestmanagedit.com`
   - candidate path chains for secrets and scheduler files
   - optional env var override support for emergency rollback
2. Update `subscribe.php`, `estimate-request.php`, and `api/scheduler/book.php` to use shared secrets resolver.
3. Update `includes/scheduler-lib.php::scheduler_load_config()` to use shared scheduler resolver and new target paths first.
4. Keep temporary backward-compatible fallback to legacy files during cutover:
   - `/home/mjrmstlj/private/mmit-secrets.php`
   - `/home/mjrmstlj/private/mmit-scheduler.php`
5. Add explicit staging safety switches (default-safe):
   - `MMIT_DISABLE_OUTBOUND_EMAIL` for subscribe/estimate/scheduler Brevo writes
   - `MMIT_DISABLE_SCHEDULER_BOOKING` for Graph event creation in non-prod
   - optional sink mode routing (e.g., force all staging records to internal mailbox/list)
6. Add operator-facing docs for required private file deployment, permissions, and verification commands.
7. After validated cutover, remove legacy fallback chain in a cleanup pass.

## 8) Manual copy/migration command plan (for hosting terminal; do not run in repo)

> Note: Commands below are planning-only and should be executed manually on hosting after backups.

```bash
# 0) Backup legacy files first
cp -a /home/mjrmstlj/private/mmit-secrets.php /home/mjrmstlj/private/mmit-secrets.php.bak.$(date +%Y%m%d%H%M%S)
cp -a /home/mjrmstlj/private/mmit-scheduler.php /home/mjrmstlj/private/mmit-scheduler.php.bak.$(date +%Y%m%d%H%M%S)

# 1) Create organized directory
mkdir -p /home/mjrmstlj/private/mmit
chmod 700 /home/mjrmstlj/private/mmit

# 2) Seed production files from legacy
cp -a /home/mjrmstlj/private/mmit-secrets.php /home/mjrmstlj/private/mmit/secrets.php
cp -a /home/mjrmstlj/private/mmit-scheduler.php /home/mjrmstlj/private/mmit/scheduler.php

# 3) Create staging variants (manual edit required after copy)
cp -a /home/mjrmstlj/private/mmit/secrets.php /home/mjrmstlj/private/mmit/secrets.staging.php
cp -a /home/mjrmstlj/private/mmit/scheduler.php /home/mjrmstlj/private/mmit/scheduler.staging.php

# 4) Lock permissions
chmod 600 /home/mjrmstlj/private/mmit/secrets.php
chmod 600 /home/mjrmstlj/private/mmit/secrets.staging.php
chmod 600 /home/mjrmstlj/private/mmit/scheduler.php
chmod 600 /home/mjrmstlj/private/mmit/scheduler.staging.php
```

## 9) Risks

- Hardcoded legacy file paths currently create single-source dependency and no environment split.
- Staging currently has no app-layer suppression for Brevo or Graph actions.
- Mispointed scheduler config could create production calendar events from staging.
- Incomplete attribute map or list IDs can produce silent operational failures (500s in user flow).
- During migration, mixed file formats (if someone adds `define(...)` style) would break because loaders expect returned arrays.

## 10) Recommended staging safety controls (next pass)

- Hostname-aware environment detection with exact staging host: `test.midwestmanagedit.com`.
- Non-prod default: disable outward side-effects unless explicitly enabled.
- Optional guarded allowlist for recipient domains/emails in staging.
- Distinct staging Brevo list IDs and distinct scheduler target calendar.
- Structured logging marker per environment (`env=prod|staging`) for operational review.

## 11) Exact next Codex implementation steps

1. Implement `includes/private-config.php` resolver + host detection helpers.
2. Refactor all secrets consumers to shared loader (`subscribe.php`, `estimate-request.php`, `api/scheduler/book.php`).
3. Refactor scheduler loader to new path chain in `includes/scheduler-lib.php`.
4. Add environment safety toggles for outbound integrations (Brevo + Graph booking).
5. Add/update docs with deployment verification checklist.
6. Run `rg` verification to ensure all legacy hardcoded paths are either removed or intentionally fallback-only.
7. Run `php -l` on modified PHP files in implementation pass.
8. Deploy staged private files, validate staging behavior, then production cutover.

## 12) Audit command log (this pass)

- `rg --files -g 'AGENTS.md'`
- `rg --files -g '*.php'`
- `rg -n "/home/mjrmstlj/private|mmit-secrets.php|mmit-scheduler.php|private/mmit/|Brevo|Graph|Microsoft|scheduler|subscribe|estimate|config|secrets" api includes estimate-request.php subscribe.php`
- `rg -n "test\.midwestmanagedit\.com|HTTP_HOST|SERVER_NAME|staging|environment|ENV|prod|production" *.php api includes assets/js`
- `nl -ba ...` inspections of relevant PHP files for line-level traceability.

## 13) Implementation status (2026-05-27)

Implemented in this repository:

- Added `includes/private-config.php` with hostname-aware private config loading.
- Production host (`midwestmanagedit.com`) secrets load order:
  1. `/home/mjrmstlj/private/mmit/secrets.php`
  2. `/home/mjrmstlj/private/mmit-secrets.php` (**deprecated fallback, production-only**)
- Staging host (`test.midwestmanagedit.com`) secrets load:
  - `/home/mjrmstlj/private/mmit/secrets.staging.php`
  - If missing, request fails safely with: `Missing MMIT staging config.`
  - Staging does not fall back to production secrets.
- Production host scheduler load order:
  1. `/home/mjrmstlj/private/mmit/scheduler.php`
  2. `/home/mjrmstlj/private/mmit-scheduler.php` (**deprecated fallback, production-only**)
  3. Existing local/dev fallback candidates remain for backward compatibility.
- Staging host scheduler load:
  - `/home/mjrmstlj/private/mmit/scheduler.staging.php`
  - If missing, request fails safely with: `Missing MMIT staging scheduler config.`
  - Staging does not fall back to production scheduler config.

Updated runtime consumers:

- `subscribe.php`
- `estimate-request.php`
- `api/scheduler/book.php`
- `includes/scheduler-lib.php`

### Private path policy reminder

Private config files must remain outside all public web roots (for cPanel/shared hosting: under `/home/mjrmstlj/private/...`).
Do not place secrets in repository-tracked files.

### Manual migration/copy commands (run on hosting terminal, not in repo)

```bash
# Backup legacy files
cp -a /home/mjrmstlj/private/mmit-secrets.php /home/mjrmstlj/private/mmit-secrets.php.bak.$(date +%Y%m%d%H%M%S)
cp -a /home/mjrmstlj/private/mmit-scheduler.php /home/mjrmstlj/private/mmit-scheduler.php.bak.$(date +%Y%m%d%H%M%S)

# Ensure organized directory exists
mkdir -p /home/mjrmstlj/private/mmit
chmod 700 /home/mjrmstlj/private/mmit

# Seed production files
cp -a /home/mjrmstlj/private/mmit-secrets.php /home/mjrmstlj/private/mmit/secrets.php
cp -a /home/mjrmstlj/private/mmit-scheduler.php /home/mjrmstlj/private/mmit/scheduler.php

# Create staging variants (then edit with staging-only values)
cp -a /home/mjrmstlj/private/mmit/secrets.php /home/mjrmstlj/private/mmit/secrets.staging.php
cp -a /home/mjrmstlj/private/mmit/scheduler.php /home/mjrmstlj/private/mmit/scheduler.staging.php

# Lock private file permissions
chmod 600 /home/mjrmstlj/private/mmit/secrets.php
chmod 600 /home/mjrmstlj/private/mmit/secrets.staging.php
chmod 600 /home/mjrmstlj/private/mmit/scheduler.php
chmod 600 /home/mjrmstlj/private/mmit/scheduler.staging.php
```

### Staging safety reminders

- Never copy production tokens/keys into staging config without explicit approval.
- Confirm staging host is exactly `test.midwestmanagedit.com`.
- Verify staging files exist before smoke testing scheduler and form endpoints.

