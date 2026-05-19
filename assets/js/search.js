(() => {
  const page = document.querySelector('[data-search-page="true"]');
  if (!page) return;

  const form = document.getElementById('quickAnswersForm');
  const input = document.getElementById('quickAnswersInput');
  const results = document.getElementById('quickAnswersResults');
  const count = document.getElementById('quickAnswersCount');
  const reset = document.getElementById('quickAnswersReset');
  const filterButtons = document.querySelectorAll('.filter-pill');

  const params = new URLSearchParams(window.location.search);
  let activeCategory = params.get('category') || 'all';
  let index = [];

  function normalise(value) {
    return (value || '').toLowerCase().replace(/[^a-z0-9\s&+-]/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function tokenise(value) {
    return normalise(value).split(' ').filter(Boolean);
  }

  function setActiveFilter(category) {
    activeCategory = category;
    filterButtons.forEach((button) => {
      button.classList.toggle('is-active', button.dataset.category === category);
    });
  }

  function scoreItem(item, query) {
    if (!query) return 1;
    const tokens = tokenise(query);
    if (!tokens.length) return 1;
    const title = normalise(item.title);
    const summary = normalise(item.summary);
    const keywords = normalise((item.keywords || []).join(' '));
    let score = 0;

    for (const token of tokens) {
      let tokenScore = 0;
      if (title.includes(token)) tokenScore += 5;
      if (summary.includes(token)) tokenScore += 3;
      if (keywords.includes(token)) tokenScore += 2;
      if (tokenScore === 0) return 0;
      score += tokenScore;
    }

    if (title.includes(normalise(query))) score += 4;
    return score;
  }

  function formatCategory(category) {
    const map = {
      services: 'Service',
      plans: 'Plan / fit',
      fit: 'Best fit',
      process: 'Getting started',
      faq: 'FAQ',
      guides: 'Guide',
      about: 'Why MMIT',
      contact: 'Contact'
    };
    return map[category] || 'Page';
  }

  function render(items, query) {
    const working = items
      .filter((item) => activeCategory === 'all' || item.category === activeCategory)
      .map((item) => ({ ...item, score: scoreItem(item, query) }))
      .filter((item) => item.score > 0)
      .sort((a, b) => b.score - a.score || a.title.localeCompare(b.title));

    count.textContent = `${working.length} result${working.length === 1 ? '' : 's'}${query ? ` for “${query}”` : ''}`;

    if (!working.length) {
      results.innerHTML = `
        <article class="empty-search-state card card-pad">
          <div class="eyebrow">No direct match yet</div>
          <h3>Try a broader term or switch the filter.</h3>
          <p>Useful starting searches: backup, Microsoft 365, remote support, plans, onboarding, cybersecurity, or break-fix.</p>
          <div class="hero-actions" style="margin-top:16px">
            <a class="btn btn-secondary" href="faq.html">Browse FAQ</a>
            <a class="btn btn-ghost" href="contact.html#schedule-intake">Schedule a Chat</a>
          </div>
        </article>`;
      return;
    }

    results.innerHTML = working.map((item) => `
      <article class="search-result-card info-card">
        <div class="search-result-meta">${formatCategory(item.category)}</div>
        <h3>${item.title}</h3>
        <p>${item.summary}</p>
        <a class="card-link" href="${item.url}">Open this page →</a>
      </article>`).join('');
  }

  async function loadIndex() {
    try {
      const response = await fetch('assets/data/search-index.json', { cache: 'no-store' });
      if (!response.ok) throw new Error('Unable to load search index.');
      index = await response.json();
      const query = params.get('q') || '';
      input.value = query;
      setActiveFilter(activeCategory);
      render(index, query);
      if (query) {
        window.setTimeout(() => input.focus(), 200);
      }
    } catch (error) {
      count.textContent = 'Search is temporarily unavailable.';
      results.innerHTML = `
        <article class="empty-search-state card card-pad">
          <div class="eyebrow">Search unavailable</div>
          <h3>The quick-answer index could not load.</h3>
          <p>You can still use the site normally through Services, Plans, FAQ, Resources, and Contact.</p>
        </article>`;
    }
  }

  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    const query = input.value.trim();
    const next = new URLSearchParams();
    if (query) next.set('q', query);
    if (activeCategory !== 'all') next.set('category', activeCategory);
    const nextUrl = `${window.location.pathname}${next.toString() ? `?${next.toString()}` : ''}`;
    window.history.replaceState({}, '', nextUrl);
    render(index, query);
  });

  filterButtons.forEach((button) => {
    button.addEventListener('click', () => {
      setActiveFilter(button.dataset.category || 'all');
      const query = input.value.trim();
      const next = new URLSearchParams();
      if (query) next.set('q', query);
      if (activeCategory !== 'all') next.set('category', activeCategory);
      const nextUrl = `${window.location.pathname}${next.toString() ? `?${next.toString()}` : ''}`;
      window.history.replaceState({}, '', nextUrl);
      render(index, query);
    });
  });

  reset?.addEventListener('click', () => {
    input.value = '';
    setActiveFilter('all');
    window.history.replaceState({}, '', window.location.pathname);
    render(index, '');
    input.focus();
  });

  loadIndex();
})();