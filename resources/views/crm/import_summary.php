<?php
$__view->extend('layouts.app');
$title = 'Import summary';
$steps = ['Upload', 'Preview', 'Map', 'Validate', 'Consent', 'Duplicates', 'Import', 'Summary'];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Import complete</h1><p><?= e((string) $batch['original_filename']) ?></p></div>
  <div class="page-head__actions">
    <a class="btn" href="/contacts/import">Import another file</a>
    <a class="btn btn--primary" href="/contacts">View contacts</a>
  </div>
</div>

<?= $__view->include('partials.import_steps', ['steps' => $steps, 'current' => 8]) ?>

<div class="stats mb-2">
  <div class="stat stat--accent"><div class="stat__label">Created</div><div class="stat__value"><?= number_format((int) $batch['imported_count']) ?></div></div>
  <div class="stat"><div class="stat__label">Updated</div><div class="stat__value"><?= number_format((int) $batch['updated_count']) ?></div></div>
  <div class="stat"><div class="stat__label">Skipped</div><div class="stat__value"><?= number_format((int) $batch['skipped_count']) ?></div></div>
  <div class="stat"><div class="stat__label">Invalid</div><div class="stat__value"><?= number_format((int) $batch['invalid_count']) ?></div></div>
  <div class="stat"><div class="stat__label">Duplicates</div><div class="stat__value"><?= number_format((int) $batch['duplicate_count']) ?></div></div>
  <div class="stat"><div class="stat__label">Suppressed</div><div class="stat__value"><?= number_format((int) $batch['suppressed_count']) ?></div></div>
</div>

<?php if ((int) $batch['no_consent_count'] > 0): ?>
  <div class="alert alert--warning">
    <strong>We do not know whether <?= number_format((int) $batch['no_consent_count']) ?> of these people agreed to hear from you</strong>
    They are saved, and your team can still ring them or email them personally. We just will not put
    them in a marketing campaign until you have their say-so. The usual way to get it is a sign-up
    form on your website, or a one-off email asking them to confirm.
  </div>
<?php endif; ?>

<?php if ($problemRows !== []): ?>
  <div class="card">
    <div class="card__head"><h2>Lines you might want to look at</h2></div>
    <div class="card__body card__body--tight">
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Row</th><th>Email</th><th>Outcome</th><th>Detail</th></tr></thead>
          <tbody>
            <?php foreach ($problemRows as $row): ?>
              <tr>
                <td class="num"><?= (int) $row['row_number'] ?></td>
                <td class="mono small"><?= e((string) ($row['email'] ?? '')) ?></td>
                <td><span class="badge"><?= e(str_replace('_', ' ', (string) $row['outcome'])) ?></span></td>
                <td class="small muted"><?= e((string) ($row['message'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2>What happens next</h2></div>
  <div class="card__body small">
    <p class="mt-0">
      The uploaded file has been deleted now that the import is finished — keeping raw customer data
      on disk adds risk and no value. The outcome of every row is retained above, and the import
      itself is recorded in the audit log with the consent declaration you made.
    </p>
    <p class="mb-0">
      Next: <a href="/segments/create">build a segment</a> to find the part of this list worth
      contacting first.
    </p>
  </div>
</div>

<?php $__view->endSection(); ?>
