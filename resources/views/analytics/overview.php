<?php
$__view->extend('layouts.app');
$title = 'Analytics';
$em    = $metrics['email'];
$rev   = $metrics['revenue'];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>How your email is doing</h1>
    <p>The last 30 days.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/analytics/campaigns">Campaign by campaign</a>
    <a class="btn" href="/analytics/deliverability">Is it getting through?</a>
  </div>
</div>

<div class="stats mb-2">
  <div class="stat">
    <div class="stat__label">Emails sent</div>
    <div class="stat__value"><?= number_format($em['sent']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Arrived</div>
    <div class="stat__value"><?= $em['delivery_rate'] ?>%</div>
    <div class="stat__meta"><?= number_format($em['delivered']) ?> of <?= number_format($em['sent']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Clicked something</div>
    <div class="stat__value"><?= $em['click_rate'] ?>%</div>
    <div class="stat__meta">the number worth watching</div>
  </div>
  <div class="stat">
    <div class="stat__label">Sales from email</div>
    <div class="stat__value"><?= e(money($rev['attributed_value'], $metrics['currency'])) ?></div>
  </div>
</div>

<?php if ($highlights['best'] !== null): ?>
  <div class="row">
    <div class="col">
      <div class="card">
        <div class="card__head"><h2>Your best campaign</h2></div>
        <div class="card__body">
          <p class="mt-0">
            <a href="/campaigns/<?= (int) $highlights['best']['id'] ?>">
              <strong><?= e((string) $highlights['best']['name']) ?></strong>
            </a>
          </p>
          <div class="flex-between small">
            <span><?= number_format((int) $highlights['best']['clicked']) ?> people clicked</span>
            <strong><?= $highlights['best']['click_rate'] ?>%</strong>
          </div>
          <p class="tiny muted mb-0">
            Against an average of <?= $highlights['average_click_rate'] ?>% across your campaigns.
            Worth asking what was different about this one.
          </p>
        </div>
      </div>
    </div>
    <div class="col">
      <div class="card">
        <div class="card__head"><h2>Your quietest campaign</h2></div>
        <div class="card__body">
          <p class="mt-0">
            <a href="/campaigns/<?= (int) $highlights['worst']['id'] ?>">
              <strong><?= e((string) $highlights['worst']['name']) ?></strong>
            </a>
          </p>
          <div class="flex-between small">
            <span><?= number_format((int) $highlights['worst']['clicked']) ?> people clicked</span>
            <strong><?= $highlights['worst']['click_rate'] ?>%</strong>
          </div>
          <p class="tiny muted mb-0">
            Only campaigns that reached at least <?= (int) $highlights['minimum'] ?> people are compared —
            a handful of test sends would otherwise come top every time.
          </p>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2>Which numbers to trust</h2></div>
  <div class="card__body small">
    <p class="mt-0"><?= e($caveat) ?></p>
    <p class="mb-0">
      We work out what your email is worth using a
      <strong><?= e(str_replace('_', ' ', (string) ($organisation['attribution_model'] ?? 'last_click'))) ?></strong>
      rule over <strong><?= (int) ($organisation['attribution_window_days'] ?? 30) ?> days</strong>: if
      somebody clicks your email and buys within that time, the sale is counted here. You can change
      both in Settings.
    </p>
  </div>
</div>

<?php $__view->endSection(); ?>
