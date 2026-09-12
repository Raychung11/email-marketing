<?php $__view->extend('layouts.app'); $title = 'Audit log'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Audit log</h1>
    <p><?= number_format($total) ?> recorded actions. Append-only — nothing here can be edited or deleted.</p>
  </div>
</div>

<div class="card mb-2">
  <div class="card__body">
    <form method="get" action="/compliance/audit-log" class="flex wrap">
      <input type="text" name="action" value="<?= e($filters['action']) ?>" placeholder="Action, e.g. consent_withdrawn" style="max-width:240px">
      <input type="text" name="entity_type" value="<?= e($filters['entity_type']) ?>" placeholder="Entity, e.g. contact" style="max-width:180px">
      <input type="date" name="from" value="<?= e($filters['from']) ?>" style="max-width:170px">
      <input type="date" name="to" value="<?= e($filters['to']) ?>" style="max-width:170px">
      <button class="btn btn--sm btn--primary" type="submit">Filter</button>
      <a class="btn btn--sm btn--ghost" href="/compliance/audit-log">Clear</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($logs === []): ?>
      <div class="empty"><h3>No matching entries</h3></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>When (UTC)</th><th>Actor</th><th>Action</th><th>Entity</th><th>Change</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td class="small nowrap"><?= e((string) $log['created_at']) ?></td>
                <td class="small">
                  <span class="badge tiny"><?= e((string) $log['actor_type']) ?></span>
                  <?= $log['user_id'] !== null ? '#' . (int) $log['user_id'] : '' ?>
                </td>
                <td><span class="badge"><?= e(str_replace('_', ' ', (string) $log['action'])) ?></span></td>
                <td class="small muted">
                  <?= e((string) ($log['entity_type'] ?? '')) ?>
                  <?= $log['entity_id'] !== null ? '#' . (int) $log['entity_id'] : '' ?>
                </td>
                <td class="tiny mono muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis">
                  <?= e(App\Support\Str::limit((string) ($log['new_values'] ?? ''), 120)) ?>
                </td>
                <td class="tiny mono muted"><?= e((string) ($log['ip_address'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="card__foot">
      <?= $__view->include('partials.pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'query' => $filters]) ?>
    </div>
  <?php endif; ?>
</div>

<?php $__view->endSection(); ?>
