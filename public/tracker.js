/**
 * Clubdesk Analytics Tracker
 * Cookieless, privacy-friendly (DSG/DSGVO)
 * Usage: <script src="https://stats.YOUR-DOMAIN.COM/tracker.js" defer></script>
 */
(function () {
  'use strict';

  var script = document.currentScript ||
    document.querySelector('script[src*="tracker.js"]');
  var ENDPOINT = script
    ? script.src.replace('tracker.js', 'collect.php')
    : '/collect.php';

  // Clubdesk-Editor nicht tracken
  if (window.location.search.indexOf('edit=') !== -1) return;

  // Zufällige View-ID für Verweildauer-Tracking
  function genId() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  function getDevice() {
    var ua = navigator.userAgent;
    if (/tablet|ipad|playbook|silk/i.test(ua)) return 'tablet';
    if (/mobile|iphone|ipod|android|blackberry|windows phone/i.test(ua)) return 'mobile';
    return 'desktop';
  }

  function send(data) {
    var params = new URLSearchParams({ d: JSON.stringify(data) });
    if (navigator.sendBeacon) {
      navigator.sendBeacon(ENDPOINT, params);
    } else {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ENDPOINT, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.send(params.toString());
    }
  }

  // Erkennt Beitragsseiten anhand des ?b= Parameters
  function hasBeitragParam(url) {
    return /[?&]b=\d+/.test(url);
  }

  // Beitragstitel aus DOM lesen (Clubdesk rendert Titel als <h1>)
  function extractBeitragTitle() {
    var selectors = ['h1', 'h2'];
    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el && el.innerText.trim()) return el.innerText.trim();
    }
    return document.title;
  }

  var viewId    = genId();
  var startTime = Date.now();
  var activeTime = 0;
  var lastActive = Date.now();
  var isHidden   = document.hidden;

  var initialUrl = window.location.href.split('#')[0];

  // Pageview senden – bei Beitragsseiten verzögert, damit CMS Zeit zum Rendern hat
  if (hasBeitragParam(initialUrl)) {
    setTimeout(function () {
      send({
        type   : 'pageview',
        view_id: viewId,
        url    : window.location.href.split('#')[0],
        title  : extractBeitragTitle(),
        ref    : document.referrer,
        device : getDevice(),
        width  : screen.width,
        lang   : navigator.language,
      });
    }, 1500);
  } else {
    send({
      type   : 'pageview',
      view_id: viewId,
      url    : initialUrl,
      title  : document.title,
      ref    : document.referrer,
      device : getDevice(),
      width  : screen.width,
      lang   : navigator.language,
    });
  }

  // URL-Polling für Client-side Navigation (z.B. Beitrag ohne Page Reload öffnen)
  var lastTrackedUrl = initialUrl;
  setInterval(function () {
    var currentUrl = window.location.href.split('#')[0];
    if (currentUrl === lastTrackedUrl) return;
    lastTrackedUrl = currentUrl;

    if (hasBeitragParam(currentUrl)) {
      var newViewId = genId();
      viewId     = newViewId;
      startTime  = Date.now();
      activeTime = 0;
      lastActive = Date.now();

      setTimeout(function () {
        send({
          type   : 'pageview',
          view_id: newViewId,
          url    : currentUrl,
          title  : extractBeitragTitle(),
          ref    : lastTrackedUrl,
          device : getDevice(),
          width  : screen.width,
          lang   : navigator.language,
        });
      }, 1000);
    }
  }, 500);

  // Sichtbarkeit tracken (Tab-Wechsel etc.)
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      activeTime += Date.now() - lastActive;
      isHidden = true;
    } else {
      lastActive = Date.now();
      isHidden = false;
    }
  });

  // Verweildauer beim Verlassen senden
  window.addEventListener('pagehide', function () {
    if (!isHidden) activeTime += Date.now() - lastActive;
    var seconds = Math.round(activeTime / 1000);
    if (seconds > 0 && seconds < 3600) {
      send({ type: 'duration', view_id: viewId, duration: seconds });
    }
  });

  // Externe Links tracken
  document.addEventListener('click', function (e) {
    var el = e.target.closest('a[href]');
    if (!el) return;
    try {
      var href = el.href;
      var target = new URL(href);
      if (
        target.hostname !== window.location.hostname &&
        (target.protocol === 'http:' || target.protocol === 'https:')
      ) {
        send({
          type : 'event',
          event: 'outbound_link',
          value: href,
          url  : window.location.href.split('#')[0],
        });
      }
    } catch (err) {}
  });
})();
