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

  function initDeviceChart(labels, data) {
    var ctx = document.getElementById('deviceChart');
    if (!ctx || !labels.length) return;
    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          backgroundColor: [NAVY, BLUE, BLUE_L],
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

  function initUptimeChart(labels, zsData, cdData) {
    var ctx = document.getElementById('uptimeChart');
    if (!ctx) return;
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'zurich-sailing.ch',
            data: zsData,
            borderColor: NAVY,
            backgroundColor: 'rgba(10,35,66,.08)',
            borderWidth: 2,
            pointRadius: 3,
            fill: false,
            tension: 0.3,
            spanGaps: true,
          },
          {
            label: 'app.clubdesk.com',
            data: cdData,
            borderColor: BLUE,
            backgroundColor: 'rgba(33,150,243,.08)',
            borderWidth: 2,
            pointRadius: 3,
            fill: false,
            tension: 0.3,
            spanGaps: true,
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

  return {
    init: function (labels, pvData, uvData, deviceLabels, deviceData) {
      initLineChart(labels, pvData, uvData);
      initDeviceChart(deviceLabels, deviceData);
    },
    initUptime: function (labels, zsData, cdData) {
      initUptimeChart(labels, zsData, cdData);
    },
  };
})();
