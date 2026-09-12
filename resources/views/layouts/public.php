<?php
/**
 * Public layout for pages a recipient sees: unsubscribe, preference centre.
 *
 * No navigation, no organisation switcher, nothing that assumes a session — the
 * visitor is a member of the public, not a user of the product.
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Email preferences') ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="public-shell">
  <div class="public-card">
    <?= $__view->section('content') ?>
  </div>

  <p class="tiny muted mt-2" style="text-align:center">
    <?php if (!empty($organisation['name'])): ?>
      Sent by <?= e((string) $organisation['name']) ?>
      <?php if (!empty($organisation['address_line1'])): ?>
        <br><?= e(trim(implode(', ', array_filter([
            (string) ($organisation['address_line1'] ?? ''),
            (string) ($organisation['address_city'] ?? ''),
            (string) ($organisation['address_state'] ?? ''),
            (string) ($organisation['address_postcode'] ?? ''),
            (string) ($organisation['address_country'] ?? ''),
        ])))) ?>
      <?php endif; ?>
    <?php endif; ?>
  </p>
</div>
</body>
</html>
