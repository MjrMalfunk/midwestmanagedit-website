# Midwest Managed IT Website Instructions

This is the v1 public-facing website for Midwest Managed IT.

## Main rule

Preserve all current launch functionality. Do not rewrite the architecture unless explicitly asked.

## Hosting assumptions

- Site is intended for shared/cPanel-style hosting unless otherwise stated.
- Prefer simple PHP, HTML, CSS, and JavaScript-compatible changes.
- Do not introduce build tools, frameworks, or package managers without approval.

## Must preserve

- Existing navigation
- Existing contact form wiring
- Existing scheduler/consultation CTA wiring
- Existing portal/client login links
- Existing pricing and package structure
- Existing images/assets unless clearly unused
- Current premium dark MSP visual style
- Client-facing wording

## Review priorities

Flag these as high priority:

- Broken links
- Missing assets
- PHP include/path errors
- Exposed secrets
- Unsafe form handling
- Incorrect pricing
- Dead CTAs
- Mobile layout problems
- Visible staging/test/build-status wording
- Missing SEO metadata on core pages
- Accessibility issues affecting navigation or forms

## Design rules

- Keep the site branded as Midwest Managed IT.
- Do not copy competitor text, images, layouts, branding, or trade dress.
- Competitor research may only be used as pattern inspiration.
- Favor clean, secure, local MSP positioning.

## Secrets

Live keys and credentials are stored outside the public web root in the hosting account private directory. Do not add real secrets to this repository.

## Research brief

For design/content/SEO strategy work, use:

`_codex/research/top-100-msp-website-report.md`

Treat this file as strategic guidance only.

Do:
- Use pattern-level recommendations.
- Improve clarity, SEO structure, CTA flow, trust signals, service positioning, and page hierarchy.
- Preserve Midwest Managed IT branding and current launch functionality.

Do not:
- Copy competitor text.
- Copy competitor layouts exactly.
- Copy images, brand elements, colors, names, or trade dress.
- Rewrite the whole site architecture unless explicitly requested.