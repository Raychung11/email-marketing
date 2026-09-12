<?php $__view->extend('layouts.app'); $title = 'Custom fields'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Custom fields</h1>
    <p>Extra contact data you can segment and personalise on.</p>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__body card__body--tight">
        <?php if ($definitions === []): ?>
          <div class="empty">
            <h3>No custom fields yet</h3>
            <p>Useful examples: property type, service plan, vehicle registration, last service date.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Label</th><th>Key</th><th>Type</th><th>Options</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($definitions as $definition): ?>
                  <tr>
                    <td><strong><?= e((string) $definition['label']) ?></strong>
                      <?php if (!empty($definition['help_text'])): ?>
                        <div class="tiny muted"><?= e((string) $definition['help_text']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="mono small"><?= e((string) $definition['key']) ?></td>
                    <td><span class="badge"><?= e($types[$definition['type']] ?? (string) $definition['type']) ?></span></td>
                    <td class="small muted"><?= e(implode(', ', (array) $definition['options'])) ?></td>
                    <td class="right">
                      <form method="post" action="/settings/custom-fields/<?= (int) $definition['id'] ?>/delete"
                            data-confirm="Delete this field? All stored values for it are deleted too.">
                        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                        <button class="btn btn--sm btn--danger" type="submit">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>New custom field</h2></div>
      <div class="card__body">
        <form method="post" action="/settings/custom-fields">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="label">Label</label>
            <input id="label" type="text" name="label" required maxlength="120" placeholder="e.g. Property type">
          </div>
          <div class="field">
            <label for="key">Key</label>
            <input id="key" type="text" name="key" required maxlength="60" pattern="[A-Za-z0-9_-]+" placeholder="property_type">
            <div class="hint">Used in segments and as <span class="mono">{{property_type}}</span> in templates.</div>
          </div>
          <div class="field">
            <label for="type">Type</label>
            <select id="type" name="type" required>
              <?php foreach ($types as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Numbers and dates are stored typed, so segment comparisons sort correctly.</div>
          </div>
          <div class="field">
            <label for="options">Options (one per line)</label>
            <textarea id="options" name="options" placeholder="House&#10;Apartment&#10;Commercial"></textarea>
            <div class="hint">Only used by the select types.</div>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Create field</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
