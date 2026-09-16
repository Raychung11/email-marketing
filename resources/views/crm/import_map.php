<?php
$__view->extend('layouts.app');
$title = 'Map columns';
$steps = ['Upload', 'Preview', 'Map', 'Validate', 'Consent', 'Duplicates', 'Import', 'Summary'];
$currentStep = 3;
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Map your columns</h1><p><?= e((string) $batch['original_filename']) ?> &middot; <?= number_format((int) $batch['total_rows']) ?> rows</p></div>
</div>

<?= $__view->include('partials.import_steps', ['steps' => $steps, 'current' => $currentStep]) ?>

<form method="post" action="/contacts/import/<?= (int) $batch['id'] ?>/map">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="card">
    <div class="card__head"><h2>Column mapping</h2></div>
    <div class="card__body">
      <p class="small muted mt-0">
        We have guessed from your header row. Email is required — it is how contacts are
        checked for duplicates and against your do-not-email list.
      </p>

      <div class="grid-2">
        <?php foreach ($fields as $field => $label): ?>
          <div class="field">
            <label for="map_<?= e($field) ?>">
              <?= e($label) ?><?= $field === 'email' ? ' *' : '' ?>
            </label>
            <select id="map_<?= e($field) ?>" name="mapping[<?= e($field) ?>]" <?= $field === 'email' ? 'required' : '' ?>>
              <option value="">— not imported —</option>
              <?php foreach ($headers as $header): ?>
                <option value="<?= e($header) ?>" <?= ($mapping[$field] ?? '') === $header ? 'selected' : '' ?>>
                  <?= e($header) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>First rows of your file</h2></div>
    <div class="card__body card__body--tight">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><?php foreach ($headers as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $row): ?>
              <tr>
                <?php foreach ($headers as $header): ?>
                  <td class="small"><?= e(App\Support\Str::limit((string) ($row[$header] ?? ''), 40)) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="flex mt-2">
    <button class="btn btn--primary" type="submit">Continue to validation</button>
    <a class="btn btn--ghost" href="/contacts/import">Cancel</a>
  </div>
</form>

<?php $__view->endSection(); ?>
