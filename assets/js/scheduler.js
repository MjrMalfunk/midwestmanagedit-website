(() => {
  const picker = document.getElementById('schedulerPicker');
  const daysWrap = document.getElementById('schedulerDays');
  const slotsWrap = document.getElementById('schedulerSlots');
  const timezoneLabel = document.getElementById('schedulerTimezoneLabel');
  const selectionSummary = document.getElementById('schedulerSelectionSummary');
  if (!picker || !daysWrap || !slotsWrap) return;
  const liveEndpoint = picker.dataset.liveEndpoint || 'api/scheduler/availability.php';
  const fallbackSource = picker.dataset.source || 'assets/data/scheduler-availability.json';
  const state = { sourceMode: 'fallback', config: null, days: [], activeDate: '', activeIso: '' };

  const fmtDay = (date) => new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric' }).format(date);
  const fmtLongDay = (date) => new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric' }).format(date);
  const fmtTime = (value) => new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' }).format(new Date(`2000-01-01T${value}:00`));
  const tzLabel = (config) => (config && (config.timezone_label || config.timezone)) || 'Business local time';
  const setSummary = (text, filled = false) => {
    if (!selectionSummary) return;
    selectionSummary.textContent = text;
    selectionSummary.classList.toggle('is-filled', filled);
  };
  const dispatchSelection = (detail) => document.dispatchEvent(new CustomEvent('mmit:scheduler-slot-selected', { detail }));

  function normalizeFallback(config) {
    const lookahead = Number(config.lookahead_days || 21);
    const businessHours = config.business_hours || {};
    const blackout = new Set(config.blackout_dates || []);
    const results = [];
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    for (let offset = 0; offset < lookahead; offset += 1) {
      const date = new Date(today);
      date.setDate(today.getDate() + offset);
      const isoDate = date.toISOString().slice(0, 10);
      const weekday = String(date.getDay());
      const slots = Array.isArray(businessHours[weekday]) ? businessHours[weekday] : [];
      if (blackout.has(isoDate) || slots.length === 0) continue;
      results.push({ isoDate, label: fmtDay(date), longLabel: fmtLongDay(date), slots: slots.map((time) => ({ time, label: fmtTime(time), iso: `${isoDate}T${time}` })) });
    }
    return { ok: true, configured: false, timezone_label: config.timezone_label || config.timezone || 'Eastern Time', days: results.slice(0, Number(config.max_bookable_days || 10)) };
  }

  async function loadData() {
    try {
      const liveResponse = await fetch(`${liveEndpoint}?days=14`, { cache: 'no-store' });
      const liveData = await liveResponse.json();
      if (liveResponse.ok && liveData && Array.isArray(liveData.days) && liveData.days.length) {
        state.sourceMode = 'live';
        return liveData;
      }
    } catch (error) {}
    const fallbackResponse = await fetch(fallbackSource, { cache: 'no-store' });
    if (!fallbackResponse.ok) throw new Error('Unable to load scheduler data.');
    state.sourceMode = 'fallback';
    return normalizeFallback(await fallbackResponse.json());
  }

  function renderSlots() {
    const day = state.days.find((item) => item.isoDate === state.activeDate);
    slotsWrap.innerHTML = '';
    if (!day) { slotsWrap.innerHTML = '<p class="muted">Choose a day to see available times.</p>'; return; }
    if (!day.slots.length) { slotsWrap.innerHTML = '<p class="muted">No times are open on that day.</p>'; return; }
    day.slots.forEach((slot) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'scheduler-slot-button';
      button.textContent = slot.label;
      if (state.activeIso === slot.iso) button.classList.add('is-active');
      button.addEventListener('click', () => {
        state.activeIso = slot.iso;
        renderSlots();
        const summary = `${day.longLabel} at ${slot.label} ${tzLabel(state.config)}`;
        setSummary(summary, true);
        dispatchSelection({ label: summary, iso: slot.iso, timezoneLabel: tzLabel(state.config), sourceMode: state.sourceMode });
      });
      slotsWrap.appendChild(button);
    });
  }

  function renderDays() {
    daysWrap.innerHTML = '';
    state.days.forEach((day, index) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'scheduler-day-button';
      if (state.activeDate === day.isoDate || (!state.activeDate && index === 0)) {
        if (!state.activeDate) state.activeDate = day.isoDate;
        button.classList.add('is-active');
      }
      const strong = document.createElement('strong');
      strong.textContent = day.label;
      const span = document.createElement('span');
      span.textContent = `${day.slots.length} ${day.slots.length === 1 ? 'slot' : 'slots'}`;
      button.append(strong, span);
      button.addEventListener('click', () => {
        state.activeDate = day.isoDate;
        state.activeIso = '';
        renderDays();
        renderSlots();
        setSummary('Choose a time to continue.', false);
        dispatchSelection({ label: '', iso: '', timezoneLabel: tzLabel(state.config), sourceMode: state.sourceMode });
      });
      daysWrap.appendChild(button);
    });
    renderSlots();
  }

  loadData().then((data) => {
    state.config = data;
    state.days = Array.isArray(data.days) ? data.days : [];
    picker.dataset.schedulerMode = state.sourceMode;
    picker.dataset.schedulerReady = state.days.length ? 'true' : 'false';
    if (timezoneLabel) timezoneLabel.textContent = tzLabel(data);
    if (!state.days.length) {
      daysWrap.innerHTML = '<p class="muted">No available windows are listed right now. Send the form and we\'ll follow up directly with next steps.</p>';
      setSummary('No time selected yet.', false);
      return;
    }
    renderDays();
    setSummary(state.sourceMode === 'live' ? 'Choose a live opening to book instantly.' : 'Choose a preferred slot to include with your intake.', false);
  }).catch(() => {
    picker.dataset.schedulerReady = 'false';
    daysWrap.innerHTML = '<p class="muted">Available times could not load right now. Send the form and we\'ll follow up directly.</p>';
    setSummary('No time selected yet.', false);
  });
})();
