<?php $__view->extend('layouts.auth'); ?>
<?php $title = 'Accept your invitation'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:17px;margin:0 0 4px">Accept your invitation</h1>
<p class="muted small mb-2">Set a password to finish setting up your account.</p>

<form method="post" action="/invitations/accept">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
  <input type="hidden" name="token" value="<?= e($token) ?>">

  <div class="grid-2">
    <div class="field">
      <label for="first_name">First name</label>
      <input id="first_name" type="text" name="first_name" maxlength="100">
    </div>
    <div class="field">
      <label for="last_name">Last name</label>
      <input id="last_name" type="text" name="last_name" maxlength="100">
    </div>
  </div>

  <div class="field">
    <label for="password">Password</label>
    <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">
    <div class="hint">At least 12 characters.</div>
  </div>

  <div class="field">
    <label for="password_confirmation">Confirm password</label>
    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
  </div>

  <button class="btn btn--primary btn--block" type="submit">Join the team</button>
</form>

<?php $__view->endSection(); ?>
