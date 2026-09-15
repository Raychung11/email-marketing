<?php
/** Public marketing layout. No sidebar, no session chrome, no auth required. */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? ($appName ?? 'AI Growth Hub')) ?></title>
<meta name="description" content="<?= e($metaDescription ?? 'Email marketing, CRM and customer retention for small businesses — with consent, compliance and deliverability handled for you.') ?>">
<meta name="theme-color" content="#0f172a">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/marketing.css">
</head>
<body class="marketing">

<header class="m-nav">
  <div class="m-wrap m-nav__inner">
    <a class="m-logo" href="/"><?= e($appName ?? 'AI Growth Hub') ?></a>

    <nav class="m-nav__links" aria-label="Sections">
      <a href="#what">What it does</a>
      <a href="#limits">What it won't do</a>
      <a href="#pricing">Pricing</a>
    </nav>

    <div class="m-nav__actions">
      <a class="m-link" href="/login">Sign in</a>
      <a class="m-btn m-btn--primary" href="/register">Start free</a>
    </div>
  </div>
</header>

<main id="main">
  <?= $__view->section('content') ?>
</main>

<footer class="m-foot">
  <div class="m-wrap m-foot__inner">
    <div>
      <strong><?= e($appName ?? 'AI Growth Hub') ?></strong>
      <p class="m-foot__note">Customer growth &amp; retention for small businesses.</p>
    </div>
    <nav class="m-foot__links" aria-label="Footer">
      <a href="/login">Sign in</a>
      <a href="/register">Create an account</a>
      <a href="#pricing">Pricing</a>
    </nav>
  </div>
  <div class="m-wrap m-foot__legal">
    &copy; <?= date('Y') ?> <?= e($appName ?? 'AI Growth Hub') ?>. Built for businesses in the United States and Australia.
  </div>
</footer>

<script src="/assets/js/marketing.js" defer></script>
</body>
</html>
