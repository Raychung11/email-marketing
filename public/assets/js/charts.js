/* Dashboard charts. Chart.js is loaded from the CDN allowed by the CSP. */
(function () {
  'use strict';

  var canvas = document.getElementById('growthChart');

  if (!canvas || typeof Chart === 'undefined') {
    return;
  }

  var raw = canvas.getAttribute('data-growth') || '[]';
  var series;

  try {
    series = JSON.parse(raw);
  } catch (e) {
    return;
  }

  if (!Array.isArray(series) || series.length === 0) {
    canvas.parentElement.innerHTML =
      '<p class="muted small" style="margin:0">Contact growth appears here once you have a month of data.</p>';
    return;
  }

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: series.map(function (row) { return row.period; }),
      datasets: [{
        label: 'New contacts',
        data: series.map(function (row) { return row.total; }),
        backgroundColor: '#1d4ed8',
        borderRadius: 4,
        maxBarThickness: 34
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#eef2f6' } }
      }
    }
  });
})();
