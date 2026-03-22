/**
 * zurich-sailing.ch Analytics Tracker
 * Cookielos, datenschutzkonform (DSG)
 * Einbinden via: <script src="https://stats.zurich-sailing.ch/tracker.js" defer></script>
 */
(function () {
  'use strict';

  var ENDPOINT = 'https://stats.zurich-sailing.ch/collect.php';

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

  var viewId    = genId();
  var startTime = Date.now();
  var activeTime = 0;
  var lastActive = Date.now();
  var isHidden   = document.hidden;

  // Pageview senden
  send({
    type   : 'pageview',
    view_id: viewId,
    url    : window.location.href.split('#')[0],
    title  : document.title,
    ref    : document.referrer,
    device : getDevice(),
    width  : screen.width,
    lang   : navigator.language,
  });

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
