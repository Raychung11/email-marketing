<?php $__view->extend('layouts.app'); $title = 'Suppression list'; $rows = $result['rows']; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Suppression list</h1>
    <p><?= number_format($result['total']) ?> addresses that will never receive marketing email.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/suppressions/export">Export CSV</a>
  </div>
</div>

<div class="alert alert--info">
  <strong>Suppression outranks everything</strong>
  An address here is skipped by every campaign and automation regardless of list membership,
  segment match or re-import. Removing an entry is deliberate, requires a reason, and is recorded
  in the audit log.
</div>

<div class="row">
  <div class="col">
    <div class="card mb-2">
      <div class="card__body">
        <form method="get" action="/suppressions" class="flex wrap">
          <input type="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Search by address" style="max-width:260px">
          <select name="reason" data-auto-submit style="max-width:200px">
            <option value="">Any reason</option>
            <?php foreach ($reasons as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $filters['reason'] === $key ? 'selected' : '' ?>>
                <?= e($label) ?><?= isset($counts[$key]) ? ' (' . number_format($counts[$key]) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn--sm btn--primary" type="submit">Filter</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__body card__body--tight">
        <?php if ($rows === []): ?>
          <div class="empty">
            <h3>Nothing suppressed</h3>
            <p>Unsubscribes, hard bounces and spam complaints land here automatically.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Address</th><th>Reason</th><th>Source</th><th>Since</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td class="mono small"><?= e((string) $row['email']) ?></td>
                    <td>
                      <span class="badge <?= in_array($row['reason'], ['complaint', 'hard_bounce', 'legal'], true) ? 'badge--danger' : '' ?>">
                        <?= e($reasons[$row['reason']] ?? (string) $row['reason']) ?>
                      </span>
                    </td>
                    <td class="small muted">
                      <?= e((string) ($row['source'] ?? '—')) ?>
                      <?php if (!empty($row['detail'])): ?>
                        <div class="tiny"><?= e(App\Support\Str::limit((string) $row['detail'], 60)) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="small muted nowrap"><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
                    <td class="right">
                      <form method="post" action="/suppressions/<?= (int) $row['id'] ?>/remove"
                            data-confirm="Remove this suppression? Only do this if you have a lawful basis to contact this address again. The removal is audited.">
                        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="reason" value="Removed from the suppression screen">
                        <button class="btn btn--sm btn--danger" type="submit">Remove</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($result['pages'] > 1): ?>
        <div class="card__foot">
          <?= $__view->include('partials.pagination', [
              'page' => $result['page'], 'pages' => $result['pages'],
              'total' => $result['total'], 'query' => $filters,
          ]) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Suppress an address</h2></div>
      <div class="card__body">
        <form method="post" action="/suppressions">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" required maxlength="255">
          </div>
          <div class="field">
            <label for="detail">Reason (internal note)</label>
            <input id="detail" type="text" name="detail" maxlength="255" placeholder="e.g. asked by phone not to be emailed">
          </div>
          <button class="btn btn--primary btn--block" type="submit">Suppress</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Breakdown</h2></div>
      <div class="card__body">
        <?php foreach ($reasons as $key => $label): ?>
          <div class="flex-between small">
            <span><?= e($label) ?></span><strong><?= number_format($counts[$key] ?? 0) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
