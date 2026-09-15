<?php
$__view->extend('layouts.app');
$title = 'Contacts';
$rows  = $result['rows'];

// Build the export link from the validated filters rather than echoing the raw
// query string back into the page.
$exportQuery = http_build_query(array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0 && $v !== null));
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Contacts</h1>
    <p><?= number_format($result['total']) ?> matching <?= $result['total'] === 1 ? 'contact' : 'contacts' ?></p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/contacts/export<?= $exportQuery === '' ? '' : '?' . e($exportQuery) ?>">Export CSV</a>
    <a class="btn" href="/contacts/import">Import</a>
    <a class="btn btn--primary" href="/contacts/create">Add contact</a>
  </div>
</div>

<div class="card mb-2">
  <div class="card__body">
    <form method="get" action="/contacts">
      <div class="grid-3">
        <div class="field">
          <label for="search">Search</label>
          <input id="search" type="search" name="search" value="<?= e($filters['search']) ?>"
                 placeholder="Name, email, company or phone">
        </div>
        <div class="field">
          <label for="status">Customer status</label>
          <select id="status" name="status" data-auto-submit>
            <option value="">Any</option>
            <?php foreach ($statuses as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="country">Country</label>
          <select id="country" name="country" data-auto-submit>
            <option value="">Any</option>
            <?php foreach ($countries as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= $filters['country'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="tag_id">Tag</label>
          <select id="tag_id" name="tag_id" data-auto-submit>
            <option value="">Any</option>
            <?php foreach ($tags as $tag): ?>
              <option value="<?= (int) $tag['id'] ?>" <?= (int) $filters['tag_id'] === (int) $tag['id'] ? 'selected' : '' ?>>
                <?= e((string) $tag['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="consent">Marketing consent</label>
          <select id="consent" name="consent" data-auto-submit>
            <option value="">Any</option>
            <option value="yes" <?= $filters['consent'] === 'yes' ? 'selected' : '' ?>>Consent recorded</option>
            <option value="no" <?= $filters['consent'] === 'no' ? 'selected' : '' ?>>No consent</option>
          </select>
        </div>
        <div class="field">
          <label for="suppressed">Do-not-email</label>
          <select id="suppressed" name="suppressed" data-auto-submit>
            <option value="">Any</option>
            <option value="no" <?= $filters['suppressed'] === 'no' ? 'selected' : '' ?>>Not suppressed</option>
            <option value="yes" <?= $filters['suppressed'] === 'yes' ? 'selected' : '' ?>>Suppressed</option>
          </select>
        </div>
      </div>
      <div class="flex">
        <button class="btn btn--primary btn--sm" type="submit">Apply filters</button>
        <a class="btn btn--ghost btn--sm" href="/contacts">Clear</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($rows === []): ?>
      <div class="empty">
        <h3>No contacts match</h3>
        <p>Adjust the filters, or add your first contacts to get started.</p>
        <div class="flex" style="justify-content:center">
          <a class="btn" href="/contacts/import">Import a CSV</a>
          <a class="btn btn--primary" href="/contacts/create">Add a contact</a>
        </div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Contact</th>
              <th>Status</th>
              <th>Location</th>
              <th class="num">Revenue</th>
              <th class="num">Score</th>
              <th>Marketing</th>
              <th>Added</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $contact): ?>
              <tr>
                <td>
                  <a href="/contacts/<?= (int) $contact['id'] ?>">
                    <strong><?= e(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: (string) $contact['email']) ?></strong>
                  </a>
                  <div class="tiny muted"><?= e((string) $contact['email']) ?></div>
                  <?php if (!empty($contact['company'])): ?>
                    <div class="tiny muted"><?= e((string) $contact['company']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge"><?= e($statuses[$contact['customer_status']] ?? (string) $contact['customer_status']) ?></span>
                </td>
                <td class="small muted">
                  <?= e(trim(implode(', ', array_filter([
                      (string) ($contact['city'] ?? ''),
                      (string) ($contact['country'] ?? ''),
                  ])))) ?>
                </td>
                <td class="num"><?= e(money((float) $contact['total_revenue'], (string) ($contact['currency'] ?: ($organisation['currency'] ?? 'USD')))) ?></td>
                <td class="num"><?= (int) $contact['lead_score'] ?></td>
                <td>
                  <?php if ((int) $contact['is_suppressed_cache'] === 1): ?>
                    <span class="badge badge--danger badge--dot">Suppressed</span>
                  <?php elseif ((int) $contact['marketing_consent_cache'] === 1): ?>
                    <span class="badge badge--success badge--dot">Consent</span>
                  <?php else: ?>
                    <span class="badge badge--warning badge--dot">No consent</span>
                  <?php endif; ?>
                </td>
                <td class="small muted nowrap"><?= e(substr((string) $contact['created_at'], 0, 10)) ?></td>
                <td class="right">
                  <a class="btn btn--sm" href="/contacts/<?= (int) $contact['id'] ?>">View</a>
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
          'page'  => $result['page'],
          'pages' => $result['pages'],
          'total' => $result['total'],
          'query' => $filters,
      ]) ?>
    </div>
  <?php endif; ?>
</div>

<?php $__view->endSection(); ?>
