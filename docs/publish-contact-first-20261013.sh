#!/usr/bin/env bash
set -Eeuo pipefail
trap 'code=$?; echo "PRECHECK_STOPPED at line $LINENO (status $code); live website unchanged"; exit "$code"' ERR
site=/home/mjrmstlj/public_html
repo=/home/mjrmstlj/repositories/midwestmanagedit-website
approved=a2a5df9d2f0d0e0ad773a7758070af0f8cb93c29
test "$(id -un)" = mjrmstlj
test "$(readlink -f "$site")" = "$site"
test -d "$repo/.git"
files=(
  'about.html'
  'api/contact-request.php'
  'assets/css/site.css'
  'assets/js/site.js'
  'backup-recovery-readiness.html'
  'contact.html'
  'cybersecurity.html'
  'faq.html'
  'getting-started.html'
  'help-desk-support.html'
  'index.html'
  'it-review.html'
  'managed-it.html'
  'managed-vs-reactive-support.html'
  'microsoft-365-hygiene.html'
  'microsoft-365.html'
  'plans.html'
  'pricing-estimator.html'
  'privacy.html'
  'quick-answers.html'
  'resources.html'
  'schedule.html'
  'services.html'
  'technology-guidance.html'
  'terms.html'
  'thanks.html'
  'who-we-help.html'
)
git -C "$repo" fetch --quiet https://github.com/MjrMalfunk/midwestmanagedit-website.git contact-first-site-20261012
git -C "$repo" cat-file -e "$approved^{commit}"
stage=$(mktemp -d /home/mjrmstlj/mmit-contact-release.XXXXXX)
git -C "$repo" archive "$approved" "${files[@]}" | tar -x -C "$stage"
php -l "$stage/api/contact-request.php"
php -v | head -n 1
php -d display_errors=1 -d log_errors=0 <<'PHP'
<?php
echo "Runtime mode: " . PHP_SAPI . "\n";
require "/home/mjrmstlj/public_html/includes/private-config.php";
try { $config = mmit_load_secrets_config(); }
catch (Throwable $error) { echo "Production configuration could not load: " . $error->getMessage() . "\n"; exit(1); }
$key = (string)($config["brevo"]["api_key"] ?? "");
if ($key === "" || !function_exists("curl_init")) {
    echo "Brevo key or PHP cURL is unavailable.\n"; exit(1);
}
$request = curl_init("https://api.brevo.com/v3/account");
curl_setopt_array($request, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["api-key: " . $key, "accept: application/json"],
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
]);
$result = curl_exec($request);
$status = (int)curl_getinfo($request, CURLINFO_HTTP_CODE);
curl_close($request);
if ($result === false || $status !== 200) {
    echo "Brevo configuration check failed (HTTP " . $status . ").\n"; exit(1);
}
echo "PHP and Brevo configuration verified.\n";
PHP
mkdir -p /home/mjrmstlj/deploy-backups
backup=$(mktemp -d /home/mjrmstlj/deploy-backups/contact-20261013.XXXXXX)
mkdir -p "$backup/original"
for file in "${files[@]}"; do
    resolved=$(readlink -m "$site/$file")
    case "$resolved" in "$site"/*) ;; *) echo "Unsafe destination path"; exit 1 ;; esac
    if test -f "$site/$file"; then
        mkdir -p "$backup/original/$(dirname "$file")"
        cp -p -- "$site/$file" "$backup/original/$file"
    else
        printf '%s\n' "$file" >> "$backup/absent-before.txt"
    fi
done
rollback() {
    code=$?
    trap - ERR
    set +e
    for file in "${files[@]}"; do
        if test -f "$backup/original/$file"; then
            cp -p -- "$backup/original/$file" "$site/$file"
        elif test -f "$backup/absent-before.txt" && grep -Fxq -- "$file" "$backup/absent-before.txt"; then
            rm -f -- "$site/$file"
        fi
    done
    echo "The check failed; previous website files were restored. Backup: $backup"
    exit "$code"
}
trap rollback ERR
for file in "${files[@]}"; do
    mkdir -p "$site/$(dirname "$file")"
    install -m 0644 -- "$stage/$file" "$site/$file"
done
for file in "${files[@]}"; do cmp -s "$stage/$file" "$site/$file"; done
endpoint=https://midwestmanagedit.com/api/contact-request.php
status=$(curl -sS --max-time 30 -o "$stage/method-test.json" -w '%{http_code}' "$endpoint")
test "$status" = 405
status=$(curl -sS --max-time 30 -o "$stage/validation-test.json" -w '%{http_code}' -H 'Content-Type: application/json' -d '{}' "$endpoint")
test "$status" = 422
# Internal test goes only to MMIT's own inbox; no campaign enrollment occurs.
status=$(curl -sS --max-time 35 -o "$stage/contact-test.json" -w '%{http_code}' -H 'Content-Type: application/json' -d '{"name":"MMIT deployment check","email":"info@midwestmanagedit.com","topic":"Something else","message":"Internal verification of the approved contact form. This is a deployment test, not a customer inquiry.","source":"website_deployment_check","campaign":"internal_validation"}' "$endpoint")
test "$status" = 200
grep -q '"ok":true' "$stage/contact-test.json"
trap - ERR
printf 'CONTACT_DEPLOY_OK\nApproved source: %s\nBackup: %s\nForm test accepted by Brevo.\n' "$approved" "$backup"
