<?php $__view->extend('layouts.app'); $title = (string) $segment['name']; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e((string) $segment['name']) ?></h1>
    <p><?= e($summary) ?></p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/segments/<?= (int) $segment['id'] ?>/edit">Edit rules</a>
    <form method="post" action="/segments/<?= (int) $segment['id'] ?>/delete" data-confirm="Delete this segment? Contacts are not affected.">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <button class="btn btn--danger" type="submit">Delete</button>
    </form>
  </div>
</div>

<div class="stats mb-2">
  <div class="stat">
    <div class="stat__label">Matching contacts</div>
    <div class="stat__value"><?= number_format($preview['total']) ?></div>
  </div>
  <div class="stat stat--accent">
    <div class="stat__label">Eligible for email</div>
    <div class="stat__value"><?= number_format($preview['eligible']) ?></div>
    <div class="stat__meta">passes suppression and consent</div>
  </div>
  <div class="stat">
    <div class="stat__label">Suppressed</div>
    <div class="stat__value"><?= number_format($preview['suppressed']) ?></div>
    <div class="stat__meta">unsubscribed, bounced or complained</div>
  </div>
  <div class="stat">
    <div class="stat__label">Consent unavailable</div>
    <div class="stat__value"><?= number_format($preview['no_consent']) ?></div>
    <div class="stat__meta">no acceptable basis recorded</div>
  </div>
  <div class="stat">
    <div class="stat__label">Invalid addresses</div>
    <div class="stat__value"><?= number_format($preview['invalid']) ?></div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Sample of eligible contacts</h2></div>
  <div class="card__body card__body--tight">
    <?php if ($preview['sample'] === []): ?>
      <div class="empty" style="padding:24px">
        <p class="mb-0">No eligible contacts match these rules at the moment.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Contact</th><th>Location</th><th class="num">Revenue</th><th>Last purchase</th></tr></thead>
          <tbody>
            <?php foreach ($preview['sample'] as $contact): ?>
              <tr>
                <td>
                  <a href="/contacts/<?= (int) $contact['id'] ?>">
                    <?= e(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: (string) $contact['email']) ?>
                  </a>
                  <div class="tiny muted"><?= e((string) $contact['email']) ?></div>
                </td>
                <td class="small muted"><?= e(trim(implode(', ', array_filter([
                    (string) ($contact['city'] ?? ''), (string) ($contact['country'] ?? ''),
                ])))) ?></td>
                <td class="num"><?= e(money((float) $contact['total_revenue'], (string) ($organisation['currency'] ?? 'USD'))) ?></td>
                <td class="small muted"><?= e(substr((string) ($contact['last_purchase_at'] ?? ''), 0, 10) ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Definition</h2></div>
  <div class="card__body">
    <pre class="mono" style="margin:0;white-space:pre-wrap"><?= e(json_encode($segment['definition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
  </div>
</div>

<?php $__view->endSection(); ?>
