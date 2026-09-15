<?php
$__view->extend('layouts.app');
$title = 'Import options';
$steps = ['Upload', 'Preview', 'Map', 'Validate', 'Consent', 'Duplicates', 'Import', 'Summary'];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Duplicates and destinations</h1><p><?= e((string) $batch['original_filename']) ?></p></div>
</div>

<?= $__view->include('partials.import_steps', ['steps' => $steps, 'current' => 6]) ?>

<div class="alert alert--success">
  <strong>Consent declared: <?= e(str_replace('_', ' ', (string) $batch['consent_source'])) ?></strong>
  <?php if (!empty($batch['consent_reference'])): ?>
    Reference: <?= e((string) $batch['consent_reference']) ?>
  <?php endif; ?>
</div>

<form method="post" action="/contacts/import/<?= (int) $batch['id'] ?>/run">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="row">
    <div class="col">
      <div class="card">
        <div class="card__head"><h2>When a contact already exists</h2></div>
        <div class="card__body">
          <label class="check mb-2">
            <input type="radio" name="duplicate_strategy" value="update_existing" checked>
            <span><strong>Update the existing contact</strong>
              <div class="tiny muted">Fields in the file overwrite what is stored.</div></span>
          </label>
          <label class="check mb-2">
            <input type="radio" name="duplicate_strategy" value="merge_fill_blanks">
            <span><strong>Fill in blanks only</strong>
              <div class="tiny muted">Existing values are kept; only empty fields are filled from the file.</div></span>
          </label>
          <label class="check">
            <input type="radio" name="duplicate_strategy" value="skip_existing">
            <span><strong>Skip the row</strong>
              <div class="tiny muted">Leave existing contacts exactly as they are.</div></span>
          </label>

          <hr class="sep">
          <p class="tiny muted mb-0">
            Either way, nobody comes off your do-not-email list, and the permission
            declaration for this import is appended to each contact's consent history rather than
            replacing what is there.
          </p>
        </div>
      </div>
    </div>

    <div class="col col--narrow">
      <?php if ($lists !== []): ?>
        <div class="card">
          <div class="card__head"><h2>Add to lists</h2></div>
          <div class="card__body">
            <?php foreach ($lists as $list): ?>
              <label class="check mb-1">
                <input type="checkbox" name="list_ids[]" value="<?= (int) $list['id'] ?>">
                <span><?= e((string) $list['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($tags !== []): ?>
        <div class="card">
          <div class="card__head"><h2>Apply tags</h2></div>
          <div class="card__body">
            <?php foreach ($tags as $tag): ?>
              <label class="check mb-1">
                <input type="checkbox" name="tag_ids[]" value="<?= (int) $tag['id'] ?>">
                <span><?= e((string) $tag['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <button class="btn btn--primary btn--block mt-2" type="submit">
        Import <?= number_format((int) $batch['total_rows']) ?> rows
      </button>
    </div>
  </div>
</form>

<?php $__view->endSection(); ?>
