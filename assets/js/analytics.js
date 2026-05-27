(function () {
  var PRODUCTION_HOSTS = ['midwestmanagedit.com', 'www.midwestmanagedit.com'];
  var MEASUREMENT_ID = 'G-QM0DZD87NJ';
  var hostname = window.location.hostname;

  if (PRODUCTION_HOSTS.indexOf(hostname) === -1) {
    return;
  }

  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () {
    window.dataLayer.push(arguments);
  };

  window.gtag('js', new Date());
  window.gtag('config', MEASUREMENT_ID);

  var script = document.createElement('script');
  script.async = true;
  script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(MEASUREMENT_ID);
  document.head.appendChild(script);
})();
