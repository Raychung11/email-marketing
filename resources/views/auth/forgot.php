<?php $__view->extend('layouts.auth'); ?>
<?php $title = 'Reset your password'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:17px;margin:0 0 4px">Reset your password</h1>
<p class="muted small mb-2">
  Enter your email address and we will send you a link. The link expires in an hour
  and can only be used once.
</p>

<form method="post" action="/forgot-password">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="field">
    <label for="email">Email address</label>
    <input id="email" type="email" name="email" required autofocus value="<?= e($old['email'] ?? '') ?>">
  </div>

  <button class="btn btn--primary btn--block" type="submit">Send reset link</button>
</form>

<?php $__view->endSection(); ?>

<?php $__view->startSection('footer'); ?>
  <a href="/login">Back to sign in</a>
<?php $__view->endSection(); ?>
