/*
 * Block editor.
 *
 * Renders a form per block from the registry the server sent, keeps a JSON
 * document in a hidden input, and asks the server to render the preview. The
 * browser never builds email HTML itself: what you see in the preview is what the
 * renderer will produce at send time, and an email body never executes in this
 * origin.
 */
(function () {
  'use strict';

  var root = document.getElementById('blockEditor');

  if (!root) {
    return;
  }

  var registry = JSON.parse(root.getAttribute('data-registry') || '{}');
  var mergeFields = JSON.parse(root.getAttribute('data-merge-fields') || '{}');
  var blocks = JSON.parse(root.getAttribute('data-blocks') || '[]');

  var list = root.querySelector('[data-block-list]');
  var hidden = document.getElementById('blocksInput');
  var picker = document.getElementById('blockPicker');
  var addButton = document.getElementById('addBlock');
  var preview = document.getElementById('templatePreview');
  var form = document.getElementById('templateForm');

  function token() {
    var el = document.querySelector('input[name="_token"]');
    return el ? el.value : '';
  }

  function defaultsFor(type) {
    var settings = {};
    var declared = (registry[type] && registry[type].settings) || {};

    Object.keys(declared).forEach(function (key) {
      settings[key] = declared[key].default;
    });

    return settings;
  }

  function render() {
    list.innerHTML = '';

    if (!blocks.length) {
      var empty = document.createElement('p');
      empty.className = 'muted small';
      empty.textContent = 'No blocks yet. Add one above.';
      list.appendChild(empty);
    }

    blocks.forEach(function (block, index) {
      list.appendChild(blockCard(block, index));
    });

    sync();
  }

  function blockCard(block, index) {
    var definition = registry[block.type] || { label: block.type, settings: {} };

    var card = document.createElement('div');
    card.className = 'rule-group';

    var head = document.createElement('div');
    head.className = 'rule-group__head';
    head.style.justifyContent = 'space-between';

    var label = document.createElement('strong');
    label.textContent = (index + 1) + '. ' + definition.label;
    head.appendChild(label);

    var controls = document.createElement('div');
    controls.className = 'flex';

    controls.appendChild(iconButton('Move up', index === 0, function () {
      swap(index, index - 1);
    }));

    controls.appendChild(iconButton('Move down', index === blocks.length - 1, function () {
      swap(index, index + 1);
    }));

    controls.appendChild(iconButton('Duplicate', false, function () {
      blocks.splice(index + 1, 0, JSON.parse(JSON.stringify(block)));
      render();
    }));

    var remove = iconButton('Remove', false, function () {
      blocks.splice(index, 1);
      render();
    });
    remove.className = 'btn btn--sm btn--danger';
    controls.appendChild(remove);

    head.appendChild(controls);
    card.appendChild(head);

    Object.keys(definition.settings || {}).forEach(function (key) {
      card.appendChild(settingField(block, key, definition.settings[key]));
    });

    return card;
  }

  function iconButton(text, disabled, onClick) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn--sm';
    button.textContent = text;
    button.disabled = !!disabled;
    button.addEventListener('click', onClick);

    return button;
  }

  function swap(a, b) {
    if (b < 0 || b >= blocks.length) { return; }
    var temp = blocks[a];
    blocks[a] = blocks[b];
    blocks[b] = temp;
    render();
  }

  function settingField(block, key, definition) {
    var wrapper = document.createElement('div');
    wrapper.className = 'field';

    var label = document.createElement('label');
    label.textContent = humanise(key);
    wrapper.appendChild(label);

    var input;

    if (definition.type === 'enum') {
      input = document.createElement('select');
      (definition.options || []).forEach(function (option) {
        var el = document.createElement('option');
        el.value = option;
        el.textContent = humanise(option);
        input.appendChild(el);
      });
    } else if (definition.type === 'rich') {
      input = document.createElement('textarea');
      input.rows = 4;
      input.placeholder = '<p>Paragraphs, <strong>bold</strong>, <a href="…">links</a> and lists.</p>';
    } else if (definition.type === 'list') {
      input = document.createElement('select');
      input.multiple = true;
      input.size = 5;
      Object.keys(mergeFields).forEach(function (field) {
        var el = document.createElement('option');
        el.value = field;
        el.textContent = mergeFields[field];
        input.appendChild(el);
      });
    } else if (definition.type === 'colour') {
      input = document.createElement('input');
      input.type = 'text';
      input.placeholder = '#1d4ed8';
    } else {
      input = document.createElement('input');
      input.type = definition.type === 'url' ? 'text' : 'text';
      if (definition.type === 'url') {
        input.placeholder = 'https://…';
      }
    }

    var current = block.settings && block.settings[key] !== undefined
      ? block.settings[key]
      : definition.default;

    if (definition.type === 'list') {
      var wanted = Array.isArray(current) ? current.map(String) : [];
      Array.prototype.forEach.call(input.options, function (option) {
        option.selected = wanted.indexOf(option.value) !== -1;
      });
    } else {
      input.value = current === null || current === undefined ? '' : current;
    }

    input.addEventListener('input', function () { update(block, key, input, definition); });
    input.addEventListener('change', function () { update(block, key, input, definition); });

    wrapper.appendChild(input);

    if (definition.type === 'rich') {
      var hint = document.createElement('div');
      hint.className = 'hint';
      hint.textContent = 'Limited HTML. Anything else — scripts, styles, unusual link schemes — is stripped when saved.';
      wrapper.appendChild(hint);
    }

    return wrapper;
  }

  function update(block, key, input, definition) {
    block.settings = block.settings || {};

    if (definition.type === 'list') {
      block.settings[key] = Array.prototype.filter.call(input.options, function (o) { return o.selected; })
        .map(function (o) { return o.value; });
    } else {
      block.settings[key] = input.value;
    }

    sync();
  }

  function humanise(value) {
    return String(value).replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); });
  }

  var previewTimer = null;

  function sync() {
    hidden.value = JSON.stringify(blocks);

    if (previewTimer) { window.clearTimeout(previewTimer); }
    previewTimer = window.setTimeout(refreshPreview, 500);
  }

  function refreshPreview() {
    if (!preview || !blocks.length) { return; }

    var body = new FormData();
    body.append('_token', token());
    body.append('blocks', JSON.stringify(blocks));

    fetch('/templates/preview', {
      method: 'POST',
      body: body,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (response) { return response.text(); })
      .then(function (html) {
        // srcdoc plus a sandboxed frame: the preview renders but cannot run
        // scripts, navigate, or reach anything in this origin.
        preview.srcdoc = html;
      })
      .catch(function () { /* a failed preview must not break the editor */ });
  }

  if (addButton && picker) {
    addButton.addEventListener('click', function () {
      var type = picker.value;
      blocks.push({ type: type, settings: defaultsFor(type) });
      render();
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-preview-width]'), function (button) {
    button.addEventListener('click', function () {
      var width = button.getAttribute('data-preview-width');
      preview.style.width = width === '360' ? '360px' : '100%';
    });
  });

  if (form) {
    form.addEventListener('submit', function () { hidden.value = JSON.stringify(blocks); });
  }

  render();
  refreshPreview();
})();
