<?php $__view->extend('layouts.auth'); ?>
<?php $title = 'Sign in'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:17px;margin:0 0 4px">Sign in</h1>
<p class="muted small mb-2">Welcome back.</p>

<form method="post" action="/login">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="field">
    <label for="email">Email address</label>
    <input id="email" type="email" name="email" required autocomplete="username" autofocus
           value="<?= e($old['email'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="password">Password</label>
    <input id="password" type="password" name="password" required autocomplete="current-password">
  </div>

  <button class="btn btn--primary btn--block" type="submit">Sign in</button>
</form>

<p class="small mt-2" style="text-align:center">
  <a href="/forgot-password">Forgotten your password?</a>
</p>

<?php $__view->endSection(); ?>

<?php $__view->startSection('footer'); ?>
  No account yet? <a href="/register">Create one</a>
<?php $__view->endSection(); ?>
