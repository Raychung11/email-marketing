<?php
/**
 * Block editor.
 *
 * The preview renders in an iframe fed by a server-side endpoint rather than by
 * assembling HTML in the browser. Two reasons: what you see is exactly what the
 * renderer will produce at send time, and an email body full of arbitrary markup
 * never executes inside the application's own origin.
 */
$__view->extend('layouts.app');

$isEdit = $template !== null;
$title  = $isEdit ? 'Edit template' : 'New template';
$action = $isEdit ? '/templates/' . (int) $template['id'] : '/templates';
?>
<?php $__view->startSection('content'); ?>

<form method="post" action="<?= e($action) ?>" id="templateForm">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
  <input type="hidden" name="blocks" id="blocksInput" value="">

  <div class="page-head">
    <div>
      <h1><?= e($title) ?></h1>
      <p>Blocks render to table-based HTML that survives Outlook, Gmail and Apple Mail.</p>
    </div>
    <div class="page-head__actions">
      <a class="btn btn--ghost" href="/templates">Cancel</a>
      <button class="btn btn--primary" type="submit">Save template</button>
    </div>
  </div>

  <div class="row">
    <div class="col">
      <div class="card">
        <div class="card__head"><h2>Details</h2></div>
        <div class="card__body">
          <div class="grid-2">
            <div class="field">
              <label for="name">Template name</label>
              <input id="name" type="text" name="name" required maxlength="160"
                     value="<?= e($old['name'] ?? (string) ($template['name'] ?? '')) ?>"
                     placeholder="e.g. Annual service reminder">
            </div>
            <div class="field">
              <label for="category">Category</label>
              <select id="category" name="category">
                <?php foreach ($categories as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field">
            <label for="description">Description</label>
            <input id="description" type="text" name="description" maxlength="255"
                   value="<?= e($old['description'] ?? (string) ($template['description'] ?? '')) ?>">
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <h2>Blocks</h2>
          <div class="card__actions">
            <select id="blockPicker" style="width:auto">
              <?php foreach ($registry['blocks'] as $key => $definition): ?>
                <option value="<?= e($key) ?>"><?= e((string) $definition['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn--sm btn--primary" type="button" id="addBlock">Add block</button>
          </div>
        </div>
        <div class="card__body">
          <div id="blockEditor"
               data-registry='<?= e(json_encode($registry['blocks'], JSON_UNESCAPED_SLASHES)) ?>'
               data-merge-fields='<?= e(json_encode($registry['merge_fields'], JSON_UNESCAPED_SLASHES)) ?>'
               data-blocks='<?= e(json_encode($blocks, JSON_UNESCAPED_SLASHES)) ?>'>
            <div data-block-list></div>
          </div>

          <noscript>
            <div class="alert alert--warning">
              The block editor needs JavaScript. Templates can also be created through the API.
            </div>
          </noscript>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><h2>Merge fields</h2></div>
        <div class="card__body">
          <p class="small muted mt-0">
            Type these into any text field. Unknown fields render as nothing rather than leaking a
            raw token into somebody's inbox.
          </p>
          <div class="flex wrap">
            <?php foreach ($registry['merge_fields'] as $key => $label): ?>
              <span class="tag mono" title="<?= e($label) ?>">{{<?= e($key) ?>}}</span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col col--narrow">
      <div class="card">
        <div class="card__head">
          <h2>Preview</h2>
          <div class="card__actions">
            <button class="btn btn--sm" type="button" data-preview-width="600">Desktop</button>
            <button class="btn btn--sm" type="button" data-preview-width="360">Mobile</button>
          </div>
        </div>
        <div class="card__body" style="background:#f4f5f7">
          <iframe id="templatePreview" title="Email preview"
                  style="width:100%;height:620px;border:1px solid var(--line);border-radius:8px;background:#fff;display:block;margin:0 auto"
                  sandbox=""></iframe>
          <p class="tiny muted mt-1 mb-0">
            Rendered by the same code that will send it, with sample customer details filled in.
          </p>
        </div>
      </div>

      <?php if ($isEdit): ?>
        <div class="card">
          <div class="card__head"><h2>Send a test</h2></div>
          <div class="card__body">
            <p class="tiny muted mt-0">
              Sent through the transactional path, so it does not need marketing consent and does not
              count against your daily sending limit.
            </p>
            <div class="field">
              <label for="recipient">Send to</label>
              <input id="recipient" type="email" form="testForm" name="recipient" required
                     value="<?= e((string) ($currentUser['email'] ?? '')) ?>">
            </div>
            <button class="btn btn--block" type="submit" form="testForm">Send test message</button>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <form method="post" action="/templates/<?= (int) $template['id'] ?>/test" id="testForm">
    <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
  </form>
<?php endif; ?>

<?php $__view->endSection(); ?>

<?php $__view->startSection('scripts'); ?>
<script src="/assets/js/template-editor.js" defer></script>
<?php $__view->endSection(); ?>
