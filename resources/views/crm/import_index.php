<?php $__view->extend('layouts.app'); $title = 'Import contacts'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Import contacts</h1>
    <p>CSV. Eight steps, including a consent declaration that decides what may be sent later.</p>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>Upload a CSV</h2></div>
      <div class="card__body">
        <form method="post" action="/contacts/import" enctype="multipart/form-data">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

          <div class="field">
            <label for="file">CSV file</label>
            <input id="file" type="file" name="file" accept=".csv,text/csv" required>
            <div class="hint">
              First row must be a header. Comma, semicolon, tab and pipe delimiters are detected
              automatically. Up to <?= number_format($rowLimit) ?> rows on your current
              <strong><?= e($trustLevel) ?></strong> trust level.
            </div>
          </div>

          <button class="btn btn--primary" type="submit">Upload and preview</button>
        </form>
      </div>
    </div>

    <?php if ($batches !== []): ?>
      <div class="card">
        <div class="card__head"><h2>Recent imports</h2></div>
        <div class="card__body card__body--tight">
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>File</th><th>Status</th><th class="num">Imported</th><th class="num">Updated</th><th class="num">Skipped</th><th>When</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($batches as $batch): ?>
                  <tr>
                    <td><?= e((string) $batch['original_filename']) ?>
                      <div class="tiny muted"><?= number_format((int) $batch['total_rows']) ?> rows</div>
                    </td>
                    <td>
                      <?php $status = (string) $batch['status']; ?>
                      <span class="badge <?= $status === 'completed' ? 'badge--success' : ($status === 'blocked' ? 'badge--danger' : '') ?>">
                        <?= e(str_replace('_', ' ', $status)) ?>
                      </span>
                      <?php if ($status === 'blocked'): ?>
                        <div class="tiny" style="color:#b91c1c"><?= e((string) ($batch['blocked_reason'] ?? '')) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="num"><?= number_format((int) $batch['imported_count']) ?></td>
                    <td class="num"><?= number_format((int) $batch['updated_count']) ?></td>
                    <td class="num"><?= number_format((int) $batch['skipped_count']) ?></td>
                    <td class="small muted nowrap"><?= e(substr((string) $batch['created_at'], 0, 16)) ?></td>
                    <td class="right">
                      <?php if ($status === 'completed'): ?>
                        <a class="btn btn--sm" href="/contacts/import/<?= (int) $batch['id'] ?>/summary">Summary</a>
                      <?php elseif (!in_array($status, ['blocked', 'failed', 'cancelled'], true)): ?>
                        <a class="btn btn--sm btn--primary" href="/contacts/import/<?= (int) $batch['id'] ?>/map">Continue</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Before you import</h2></div>
      <div class="card__body small">
        <p class="mt-0">You will be asked how these contacts were obtained. That answer decides what
          the platform will let you send.</p>

        <p><strong>Accepted sources</strong></p>
        <ul style="padding-left:18px;margin:0 0 12px">
          <?php foreach ($sources as $key => $definition): ?>
            <?php if (empty($definition['blocked'])): ?>
              <li><?= e((string) $definition['label']) ?></li>
            <?php endif; ?>
          <?php endforeach; ?>
        </ul>

        <p><strong>Refused outright</strong></p>
        <ul style="padding-left:18px;margin:0 0 12px">
          <?php foreach ($sources as $key => $definition): ?>
            <?php if (!empty($definition['blocked'])): ?>
              <li><?= e((string) $definition['label']) ?></li>
            <?php endif; ?>
          <?php endforeach; ?>
        </ul>

        <p class="muted mb-0">
          Purchased and scraped lists are not imported-and-blocked — the import does not run at all.
          Existing suppressions are never cleared by an import, so someone who unsubscribed stays
          unsubscribed even if their address appears in the file again.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
