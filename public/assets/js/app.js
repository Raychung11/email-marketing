/*
 * Application JavaScript.
 *
 * Vanilla, no framework, no build step. Progressive enhancement only: every screen
 * works with JavaScript disabled, because a marketing tool that breaks silently is
 * worse than one that is plain.
 *
 * No secret ever appears here. API keys live server-side; this file talks only to
 * this application's own endpoints, with the CSRF token from the page.
 */
(function () {
  'use strict';

  function token() {
    var el = document.querySelector('input[name="_token"]');
    return el ? el.value : '';
  }

  /* ------------------------------------------------------ confirm dangerous acts */

  document.addEventListener('submit', function (event) {
    var form = event.target;

    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    var message = form.getAttribute('data-confirm');

    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

  /* --------------------------------------------------- auto-submit filter forms */

  Array.prototype.forEach.call(document.querySelectorAll('[data-auto-submit]'), function (el) {
    el.addEventListener('change', function () {
      var form = el.closest('form');
      if (form) { form.submit(); }
    });
  });

  /* ------------------------------------------------------------- segment builder */

  var builder = document.getElementById('segmentBuilder');

  if (builder) {
    initSegmentBuilder(builder);
  }

  function initSegmentBuilder(root) {
    var fields = JSON.parse(root.getAttribute('data-fields') || '{}');
    var operatorsByType = JSON.parse(root.getAttribute('data-operators') || '{}');
    var choices = JSON.parse(root.getAttribute('data-choices') || '{}');
    var rowsHost = root.querySelector('[data-rows]');
    var hidden = document.getElementById('definitionInput');
    var matchSelect = root.querySelector('[data-match]');
    var preview = document.getElementById('segmentPreview');

    var initial = JSON.parse(root.getAttribute('data-definition') || 'null');

    function fieldType(key) {
      return (fields[key] && fields[key].type) || 'string';
    }

    function operatorsFor(key) {
      return operatorsByType[fieldType(key)] || ['equals'];
    }

    function humanise(value) {
      return String(value).replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); });
    }

    function addRow(rule) {
      var row = document.createElement('div');
      row.className = 'rule-row';

      var fieldSelect = document.createElement('select');
      Object.keys(fields).forEach(function (key) {
        var option = document.createElement('option');
        option.value = key;
        option.textContent = fields[key].label || key;
        fieldSelect.appendChild(option);
      });
      fieldSelect.value = (rule && rule.field) || Object.keys(fields)[0];

      var opSelect = document.createElement('select');

      var valueHost = document.createElement('div');

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn--sm btn--danger';
      remove.textContent = 'Remove';
      remove.addEventListener('click', function () {
        row.remove();
        sync();
      });

      function renderOperators() {
        opSelect.innerHTML = '';
        operatorsFor(fieldSelect.value).forEach(function (op) {
          var option = document.createElement('option');
          option.value = op;
          option.textContent = humanise(op);
          opSelect.appendChild(option);
        });

        if (rule && rule.operator && operatorsFor(fieldSelect.value).indexOf(rule.operator) !== -1) {
          opSelect.value = rule.operator;
        }
      }

      function renderValue() {
        valueHost.innerHTML = '';

        var op = opSelect.value;

        if (op === 'exists' || op === 'not_exists') {
          var note = document.createElement('div');
          note.className = 'muted small';
          note.textContent = 'No value needed';
          valueHost.appendChild(note);
          return;
        }

        var key = fieldSelect.value;
        var options = choices[key];

        if (options) {
          var select = document.createElement('select');
          select.setAttribute('data-value', '');

          if (op === 'in' || op === 'not_in') {
            select.multiple = true;
            select.size = Math.min(4, Object.keys(options).length);
          }

          Object.keys(options).forEach(function (optionValue) {
            var option = document.createElement('option');
            option.value = optionValue;
            option.textContent = options[optionValue];
            select.appendChild(option);
          });

          if (rule && rule.value !== undefined && rule.value !== null) {
            var wanted = Array.isArray(rule.value) ? rule.value.map(String) : [String(rule.value)];
            Array.prototype.forEach.call(select.options, function (option) {
              option.selected = wanted.indexOf(option.value) !== -1;
            });
          }

          valueHost.appendChild(select);
          return;
        }

        var input = document.createElement('input');
        input.setAttribute('data-value', '');
        input.type = fieldType(key) === 'number' || fieldType(key) === 'money' ? 'number' : 'text';
        input.step = 'any';

        if (fieldType(key) === 'date') {
          input.type = 'text';
          input.placeholder = 'now-180days, now-1year, or 2026-01-31';
        }

        if (rule && rule.value !== undefined && rule.value !== null) {
          input.value = Array.isArray(rule.value) ? rule.value.join(',') : rule.value;
        }

        valueHost.appendChild(input);

        if (op === 'between') {
          var second = document.createElement('input');
          second.setAttribute('data-value2', '');
          second.type = input.type;
          second.placeholder = 'and…';
          second.style.marginTop = '6px';
          if (rule && rule.value2 !== undefined) { second.value = rule.value2; }
          valueHost.appendChild(second);
        }
      }

      fieldSelect.addEventListener('change', function () {
        renderOperators();
        renderValue();
        sync();
      });

      opSelect.addEventListener('change', function () {
        renderValue();
        sync();
      });

      valueHost.addEventListener('change', sync);
      valueHost.addEventListener('input', debounce(sync, 400));

      renderOperators();
      renderValue();

      row.appendChild(fieldSelect);
      row.appendChild(opSelect);
      row.appendChild(valueHost);
      row.appendChild(remove);
      rowsHost.appendChild(row);
    }

    function collect() {
      var rules = [];

      Array.prototype.forEach.call(rowsHost.querySelectorAll('.rule-row'), function (row) {
        var selects = row.querySelectorAll('select');
        var field = selects[0].value;
        var operator = selects[1].value;
        var valueEl = row.querySelector('[data-value]');
        var value2El = row.querySelector('[data-value2]');
        var value = null;

        if (valueEl) {
          if (valueEl.tagName === 'SELECT' && valueEl.multiple) {
            value = Array.prototype.filter.call(valueEl.options, function (o) { return o.selected; })
              .map(function (o) { return o.value; });
          } else {
            value = valueEl.value;
          }
        }

        var rule = { field: field, operator: operator, value: value };

        if (value2El && value2El.value !== '') {
          rule.value2 = value2El.value;
        }

        rules.push(rule);
      });

      return { match: matchSelect ? matchSelect.value : 'all', rules: rules };
    }

    function sync() {
      var definition = collect();
      hidden.value = JSON.stringify(definition);
      schedulePreview(definition);
    }

    var previewTimer = null;

    function schedulePreview(definition) {
      if (!preview) { return; }

      if (previewTimer) { window.clearTimeout(previewTimer); }

      previewTimer = window.setTimeout(function () { runPreview(definition); }, 500);
    }

    function runPreview(definition) {
      if (!definition.rules.length) {
        preview.innerHTML = '<p class="muted small" style="margin:0">Add a condition to see the audience.</p>';
        return;
      }

      preview.innerHTML = '<p class="muted small" style="margin:0">Counting…</p>';

      var body = new FormData();
      body.append('_token', token());
      body.append('definition', JSON.stringify(definition));

      fetch('/segments/preview', {
        method: 'POST',
        body: body,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data.error) {
            preview.innerHTML = '<p class="small" style="margin:0;color:#b91c1c">' +
              escapeHtml(data.error.message || 'That segment is not valid yet.') + '</p>';
            return;
          }

          preview.innerHTML =
            '<div class="stats">' +
            tile('Matching contacts', data.total) +
            tile('Can be emailed', data.eligible, 'stat--accent') +
            tile('Suppressed', data.suppressed) +
            tile('No consent', data.no_consent) +
            '</div>' +
            '<p class="tiny muted mt-1" style="margin-bottom:0">' +
            escapeHtml(data.summary || '') +
            '</p>';
        })
        .catch(function () {
          preview.innerHTML = '<p class="small muted" style="margin:0">Preview unavailable right now.</p>';
        });
    }

    function tile(label, value, extra) {
      return '<div class="stat ' + (extra || '') + '">' +
        '<div class="stat__label">' + escapeHtml(label) + '</div>' +
        '<div class="stat__value">' + Number(value || 0).toLocaleString() + '</div>' +
        '</div>';
    }

    var addButton = root.querySelector('[data-add-rule]');

    if (addButton) {
      addButton.addEventListener('click', function () {
        addRow(null);
        sync();
      });
    }

    if (matchSelect) {
      matchSelect.addEventListener('change', sync);
    }

    if (initial && Array.isArray(initial.rules) && initial.rules.length) {
      if (matchSelect && initial.match) { matchSelect.value = initial.match; }

      initial.rules.forEach(function (rule) {
        // Nested groups are rendered flat in this first version of the builder;
        // the compiler supports arbitrary nesting and a saved nested definition is
        // preserved until it is edited here.
        if (rule && !rule.rules) { addRow(rule); }
      });
    } else {
      addRow(null);
    }

    sync();
  }

  /* ------------------------------------------------------------------- helpers */

  function debounce(fn, wait) {
    var timer = null;

    return function () {
      var args = arguments;
      if (timer) { window.clearTimeout(timer); }
      timer = window.setTimeout(function () { fn.apply(null, args); }, wait);
    };
  }

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(value)));
    return div.innerHTML;
  }
})();
