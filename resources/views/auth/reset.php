<?php $__view->extend('layouts.auth'); ?>
<?php $title = 'Choose a new password'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:17px;margin:0 0 4px">Choose a new password</h1>
<p class="muted small mb-2">Signing in elsewhere will require the new password.</p>

<form method="post" action="/reset-password">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
  <input type="hidden" name="token" value="<?= e($token) ?>">

  <div class="field">
    <label for="password">New password</label>
    <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password" autofocus>
    <div class="hint">At least 12 characters.</div>
  </div>

  <div class="field">
    <label for="password_confirmation">Confirm new password</label>
    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
  </div>

  <button class="btn btn--primary btn--block" type="submit">Change password</button>
</form>

<?php $__view->endSection(); ?>
