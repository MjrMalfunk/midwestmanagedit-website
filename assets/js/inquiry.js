(() => {
  'use strict';
  const form = document.getElementById('inquiryForm');
  if (!form) return;
  const button = document.getElementById('inquirySubmit');
  const status = document.getElementById('inquiryStatus');
  let token = '', requestId = '', busy = false;
  const show = (message, error = false) => {
    status.textContent = message;
    status.classList.toggle('is-error', error);
  };
  // Carry estimate context without putting contact details into URLs or analytics.
  try {
    const carry = JSON.parse(sessionStorage.getItem('mmit-estimator-carry-v1') || 'null');
    if (carry && Date.now() - Number(carry.savedAt) < 4 * 3600000) {
      const f = carry.params || {};
      ['name', 'company', 'email', 'phone'].forEach(key => {
        if (typeof f[key] === 'string') form.elements[key].value = f[key];
      });
      form.elements.message.value = [f.plan_fit, f.estimate_range, f.pain_points]
        .filter(value => typeof value === 'string').join(' — ').slice(0, 5000);
    }
    if (new URLSearchParams(location.search).get('source') === 'it-review' && !form.elements.message.value) {
      form.elements.message.value = 'I would like an IT & Backup Readiness Review for my business. ';
    }
  } catch (_) { /* Form works without session storage. */ }
  async function prepare() {
    button.disabled = true; token = '';
    try {
      const res = await fetch('inquiry.php', {credentials: 'same-origin', cache: 'no-store'});
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || 'Please email us directly while the form is unavailable.');
      token = data.token; requestId = data.request_id;
      show('Ready when you are.'); button.disabled = false;
    } catch (error) {
      show(error.message || 'The form could not load. Please refresh or email us directly.', true);
    }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !token || !form.reportValidity()) return;
    busy = true; button.disabled = true; button.textContent = 'Sending…'; show('Sending your inquiry…');
    try {
      const res = await fetch('inquiry.php', {
        method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...Object.fromEntries(new FormData(form)), token, request_id: requestId})
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        if (res.status === 403) {
          await prepare();
          throw new Error('Your form session expired. Your text is still here; please send it again.');
        }
        throw new Error(data.message || 'We couldn’t confirm receipt. Please try again or email us directly.');
      }
      form.hidden = true;
      const success = document.getElementById('inquirySuccess');
      document.getElementById('inquiryReference').textContent = 'Your reference: ' + data.reference;
      success.hidden = false; success.focus();
    } catch (error) {
      show(error.message || 'We couldn’t confirm receipt. Please try again or email us directly.', true);
    } finally {
      busy = false; button.disabled = !token; button.textContent = 'Send my inquiry ↗';
    }
  });
  prepare();
})();
