(() => {
  const picker = document.getElementById('schedulerPicker');
  const daysWrap = document.getElementById('schedulerDays');
  const slotsWrap = document.getElementById('schedulerSlots');
  const timezoneLabel = document.getElementById('schedulerTimezoneLabel');
  const selectionSummary = document.getElementById('schedulerSelectionSummary');
  if (!picker || !daysWrap || !slotsWrap) return;
  const liveEndpoint = picker.dataset.liveEndpoint || 'api/scheduler/availability.php';
  const fallbackSource = picker.dataset.source || 'assets/data/scheduler-availability.json';
  const easternTimeZone = 'America/New_York';
  const state = { sourceMode: 'fallback', config: null, days: [], activeDate: '', activeIso: '', visitorTimeZone: detectVisitorTimeZone(), usingVisitorTimeZone: false };

  const fmtDay = (date, timeZone) => new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric', timeZone }).format(date);
  const fmtLongDay = (date, timeZone) => new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', timeZone }).format(date);
  const fmtTime = (value) => new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' }).format(new Date(`2000-01-01T${value}:00`));
  const fmtSlotTime = (date, timeZone) => new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', timeZone }).format(date);
  const tzLabel = (config) => (config && (config.timezone_label || config.timezone)) || 'Business local time';
  const setSummary = (text, filled = false) => {
    if (!selectionSummary) return;
    selectionSummary.textContent = text;
    selectionSummary.classList.toggle('is-filled', filled);
  };
  const dispatchSelection = (detail) => document.dispatchEvent(new CustomEvent('mmit:scheduler-slot-selected', { detail }));

  function detectVisitorTimeZone() {
    try {
      const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (!zone) return '';
      new Intl.DateTimeFormat('en-US', { timeZone: zone }).format(new Date());
      return zone;
    } catch (error) {
      return '';
    }
  }

  function hasExplicitTimeZone(value) {
    return /(?:Z|[+-]\d{2}:?\d{2})$/i.test(String(value || ''));
  }

  function dateIsValid(date) {
    return date instanceof Date && Number.isFinite(date.getTime());
  }

  function zonedParts(date, timeZone) {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
      hourCycle: 'h23'
    }).formatToParts(date);

    return parts.reduce((acc, part) => {
      if (part.type !== 'literal') acc[part.type] = Number(part.value);
      return acc;
    }, {});
  }

  function zonedDateKey(date, timeZone) {
    const parts = zonedParts(date, timeZone);
    const year = String(parts.year).padStart(4, '0');
    const month = String(parts.month).padStart(2, '0');
    const day = String(parts.day).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function dateFromZonedTime(isoDate, time, timeZone) {
    if (!isoDate || !time || !timeZone) return null;
    const dateBits = String(isoDate).split('-').map(Number);
    const timeBits = String(time).split(':').map(Number);
    if (dateBits.length < 3 || timeBits.length < 2 || dateBits.some(Number.isNaN) || timeBits.some(Number.isNaN)) return null;

    const desiredUtc = Date.UTC(dateBits[0], dateBits[1] - 1, dateBits[2], timeBits[0], timeBits[1], 0);
    let resolvedUtc = desiredUtc;

    try {
      for (let index = 0; index < 2; index += 1) {
        const parts = zonedParts(new Date(resolvedUtc), timeZone);
        const renderedUtc = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, 0);
        resolvedUtc += desiredUtc - renderedUtc;
      }
    } catch (error) {
      return null;
    }

    const resolved = new Date(resolvedUtc);
    return dateIsValid(resolved) ? resolved : null;
  }

  function slotDate(slot, day, config) {
    const rawIso = String(slot?.iso || '');
    if (hasExplicitTimeZone(rawIso)) {
      const parsed = new Date(rawIso);
      return dateIsValid(parsed) ? parsed : null;
    }

    const businessZone = (config && config.timezone) || easternTimeZone;
    const isoDate = day?.isoDate || rawIso.slice(0, 10);
    const time = slot?.time || rawIso.slice(11, 16);
    return dateFromZonedTime(isoDate, time, businessZone);
  }

  function localTimeZoneLabel(referenceDate = new Date()) {
    if (!state.usingVisitorTimeZone || !state.visitorTimeZone) {
      return tzLabel(state.config);
    }

    try {
      const zoneName = new Intl.DateTimeFormat('en-US', {
        timeZone: state.visitorTimeZone,
        timeZoneName: 'short'
      }).formatToParts(referenceDate).find((part) => part.type === 'timeZoneName')?.value;
      return zoneName ? `Your local time (${zoneName})` : 'Your local time';
    } catch (error) {
      return 'Your local time';
    }
  }

  function prepareDisplayDays(data) {
    const sourceDays = Array.isArray(data.days) ? data.days : [];
    if (!state.visitorTimeZone) {
      state.usingVisitorTimeZone = false;
      return sourceDays;
    }

    const grouped = new Map();
    for (const day of sourceDays) {
      const slots = Array.isArray(day.slots) ? day.slots : [];
      for (const slot of slots) {
        const parsedDate = slotDate(slot, day, data);
        if (!parsedDate) {
          state.usingVisitorTimeZone = false;
          return sourceDays;
        }

        const localDate = zonedDateKey(parsedDate, state.visitorTimeZone);
        if (!grouped.has(localDate)) {
          grouped.set(localDate, {
            isoDate: localDate,
            label: fmtDay(parsedDate, state.visitorTimeZone),
            longLabel: fmtLongDay(parsedDate, state.visitorTimeZone),
            slots: []
          });
        }

        grouped.get(localDate).slots.push({
          ...slot,
          label: fmtSlotTime(parsedDate, state.visitorTimeZone),
          displayTimestamp: parsedDate.getTime()
        });
      }
    }

    state.usingVisitorTimeZone = true;
    return Array.from(grouped.values()).map((day) => ({
      ...day,
      slots: day.slots.sort((a, b) => (a.displayTimestamp || 0) - (b.displayTimestamp || 0))
    }));
  }

  function firstDisplayDate() {
    for (const day of state.days) {
      const slot = Array.isArray(day.slots) ? day.slots.find((item) => item.displayTimestamp) : null;
      if (slot) return new Date(slot.displayTimestamp);
    }
    return new Date();
  }

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
    return { ok: true, configured: false, timezone: config.timezone || easternTimeZone, timezone_label: config.timezone_label || config.timezone || 'Eastern Time', days: results.slice(0, Number(config.max_bookable_days || 10)) };
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
        const slotTzLabel = localTimeZoneLabel(slot.displayTimestamp ? new Date(slot.displayTimestamp) : new Date());
        const summary = `${day.longLabel} at ${slot.label} ${slotTzLabel}`;
        setSummary(summary, true);
        dispatchSelection({ label: summary, iso: slot.iso, timezoneLabel: slotTzLabel, sourceMode: state.sourceMode });
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
        dispatchSelection({ label: '', iso: '', timezoneLabel: localTimeZoneLabel(firstDisplayDate()), sourceMode: state.sourceMode });
      });
      daysWrap.appendChild(button);
    });
    renderSlots();
  }

  loadData().then((data) => {
    state.config = data;
    state.activeDate = '';
    state.activeIso = '';
    state.days = prepareDisplayDays(data);
    picker.dataset.schedulerMode = state.sourceMode;
    picker.dataset.schedulerReady = state.days.length ? 'true' : 'false';
    if (timezoneLabel) timezoneLabel.textContent = localTimeZoneLabel(firstDisplayDate());
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