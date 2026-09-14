<?php
/** Guest layout: sign in, register, password reset, invitations. */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Sign in') ?> &middot; <?= e($appName ?? 'AI Growth Hub') ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="auth-shell">
  <div class="auth-card <?= !empty($wide) ? 'auth-card--wide' : '' ?>">
    <div class="auth-brand">
      <strong><?= e($appName ?? 'AI Growth Hub') ?></strong>
      <p>AI customer growth &amp; retention platform</p>
    </div>

    <?= $__view->include('partials.flash', [
        'success' => $success ?? null,
        'error'   => $error ?? null,
        'warning' => $warning ?? null,
        'errors'  => $errors ?? [],
    ]) ?>

    <div class="card">
      <div class="card__body">
        <?= $__view->section('content') ?>
      </div>
    </div>

    <p class="tiny muted mt-2" style="text-align:center">
      <?= $__view->section('footer', '') ?>
    </p>
  </div>
</div>
<script src="/assets/js/password-toggle.js" defer></script>
</body>
</html>
