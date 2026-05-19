(() => {
  const root = document.documentElement;
  const body = document.body;
  const scrollLinks = document.querySelectorAll('a[href^="#"]');
  const form = document.getElementById('notifyForm');
  const nameInput = document.getElementById('name');
  const emailInput = document.getElementById('email');
  const companyInput = document.getElementById('company');
  const honeypotInput = document.getElementById('website');
  const startedAtInput = document.getElementById('formStartedAt');
  const captchaQuestion = document.getElementById('captchaQuestion');
  const captchaAnswerInput = document.getElementById('captchaAnswer');
  const formStatus = document.getElementById('formStatus');
  const scheduleForm = document.getElementById('scheduleForm');
  const scheduleStatus = document.getElementById('scheduleFormStatus');
  const scheduleName = document.getElementById('scheduleName');
  const scheduleCompany = document.getElementById('scheduleCompany');
  const scheduleEmail = document.getElementById('scheduleEmail');
  const schedulePhone = document.getElementById('schedulePhone');
  const scheduleTeamSize = document.getElementById('scheduleTeamSize');
  const scheduleInterest = document.getElementById('scheduleInterest');
  const scheduleSupportModel = document.getElementById('scheduleSupportModel');
  const scheduleBusinessProfile = document.getElementById('scheduleBusinessProfile');
  const schedulePlanFit = document.getElementById('schedulePlanFit');
  const scheduleContactMethod = document.getElementById('scheduleContactMethod');
  const schedulePainPoints = document.getElementById('schedulePainPoints');
  const scheduleAvailability = document.getElementById('scheduleAvailability');
  const scheduleRequestedSlot = document.getElementById('scheduleRequestedSlot');
  const scheduleRequestedSlotIso = document.getElementById('scheduleRequestedSlotIso');
  const navToggle = document.getElementById('navToggle');
  const mobileMenu = document.getElementById('mobileMenu');
  const mobileMenuLinks = document.querySelectorAll('.mobile-nav-links a, .mobile-menu-actions a');
  const estimatorCarryStorageKey = 'mmit-estimator-carry-v1';
  const scheduleStorageKey = 'mmit-schedule-intake-v1';
  const carryStorageTtlMs = 1000 * 60 * 60 * 4;
  let captchaTotal = 0;

  function storageGetJson(key) {
    try {
      const raw = window.sessionStorage.getItem(key);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (error) {
      return null;
    }
  }

  function storageSetJson(key, value) {
    try {
      window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch (error) {}
  }

  function storageRemove(key) {
    try {
      window.sessionStorage.removeItem(key);
    } catch (error) {}
  }

  function phoneDigits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function formatPhoneForDisplay(value) {
    const original = String(value || '').trim();
    let digits = phoneDigits(original);
    if (!digits) return '';
    if (digits.length === 11 && digits.startsWith('1')) digits = digits.slice(1);
    if (digits.length > 10) return original;
    if (digits.length <= 3) return digits;
    if (digits.length <= 6) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
    return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6, 10)}`;
  }

  function applyPhoneFormat(input) {
    if (!input) return;
    const formatted = formatPhoneForDisplay(input.value);
    if (formatted && formatted !== input.value) {
      input.value = formatted;
    }
  }

  function bindPhoneFormatter(input) {
    if (!input) return;
    const handle = () => applyPhoneFormat(input);
    input.addEventListener('input', handle);
    input.addEventListener('change', handle);
    input.addEventListener('blur', handle);
    applyPhoneFormat(input);
  }

  function freshSnapshot(snapshot) {
    if (!snapshot || !snapshot.savedAt) return null;
    if (Date.now() - Number(snapshot.savedAt) > carryStorageTtlMs) return null;
    return snapshot;
  }

  function getEstimatorCarrySnapshot() {
    const snapshot = freshSnapshot(storageGetJson(estimatorCarryStorageKey));
    if (!snapshot) storageRemove(estimatorCarryStorageKey);
    return snapshot;
  }

  function getScheduleSnapshot() {
    const snapshot = freshSnapshot(storageGetJson(scheduleStorageKey));
    if (!snapshot) storageRemove(scheduleStorageKey);
    return snapshot;
  }

  root.setAttribute('data-theme', 'executive-dark');

  function setNotifyStatus(message, type) {
    if (!formStatus) return;
    formStatus.textContent = message;
    formStatus.className = 'form-status ' + (type === 'success' ? 'is-success' : 'is-error');
  }

  function setScheduleStatus(message, type) {
    if (!scheduleStatus) return;
    scheduleStatus.textContent = message;
    scheduleStatus.className = 'form-status intake-status ' + (type === 'success' ? 'is-success' : 'is-error');
  }

  function openMenu() {
    if (!mobileMenu || !navToggle) return;
    body.classList.add('is-menu-open');
    navToggle.setAttribute('aria-expanded', 'true');
  }

  function closeMenu() {
    if (!mobileMenu || !navToggle) return;
    body.classList.remove('is-menu-open');
    navToggle.setAttribute('aria-expanded', 'false');
  }

  function bindRevealAnimations() {
    const targets = document.querySelectorAll(
      '.hero-shell, .trust-pill, .info-card, .content-block, .story-panel, .cta-band, .utility-banner, .accent-card, .proof-card, .package-card, .plan-callout-band'
    );
    if (!targets.length) return;
    targets.forEach((node) => node.setAttribute('data-reveal', ''));

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion || !('IntersectionObserver' in window)) {
      targets.forEach((node) => node.classList.add('is-visible'));
      return;
    }

    const observer = new IntersectionObserver(
      (entries, obs) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-visible');
          obs.unobserve(entry.target);
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -8% 0px' }
    );

    targets.forEach((node) => observer.observe(node));
  }

  if (navToggle && mobileMenu) {
    navToggle.addEventListener('click', () => {
      if (body.classList.contains('is-menu-open')) {
        closeMenu();
      } else {
        openMenu();
      }
    });

    mobileMenuLinks.forEach((link) => {
      link.addEventListener('click', () => closeMenu());
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeMenu();
      }
    });
  }

  scrollLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      const id = link.getAttribute('href');
      if (!id || id === '#' || id.startsWith('#') === false) return;
      const target = document.querySelector(id);
      if (!target) return;
      event.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      closeMenu();
      if (id === '#schedule-intake' && scheduleName) {
        window.setTimeout(() => scheduleName.focus(), 350);
      }
    });
  });

  function resetCaptcha() {
    if (!captchaQuestion || !captchaAnswerInput) return;
    const first = Math.floor(Math.random() * 6) + 2;
    const second = Math.floor(Math.random() * 6) + 2;
    captchaTotal = first + second;
    captchaQuestion.textContent = `What is ${first} + ${second}?`;
    captchaAnswerInput.value = '';
  }

  function markFormStarted() {
    if (startedAtInput && !startedAtInput.value) {
      startedAtInput.value = String(Date.now());
    }
  }

  if (form) {
    form.addEventListener('focusin', markFormStarted);
    form.addEventListener('pointerdown', markFormStarted, { once: true });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (formStatus) {
        formStatus.textContent = '';
        formStatus.className = 'form-status';
      }

      const name = nameInput ? nameInput.value.trim() : '';
      const email = emailInput ? emailInput.value.trim() : '';
      const company = companyInput ? companyInput.value.trim() : '';
      const honeypot = honeypotInput ? honeypotInput.value.trim() : '';
      const answer = Number(captchaAnswerInput ? captchaAnswerInput.value.trim() : '');
      const elapsedMs = Date.now() - Number(startedAtInput?.value || Date.now());

      if (honeypot !== '') {
        form.reset();
        if (startedAtInput) startedAtInput.value = '';
        resetCaptcha();
        return;
      }

      if (!name) {
        setNotifyStatus('Please enter your name.', 'error');
        nameInput?.focus();
        return;
      }

      if (!email || !emailInput || !emailInput.checkValidity()) {
        setNotifyStatus('Please enter a valid email address.', 'error');
        emailInput?.focus();
        return;
      }

      if (!startedAtInput || !startedAtInput.value || elapsedMs < 3500) {
        setNotifyStatus('Please take a moment and try again.', 'error');
        return;
      }

      if (!Number.isFinite(answer) || answer !== captchaTotal) {
        setNotifyStatus('Please answer the spam check correctly.', 'error');
        captchaAnswerInput?.focus();
        resetCaptcha();
        return;
      }

      try {
        const response = await fetch('/subscribe.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, email, company })
        });

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.message || 'Unable to submit form.');
        }

        setNotifyStatus(data.message || 'Almost done. Please check your inbox and confirm your email.', 'success');
        form.reset();
        if (startedAtInput) startedAtInput.value = '';
        resetCaptcha();
      } catch (error) {
        setNotifyStatus(error.message || 'Something went wrong. Please try again.', 'error');
      }
    });
  }

  function collectScheduleFields() {
    return {
      name: scheduleName ? scheduleName.value.trim() : '',
      company: scheduleCompany ? scheduleCompany.value.trim() : '',
      email: scheduleEmail ? scheduleEmail.value.trim() : '',
      phone: schedulePhone ? formatPhoneForDisplay(schedulePhone.value) : '',
      team_size: scheduleTeamSize ? scheduleTeamSize.value.trim() : '',
      interest: scheduleInterest ? scheduleInterest.value.trim() : '',
      support_model: scheduleSupportModel ? scheduleSupportModel.value.trim() : '',
      business_profile: scheduleBusinessProfile ? scheduleBusinessProfile.value.trim() : '',
      plan_fit: schedulePlanFit ? schedulePlanFit.value.trim() : '',
      contact_method: scheduleContactMethod ? scheduleContactMethod.value.trim() : '',
      pain_points: schedulePainPoints ? schedulePainPoints.value.trim() : '',
      availability: scheduleAvailability ? scheduleAvailability.value.trim() : '',
      requested_slot: scheduleRequestedSlot ? scheduleRequestedSlot.value.trim() : '',
      requested_slot_iso: scheduleRequestedSlotIso ? scheduleRequestedSlotIso.value.trim() : ''
    };
  }

  function saveScheduleSnapshot() {
    if (!scheduleForm) return;
    storageSetJson(scheduleStorageKey, {
      version: 1,
      savedAt: Date.now(),
      source: 'schedule-intake',
      fields: collectScheduleFields()
    });
  }

  document.addEventListener('mmit:scheduler-slot-selected', (event) => {
    const detail = event.detail || {};
    if (scheduleRequestedSlot) scheduleRequestedSlot.value = detail.label || '';
    if (scheduleRequestedSlotIso) scheduleRequestedSlotIso.value = detail.iso || '';
    saveScheduleSnapshot();
  });

  async function tryLiveBooking(values) {
    if (!scheduleForm || !values.requestedSlotIso) return false;
    const endpoint = scheduleForm.dataset.bookingEndpoint || 'api/scheduler/book.php';
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: values.name,
          company: values.company,
          email: values.email,
          phone: values.phone,
          teamSize: values.teamSize,
          interest: values.interest,
          supportModel: values.supportModel,
          businessProfile: values.businessProfile,
          planFit: values.planFit,
          contactMethod: values.contactMethod,
          painPoints: values.painPoints,
          availability: values.availability,
          requestedSlotLabel: values.requestedSlotLabel,
          requestedSlotIso: values.requestedSlotIso
        })
      });

      const data = await response.json().catch(() => ({}));
      if (response.ok && data && data.ok) {
        const slotText = data.slotLabel || values.requestedSlotLabel || 'the selected time';
        setScheduleStatus(`Booked. ${slotText} has been reserved on the MMIT calendar.`, 'success');
        scheduleForm.reset();
        if (scheduleRequestedSlot) scheduleRequestedSlot.value = '';
        if (scheduleRequestedSlotIso) scheduleRequestedSlotIso.value = '';
        const summary = document.getElementById('schedulerSelectionSummary');
        if (summary) {
          summary.textContent = 'No time selected yet.';
          summary.classList.remove('is-filled');
        }
        storageRemove(scheduleStorageKey);
        storageRemove(estimatorCarryStorageKey);
        const params = new URLSearchParams({
          mode: 'booking',
          slot: slotText,
          name: values.name,
          company: values.company,
          method: data.joinUrl ? 'teams' : 'calendar',
        });
        if (data.webLink) params.set('calendar', data.webLink);
        if (data.joinUrl) params.set('join', data.joinUrl);
        window.setTimeout(() => {
          window.location.href = `thanks.html?${params.toString()}`;
        }, 550);
        return true;
      }
    } catch (error) {
      // fall through to email fallback
    }
    return false;
  }

  bindPhoneFormatter(schedulePhone);

  if (scheduleForm) {
    scheduleForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      setScheduleStatus('', 'success');

      const values = {
        name: scheduleName ? scheduleName.value.trim() : '',
        company: scheduleCompany ? scheduleCompany.value.trim() : '',
        email: scheduleEmail ? scheduleEmail.value.trim() : '',
        phone: schedulePhone ? formatPhoneForDisplay(schedulePhone.value) : '',
        teamSize: scheduleTeamSize ? scheduleTeamSize.value.trim() : '',
        interest: scheduleInterest ? scheduleInterest.value.trim() : '',
        supportModel: scheduleSupportModel ? scheduleSupportModel.value.trim() : '',
        businessProfile: scheduleBusinessProfile ? scheduleBusinessProfile.value.trim() : '',
        planFit: schedulePlanFit ? schedulePlanFit.value.trim() : '',
        contactMethod: scheduleContactMethod ? scheduleContactMethod.value.trim() : '',
        painPoints: schedulePainPoints ? schedulePainPoints.value.trim() : '',
        availability: scheduleAvailability ? scheduleAvailability.value.trim() : '',
        requestedSlotLabel: scheduleRequestedSlot ? scheduleRequestedSlot.value.trim() : '',
        requestedSlotIso: scheduleRequestedSlotIso ? scheduleRequestedSlotIso.value.trim() : ''
      };

      if (!values.name) {
        setScheduleStatus('Please enter your name.', 'error');
        scheduleName?.focus();
        return;
      }

      if (!values.company) {
        setScheduleStatus('Please enter your company name.', 'error');
        scheduleCompany?.focus();
        return;
      }

      if (!values.email || !scheduleEmail || !scheduleEmail.checkValidity()) {
        setScheduleStatus('Please enter a valid email address.', 'error');
        scheduleEmail?.focus();
        return;
      }

      if (!values.interest) {
        setScheduleStatus('Please select your primary interest.', 'error');
        scheduleInterest?.focus();
        return;
      }

      if (!values.painPoints) {
        setScheduleStatus('Please describe the main friction point you want to address.', 'error');
        schedulePainPoints?.focus();
        return;
      }

      if (!values.requestedSlotIso) {
        setScheduleStatus('Please choose a time before you book.', 'error');
        document.getElementById('schedulerDays')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }

      const booked = await tryLiveBooking(values);
      if (booked) {
        return;
      }

      const subject = `Schedule a Chat Inquiry - ${values.company}`;
      const bodyLines = [
        'Hello Midwest Managed IT,',
        '',
        'I would like to schedule a chat.',
        '',
        `Name: ${values.name}`,
        `Company: ${values.company}`,
        `Email: ${values.email}`,
        `Phone: ${values.phone || 'Not provided'}`,
        `Approximate team size: ${values.teamSize || 'Not provided'}`,
        `Primary interest: ${values.interest}`,
        `Relationship lane: ${values.planFit || 'Not provided'}`,
        `Current support setup: ${values.supportModel || 'Not provided'}`,
        `Business profile: ${values.businessProfile || 'Not provided'}`,
        `Preferred contact path: ${values.contactMethod || 'Not provided'}`,
        `Requested slot: ${values.requestedSlotLabel || 'Not selected'}`,
        '',
        'Current friction points:',
        values.painPoints,
        '',
        'Anything else / alternate windows:',
        values.availability || 'Not provided',
        '',
        'Thank you.'
      ];

      const mailto = `mailto:info@midwestmanagedit.com?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(bodyLines.join('\n'))}`;
      window.location.href = mailto;
      saveScheduleSnapshot();
      setScheduleStatus('Live booking is not configured yet, so the page fell back to your email app with the selected slot and intake details prefilled.', 'success');
    });

    scheduleForm.addEventListener('input', saveScheduleSnapshot);
    scheduleForm.addEventListener('change', saveScheduleSnapshot);
  }

  function prefillScheduleFromParams() {
    if (!scheduleForm) return;
    const params = new URLSearchParams(window.location.search);
    const estimatorSnapshot = getEstimatorCarrySnapshot();
    const scheduleSnapshot = getScheduleSnapshot();
    const estimatorParams = estimatorSnapshot && estimatorSnapshot.params ? estimatorSnapshot.params : {};
    const savedFields = scheduleSnapshot && scheduleSnapshot.fields ? scheduleSnapshot.fields : {};
    const preferSaved = Number(scheduleSnapshot?.savedAt || 0) > Number(estimatorSnapshot?.savedAt || 0);

    const pick = (key) => {
      const fromUrl = params.get(key);
      if (fromUrl) return fromUrl;
      const primary = preferSaved ? savedFields[key] : estimatorParams[key];
      const secondary = preferSaved ? estimatorParams[key] : savedFields[key];
      return primary || secondary || '';
    };

    const name = pick('name');
    const company = pick('company');
    const email = pick('email');
    const phone = pick('phone');
    const planFit = pick('plan_fit');
    const teamSize = pick('team_size');
    const interest = pick('interest');
    const supportModel = pick('support_model');
    const businessProfile = pick('business_profile');
    const contactMethod = pick('contact_method');
    const painPoints = pick('pain_points');
    const availability = pick('availability');
    const estimateRange = pick('estimate_range');
    const requestedSlot = pick('requested_slot');
    const requestedSlotIso = pick('requested_slot_iso');

    if (name && scheduleName && !scheduleName.value) scheduleName.value = name;
    if (company && scheduleCompany && !scheduleCompany.value) scheduleCompany.value = company;
    if (email && scheduleEmail && !scheduleEmail.value) scheduleEmail.value = email;
    if (phone && schedulePhone && !schedulePhone.value) schedulePhone.value = formatPhoneForDisplay(phone);
    if (planFit && schedulePlanFit && !schedulePlanFit.value) schedulePlanFit.value = planFit;
    if (teamSize && scheduleTeamSize && !scheduleTeamSize.value) scheduleTeamSize.value = teamSize;
    if (interest && scheduleInterest && !scheduleInterest.value) scheduleInterest.value = interest;
    if (supportModel && scheduleSupportModel && !scheduleSupportModel.value) scheduleSupportModel.value = supportModel;
    if (businessProfile && scheduleBusinessProfile && !scheduleBusinessProfile.value) scheduleBusinessProfile.value = businessProfile;
    if (contactMethod && scheduleContactMethod && !scheduleContactMethod.value) scheduleContactMethod.value = contactMethod;
    if (requestedSlot && scheduleRequestedSlot && !scheduleRequestedSlot.value) scheduleRequestedSlot.value = requestedSlot;
    if (requestedSlotIso && scheduleRequestedSlotIso && !scheduleRequestedSlotIso.value) scheduleRequestedSlotIso.value = requestedSlotIso;
    if (painPoints && schedulePainPoints && !schedulePainPoints.value) {
      schedulePainPoints.value = painPoints;
    }
    if (scheduleAvailability && !scheduleAvailability.value) {
      const availabilityParts = [];
      if (availability) availabilityParts.push(availability);
      if (estimateRange && !String(availability || '').includes(estimateRange)) {
        availabilityParts.push(`Estimator planning range: ${estimateRange}`);
      }
      if (availabilityParts.length) {
        scheduleAvailability.value = availabilityParts.join('\n');
      }
    }
    saveScheduleSnapshot();
  }

  resetCaptcha();
  prefillScheduleFromParams();
  bindRevealAnimations();

  if (window.location.hash === '#schedule-intake' && scheduleName) {
    window.setTimeout(() => scheduleName.focus(), 450);
  }
})();
