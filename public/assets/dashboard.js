var ZSDash = (function () {
  'use strict';

  var NAVY   = '#0A2342';
  var BLUE   = '#2196F3';
  var BLUE_L = '#64B5F6';

  function initLineChart(labels, pvData, uvData) {
    var ctx = document.getElementById('lineChart');
    if (!ctx) return;
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Seitenaufrufe',
            data: pvData,
            borderColor: NAVY,
            backgroundColor: 'rgba(10,35,66,.08)',
            borderWidth: 2,
            pointRadius: labels.length > 14 ? 2 : 4,
            fill: true,
            tension: 0.3,
          },
          {
            label: 'Unique Visitors',
            data: uvData,
            borderColor: BLUE,
            backgroundColor: 'rgba(33,150,243,.08)',
            borderWidth: 2,
            pointRadius: labels.length > 14 ? 2 : 4,
            fill: true,
            tension: 0.3,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            position: 'top',
            labels: { font: { size: 12 }, boxWidth: 12, padding: 16 },
          },
          tooltip: { callbacks: {} },
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 11 }, maxTicksLimit: 10 },
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,.05)' },
            ticks: { font: { size: 11 }, precision: 0 },
          },
        },
      },
    });
  }

  function initDoughnutChart(id, labels, data, colors) {
    var ctx = document.getElementById(id);
    if (!ctx || !labels.length) return;
    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          backgroundColor: colors || [NAVY, BLUE, BLUE_L],
          borderWidth: 2,
          borderColor: '#fff',
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                var pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
              },
            },
          },
        },
      },
    });
  }

  function initUptimeChart(labels, datasets, datasetLabels) {
    var ctx = document.getElementById('uptimeChart');
    if (!ctx || !datasets.length) return;
    var colors = [NAVY, BLUE, BLUE_L, '#FF9800'];
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: datasets.map(function (data, i) {
          return {
            label: datasetLabels[i] || ('Ziel ' + (i + 1)),
            data: data,
            borderColor: colors[i % colors.length],
            borderWidth: 2,
            pointRadius: 3,
            fill: false,
            tension: 0.3,
            spanGaps: true,
          };
        }),
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            position: 'top',
            labels: { font: { size: 12 }, boxWidth: 12, padding: 16 },
          },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + ' ms';
              },
            },
          },
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 11 }, maxTicksLimit: 12 },
          },
          y: {
            beginAtZero: true,
            title: { display: true, text: 'Antwortzeit (ms)', font: { size: 11 } },
            grid: { color: 'rgba(0,0,0,.05)' },
            ticks: { font: { size: 11 }, precision: 0 },
          },
        },
      },
    });
  }

  // Tab-Umschalter fuer beliebig viele Karten (ohne Neuladen).
  // Buttons: data-tab-group="pages" data-tab-target="top"
  // Panels:  id="pages-top"  (= Gruppe + '-' + Ziel)
  function initTabs() {
    var btns = document.querySelectorAll('[data-tab-group]');
    if (!btns.length) return;
    var groups = {};
    btns.forEach(function (b) {
      var g = b.getAttribute('data-tab-group');
      (groups[g] = groups[g] || []).push(b);
    });
    Object.keys(groups).forEach(function (g) {
      groups[g].forEach(function (btn) {
        btn.addEventListener('click', function () {
          groups[g].forEach(function (b) {
            var on = b === btn;
            b.classList.toggle('active', on);
            var panel = document.getElementById(g + '-' + b.getAttribute('data-tab-target'));
            if (panel) panel.hidden = !on;
          });
        });
      });
    });
  }

  // --- Beitrags-Auswertung (beitraege.php) ---

  // Median-Aufrufe je Wochentag. Werte unter der Mustergrenze kommen als null
  // an und erzeugen bewusst keinen Balken (siehe beitraege.php).
  function initWeekdayChart(cfg) {
    var ctx = document.getElementById('weekdayChart');
    if (!ctx || !cfg || !cfg.labels.length) return;
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: cfg.labels,
        datasets: [{
          label: 'Median Aufrufe in 7 Tagen',
          data: cfg.data,
          backgroundColor: BLUE,
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, grid: { color: '#EDF2F7' } },
          x: { grid: { display: false } },
        },
      },
    });
  }

  // Lebenszyklus: Anteil je Tag als Balken, kumulierte Kurve auf zweiter Achse.
  function initLifecycleChart(cfg) {
    var ctx = document.getElementById('lifecycleChart');
    if (!ctx || !cfg || !cfg.labels.length) return;
    new Chart(ctx, {
      data: {
        labels: cfg.labels,
        datasets: [
          {
            type: 'bar', label: 'Anteil der Aufrufe', data: cfg.share,
            backgroundColor: BLUE_L, borderRadius: 4, yAxisID: 'y',
          },
          {
            type: 'line', label: 'kumuliert', data: cfg.cum,
            borderColor: NAVY, backgroundColor: 'transparent',
            tension: 0.3, pointRadius: 2, yAxisID: 'y1',
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
          tooltip: {
            callbacks: {
              label: function (c) { return c.dataset.label + ': ' + c.parsed.y + ' %'; },
            },
          },
        },
        scales: {
          y: {
            beginAtZero: true, grid: { color: '#EDF2F7' },
            ticks: { callback: function (v) { return v + ' %'; } },
          },
          y1: {
            beginAtZero: true, max: 100, position: 'right', grid: { display: false },
            ticks: { callback: function (v) { return v + ' %'; } },
          },
          x: { grid: { display: false } },
        },
      },
    });
  }

  return {
    init: function (labels, pvData, uvData, deviceLabels, deviceData) {
      initLineChart(labels, pvData, uvData);
      initDoughnutChart('deviceChart', deviceLabels, deviceData);
      initTabs();
    },
    initUptime: function (labels, datasets, datasetLabels) {
      initUptimeChart(labels, datasets, datasetLabels);
    },
    initBeitraege: function (cfg) {
      initWeekdayChart(cfg.weekday);
      initLifecycleChart(cfg.lifecycle);
      initDoughnutChart('refChart', cfg.referrer.labels, cfg.referrer.data, cfg.referrer.colors);
      initTabs();
    },
  };
})();
