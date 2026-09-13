/*
 * Dashboard charts. Chart.js is loaded from the CDN allowed by the CSP.
 *
 * Every chart here is driven by a data attribute the server rendered, not by a
 * fetch: the page already has the numbers, and a chart that needs a second round
 * trip is a chart that sometimes never appears.
 */
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

  /* --------------------------------------------------------------- revenue */

  var revenueCanvas = document.getElementById('revenueChart');

  if (revenueCanvas && typeof Chart !== 'undefined') {
    var months = read(revenueCanvas, 'data-months');

    if (months.length === 0) {
      empty(revenueCanvas, 'Sales appear here once you have recorded some.');
    } else {
      new Chart(revenueCanvas, {
        type: 'bar',
        data: {
          labels: months.map(function (row) { return row.period; }),
          datasets: [
            {
              label: 'From email',
              data: months.map(function (row) { return Number(row.attributed_value); }),
              backgroundColor: '#1d4ed8',
              borderRadius: 4
            },
            {
              // Shown alongside, never instead of: "£8,400 of £31,000" is an
              // honest claim where "email generated £8,400" invites the reader
              // to think email did all the work.
              label: 'Everything else',
              data: months.map(function (row) {
                return Number(row.total_value) - Number(row.attributed_value);
              }),
              backgroundColor: '#cbd5e1',
              borderRadius: 4
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
          scales: {
            x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
            y: { stacked: true, beginAtZero: true, ticks: { font: { size: 11 } }, grid: { color: '#eef2f6' } }
          }
        }
      });
    }
  }

  /* ------------------------------------------------------- email over time */

  var sendCanvas = document.getElementById('sendChart');

  if (sendCanvas && typeof Chart !== 'undefined') {
    var days = read(sendCanvas, 'data-days');

    if (days.length === 0) {
      empty(sendCanvas, 'This fills in once you have sent something.');
    } else {
      new Chart(sendCanvas, {
        type: 'line',
        data: {
          labels: days.map(function (row) { return row.date; }),
          datasets: [
            {
              label: 'Arrived',
              data: days.map(function (row) { return Number(row.delivered); }),
              borderColor: '#059669',
              backgroundColor: 'rgba(5,150,105,0.08)',
              fill: true,
              tension: 0.3,
              pointRadius: 0
            },
            {
              label: 'Bounced',
              data: days.map(function (row) { return Number(row.bounced); }),
              borderColor: '#dc2626',
              tension: 0.3,
              pointRadius: 0
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
          scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 }, maxTicksLimit: 10 } },
            y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#eef2f6' } }
          }
        }
      });
    }
  }

  function read(element, attribute) {
    try {
      var parsed = JSON.parse(element.getAttribute(attribute) || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function empty(element, message) {
    element.parentElement.innerHTML =
      '<p class="muted small" style="margin:0">' + message + '</p>';
  }
})();
