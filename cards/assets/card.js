(function () {
  function qs(selector) { return document.querySelector(selector); }

  const shareButton = qs('[data-share-card]');
  const copyButtons = document.querySelectorAll('[data-copy]');
  const saveAutoLink = qs('[data-vcf-link]');

  if (shareButton && navigator.share) {
    shareButton.hidden = false;
    shareButton.addEventListener('click', function () {
      navigator.share({
        title: document.title,
        text: shareButton.getAttribute('data-share-text') || 'Digital business card',
        url: window.location.href.split('?')[0]
      }).catch(function () {});
    });
  }

  copyButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      const value = button.getAttribute('data-copy');
      if (!value || !navigator.clipboard) return;
      navigator.clipboard.writeText(value).then(function () {
        const oldText = button.querySelector('[data-label]')?.textContent || button.textContent;
        const label = button.querySelector('[data-label]');
        if (label) label.textContent = 'Copied';
        setTimeout(function () {
          if (label) label.textContent = oldText;
        }, 1200);
      }).catch(function () {});
    });
  });

  // Optional behavior: add ?save=1 to a card URL to nudge the vCard download once.
  const params = new URLSearchParams(window.location.search);
  if (params.get('save') === '1' && saveAutoLink) {
    setTimeout(function () {
      saveAutoLink.click();
    }, 650);
  }
})();
