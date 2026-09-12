<?php $__view->extend('layouts.auth'); ?>
<?php $title = 'Create your account'; $wide = true; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:17px;margin:0 0 4px">Create your account</h1>
<p class="muted small mb-2">
  Country and timezone decide which marketing rules apply to your contacts and how
  every date is displayed, so they are worth getting right now.
</p>

<form method="post" action="/register">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="field">
    <label for="organisation_name">Business name</label>
    <input id="organisation_name" type="text" name="organisation_name" required maxlength="200"
           value="<?= e($old['organisation_name'] ?? '') ?>" placeholder="e.g. Perth Plumbing Co">
  </div>

  <div class="grid-2">
    <div class="field">
      <label for="first_name">First name</label>
      <input id="first_name" type="text" name="first_name" maxlength="100" value="<?= e($old['first_name'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="last_name">Last name</label>
      <input id="last_name" type="text" name="last_name" maxlength="100" value="<?= e($old['last_name'] ?? '') ?>">
    </div>
  </div>

  <div class="field">
    <label for="email">Work email</label>
    <input id="email" type="email" name="email" required autocomplete="username" value="<?= e($old['email'] ?? '') ?>">
  </div>

  <div class="grid-2">
    <div class="field">
      <label for="password">Password</label>
      <input id="password" type="password" name="password" required autocomplete="new-password" minlength="12">
      <div class="hint">At least 12 characters.</div>
    </div>
    <div class="field">
      <label for="password_confirmation">Confirm password</label>
      <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
    </div>
  </div>

  <div class="grid-3">
    <div class="field">
      <label for="country">Country</label>
      <select id="country" name="country" required>
        <?php foreach ($countries as $code => $label): ?>
          <option value="<?= e($code) ?>" <?= ($old['country'] ?? 'US') === $code ? 'selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="timezone">Timezone</label>
      <select id="timezone" name="timezone" required>
        <?php foreach ($timezones as $tz): ?>
          <option value="<?= e($tz) ?>" <?= ($old['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="currency">Currency</label>
      <select id="currency" name="currency" required>
        <?php foreach ($currencies as $currency): ?>
          <option value="<?= e($currency) ?>" <?= ($old['currency'] ?? 'USD') === $currency ? 'selected' : '' ?>>
            <?= e($currency) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <button class="btn btn--primary btn--block mt-1" type="submit">Create account</button>
</form>

<?php $__view->endSection(); ?>

<?php $__view->startSection('footer'); ?>
  Already have an account? <a href="/login">Sign in</a>
<?php $__view->endSection(); ?>
