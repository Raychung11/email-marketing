<?php $__view->extend('layouts.app'); $title = 'Your profile'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Your profile</h1><p><?= e((string) $user['email']) ?></p></div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>Change password</h2></div>
      <div class="card__body">
        <form method="post" action="/settings/profile/password">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="current_password">Current password</label>
            <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
          </div>
          <div class="field">
            <label for="password">New password</label>
            <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">
            <div class="hint">At least 12 characters.</div>
          </div>
          <div class="field">
            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
          </div>
          <button class="btn btn--primary" type="submit">Change password</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Account</h2></div>
      <div class="card__body">
        <div class="flex-between small"><span>Email</span><strong><?= e((string) $user['email']) ?></strong></div>
        <div class="flex-between small"><span>Role</span><strong><?= e((string) ($roleKey ?? '—')) ?></strong></div>
        <div class="flex-between small">
          <span>Last sign-in</span>
          <strong class="tiny"><?= e((string) ($user['last_login_at'] ?? 'never')) ?></strong>
        </div>
        <div class="flex-between small">
          <span>Password changed</span>
          <strong class="tiny"><?= e(substr((string) ($user['password_changed_at'] ?? ''), 0, 10) ?: '—') ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
