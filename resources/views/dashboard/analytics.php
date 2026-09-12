<?php
$__view->extend('layouts.app');
$title = 'Analytics';
$em    = $metrics['email'];
$rev   = $metrics['revenue'];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Analytics overview</h1>
    <p>Campaign, engagement and attribution reporting arrive with the sending engine in phase 2.</p>
  </div>
</div>

<div class="stats mb-2">
  <div class="stat">
    <div class="stat__label">Emails sent (30d)</div>
    <div class="stat__value"><?= number_format($em['sent']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Delivery rate</div>
    <div class="stat__value"><?= $em['delivery_rate'] ?>%</div>
  </div>
  <div class="stat">
    <div class="stat__label">Click rate</div>
    <div class="stat__value"><?= $em['click_rate'] ?>%</div>
    <div class="stat__meta">the metric that correlates with revenue</div>
  </div>
  <div class="stat">
    <div class="stat__label">Attributed revenue</div>
    <div class="stat__value"><?= e(money($rev['attributed_value'], $metrics['currency'])) ?></div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>What is measured here</h2></div>
  <div class="card__body small">
    <p class="mt-0">
      This platform reports on outcomes rather than vanity metrics. Opens are recorded but
      weighted low: mail privacy features in modern clients pre-fetch tracking pixels, which
      inflates opens and makes them a poor basis for a decision. Clicks and conversions carry
      the weight in every report and in every AI recommendation.
    </p>
    <p class="mb-0">
      Revenue attribution uses a
      <strong><?= e(str_replace('_', ' ', (string) ($organisation['attribution_model'] ?? 'last_click'))) ?></strong>
      model with a <strong><?= (int) ($organisation['attribution_window_days'] ?? 30) ?>-day</strong> window,
      configurable in Settings.
    </p>
  </div>
</div>

<?php $__view->endSection(); ?>
