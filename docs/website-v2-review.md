# Website v2 — inquiry-first review

Baseline: `99cc5f9` on `MjrMalfunk/midwestmanagedit-website`.
Review branch: `website-v2-inquiry-review`.

## What changed

The homepage now explains managed service providers in plain language, introduces all six MMIT service areas, compares the intent of Manage IT / Protect IT / Govern IT, and leads visitors toward sending an inquiry. The navy and mint design uses larger typography, a lighter process section, and responsive layouts.

The new contact page asks for a name, business, email and message. Phone and service interest are optional. Existing clients have a separate portal link. The inquiry form does not enroll visitors in marketing lists.

Existing service detail pages, pricing configuration, estimator calculations, portal, scheduler API and integrations remain. Their primary navigation now points toward an inquiry. The original contact/scheduler page is retained as `consultation.html`; the existing opaque `schedule.html` email-link flow leads there. The estimator's inquiry link carries its existing session-storage context without placing contact details into the URL.

## Files and runtime

New runtime files: `inquiry.php`, `includes/inquiry-lib.php`, `assets/js/inquiry.js`, `assets/css/v2.css`, `consultation.html`.

Updated runtime files include the root HTML pages, `assets/js/estimator.js`, and `assets/data/search-index.json`. There are no new site frameworks, package managers or build steps. PHP 8.1+ with cURL and working PHP sessions is required. Dependencies used for development are outside the site repository.

## Brevo setup before enabling

The handler uses the existing `includes/private-config.php` loader:

- Staging: `/home/mjrmstlj/private/mmit/secrets.staging.php`
- Production: existing `/home/mjrmstlj/private/mmit/secrets.php` (existing legacy fallback retained)

Add an `inquiry` entry to the returned configuration array, preserving all existing keys:

```php
'inquiry' => ['enabled' => true],
```

It uses the existing `brevo.api_key`; do not paste credentials into Git or client-side code. Until enabled with valid configuration, the form displays an email fallback and never claims success.

Create a Brevo automation triggered by the appropriate custom event:

| Environment | Event |
| --- | --- |
| Staging | `mmit_inquiry_staging` |
| Production | `mmit_inquiry_received` |

The event identifies the contact with `identifiers.email_id`. Properties are `name`, `company`, `email`, `phone`, `interest`, `message`, `inquiry_id`, `source` (`website_contact`), `environment`, and `marketing_opt_in` (`false`). No custom contact attributes or list IDs are required by this handler.

Configure the workflow to notify the owner with the inquiry context and acknowledge receipt to the visitor. Render submitted text as escaped text in templates, not trusted HTML. Actual Brevo automation, templates, recipients and sender settings have not been created or verified in this task. API acceptance alone does not prove the resulting emails were delivered.

Before staging submissions, inspect any existing broad “contact created” automations: the identity upsert could trigger those independently of the isolated staging event. Use only designated test addresses under Keith's control. An inquiry is not newsletter consent; any later nurture subscription needs its own explicit choice and flow.

API references used for the implementation:

- https://developers.brevo.com/reference/create-contact
- https://developers.brevo.com/reference/create-event

## Delivery and recovery behavior

The endpoint accepts same-origin JSON with a session-bound token. It validates types and field lengths, limits request body size, uses a honeypot and limits valid attempts to ten per network address per hour. PHP session cookies are Secure, HttpOnly and SameSite=Lax. Use HTTPS on staging.

A private copy is written before contacting Brevo:

- `/home/mjrmstlj/private/mmit/inquiries-staging`
- `/home/mjrmstlj/private/mmit/inquiries-production`

The directories are separate from the document root and use restrictive permissions. Contact records contain personal information; limit access and agree on a retention period. The implementation does not automatically delete them. Rate files contain a hashed address and counters; review their cleanup along with record retention.

The handler upserts only the contact email and `updateEnabled`; it does not assign lists, overwrite attributes or reset unsubscribe flags. It then sends the event. A success response requires event API acceptance.

Same-reference retries do not resend an already-delivered event. Failures before event submission may retry. An event timeout or interrupted event submission becomes `needs_review` / `sending_event` and is not blindly resent, because Brevo might already have accepted it. The visitor gets an honest error with a reference and email fallback. Inputs stay in the browser. PHP error logs identify the environment/reference without dumping the message or API key.

For a recovery case, inspect the private JSON and Brevo event history by inquiry reference. Establish whether the event arrived before any manual resend or direct follow-up. There is no automatic queue worker or automatic recovery claim in this draft.

## Staging rollout

1. Confirm the website staging checkout, deployed document root, current commit, and clean status. The OPS repository paths are not website deployment paths.
2. Fetch the review branch. Compare every changed existing deployed file with its baseline; added files should be absent. Do not treat an expected new file as drift.
3. Back up the changed deployed files and record additions before copying the reviewed runtime files.
4. Keep `docs/`, `tests/`, Git metadata and configuration outside the document root.
5. Verify PHP 8.1+ syntax, cURL, session storage, and writable private inquiry directory on the host.
6. Configure the isolated Brevo staging workflow and enable the staging inquiry configuration.
7. Submit a designated test inquiry. Verify the private record, Brevo contact/event, owner notification, acknowledgment and browser success state.
8. Test estimator context, existing consultation links, mobile navigation and the existing portal link.
9. Review the result on `test.midwestmanagedit.com` before any production promotion.

Nothing has been deployed to staging or production by this task. No real inquiry, email or Brevo API request was sent.

## Validation

- PHP 8.3 syntax and unit tests passed locally; run the same checks with the hosting PHP version before staging deployment.
- Tests cover validation, private recovery, contact/event failures, uncertain delivery, duplicate submission prevention, staging event separation and rate limiting.
- Browser checks cover 390, 768 and 1440px widths for homepage, contact, services, plans and estimator; mobile navigation; form failure/retry; expired token refresh; estimator context.
- Browser inquiry responses and PHP transports are mocked. These checks do not replace the real Brevo staging acceptance test.

```bash
php -l inquiry.php
php -l includes/inquiry-lib.php
php tests/inquiry_test.php
node --check assets/js/inquiry.js
node --check assets/js/estimator.js
```

For optional browser tests, serve the repository locally and use an external Playwright installation; see `tests/website_v2_browser.cjs`. No npm installation is needed on cPanel.
