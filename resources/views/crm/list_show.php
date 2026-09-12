<?php $__view->extend('layouts.app'); $title = (string) $list['name']; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e((string) $list['name']) ?></h1>
    <p><?= number_format($total) ?> contacts <?= !empty($list['description']) ? '&middot; ' . e((string) $list['description']) : '' ?></p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/contacts?list_id=<?= (int) $list['id'] ?>">Filter contacts by this list</a>
    <form method="post" action="/lists/<?= (int) $list['id'] ?>/delete" data-confirm="Delete this list? Contacts are not deleted.">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <button class="btn btn--danger" type="submit">Delete list</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($contacts === []): ?>
      <div class="empty">
        <h3>No contacts on this list</h3>
        <p>Add contacts from their profile, during an import, or from a form.</p>
        <a class="btn btn--primary" href="/contacts">Browse contacts</a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Contact</th><th>Status</th><th>Marketing</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($contacts as $contact): ?>
              <tr>
                <td>
                  <a href="/contacts/<?= (int) $contact['id'] ?>">
                    <?= e(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: (string) $contact['email']) ?>
                  </a>
                  <div class="tiny muted"><?= e((string) $contact['email']) ?></div>
                </td>
                <td><span class="badge"><?= e(str_replace('_', ' ', (string) $contact['customer_status'])) ?></span></td>
                <td>
                  <?php if ((int) $contact['is_suppressed_cache'] === 1): ?>
                    <span class="badge badge--danger badge--dot">Suppressed</span>
                  <?php elseif ((int) $contact['marketing_consent_cache'] === 1): ?>
                    <span class="badge badge--success badge--dot">Consent</span>
                  <?php else: ?>
                    <span class="badge badge--warning badge--dot">No consent</span>
                  <?php endif; ?>
                </td>
                <td class="right">
                  <form method="post" action="/lists/<?= (int) $list['id'] ?>/contacts/<?= (int) $contact['id'] ?>/remove">
                    <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                    <button class="btn btn--sm" type="submit">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="card__foot">
      <?= $__view->include('partials.pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'query' => []]) ?>
    </div>
  <?php endif; ?>
</div>

<?php $__view->endSection(); ?>
