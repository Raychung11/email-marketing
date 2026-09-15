<?php $__view->extend('layouts.app'); $title = 'Settings'; $org = $organisation; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Settings</h1><p>Your business details, where you operate, and who your email comes from.</p></div>
  <div class="page-head__actions">
    <a class="btn" href="/settings/brand">Brand profile</a>
    <a class="btn" href="/settings/custom-fields">Custom fields</a>
  </div>
</div>

<form method="post" action="/settings">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="card">
    <div class="card__head"><h2>Business</h2></div>
    <div class="card__body">
      <div class="field">
        <label for="name">Business name</label>
        <input id="name" type="text" name="name" required maxlength="200" value="<?= e((string) $org['name']) ?>">
        <div class="hint">Appears in the footer of every marketing email as the sender identity.</div>
      </div>

      <div class="grid-2">
        <div class="field">
          <label for="industry">Industry</label>
          <select id="industry" name="industry">
            <option value="">Not set</option>
            <?php foreach ($industries as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= ($org['industry'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="website">Website</label>
          <input id="website" type="text" name="website" maxlength="255" value="<?= e((string) ($org['website'] ?? '')) ?>">
        </div>
      </div>

      <div class="grid-3">
        <div class="field">
          <label for="country">Country</label>
          <select id="country" name="country" required>
            <?php foreach ($countries as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= ($org['country'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">Decides the default marketing rule set.</div>
        </div>
        <div class="field">
          <label for="timezone">Timezone</label>
          <select id="timezone" name="timezone" required>
            <?php foreach ($timezones as $tz): ?>
              <option value="<?= e($tz) ?>" <?= ($org['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">All dates are stored in UTC and displayed in this timezone.</div>
        </div>
        <div class="field">
          <label for="currency">Currency</label>
          <select id="currency" name="currency" required>
            <?php foreach ($currencies as $currency): ?>
              <option value="<?= e($currency) ?>" <?= ($org['currency'] ?? 'USD') === $currency ? 'selected' : '' ?>><?= e($currency) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card" id="address">
    <div class="card__head">
      <h2>Physical postal address</h2>
      <div class="card__actions"><span class="badge badge--warning">Required for marketing email</span></div>
    </div>
    <div class="card__body">
      <p class="small muted mt-0">
        US commercial marketing email must carry a valid physical postal address, and it is good
        practice everywhere. This address is rendered in the footer of every marketing send, and a
        campaign cannot pass validation without it.
      </p>

      <div class="field">
        <label for="address_line1">Address line 1</label>
        <input id="address_line1" type="text" name="address_line1" maxlength="200" value="<?= e((string) ($org['address_line1'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="address_line2">Address line 2</label>
        <input id="address_line2" type="text" name="address_line2" maxlength="200" value="<?= e((string) ($org['address_line2'] ?? '')) ?>">
      </div>
      <div class="grid-3">
        <div class="field">
          <label for="address_city">City / suburb</label>
          <input id="address_city" type="text" name="address_city" maxlength="120" value="<?= e((string) ($org['address_city'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="address_state">State / region</label>
          <input id="address_state" type="text" name="address_state" maxlength="120" value="<?= e((string) ($org['address_state'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="address_postcode">Postcode</label>
          <input id="address_postcode" type="text" name="address_postcode" maxlength="30" value="<?= e((string) ($org['address_postcode'] ?? '')) ?>">
        </div>
      </div>
      <div class="grid-3">
        <div class="field">
          <label for="address_country">Country</label>
          <select id="address_country" name="address_country">
            <option value="">Same as business country</option>
            <?php foreach ($countries as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= ($org['address_country'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="contact_phone">Contact phone</label>
          <input id="contact_phone" type="tel" name="contact_phone" maxlength="40" value="<?= e((string) ($org['contact_phone'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="contact_email">Contact email</label>
          <input id="contact_email" type="email" name="contact_email" maxlength="255" value="<?= e((string) ($org['contact_email'] ?? '')) ?>">
        </div>
      </div>
    </div>
    <div class="card__foot">
      <button class="btn btn--primary" type="submit">Save settings</button>
    </div>
  </div>
</form>

<form method="post" action="/settings/sender" id="sender">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
  <div class="card">
    <div class="card__head">
      <h2>Sender identity</h2>
      <div class="card__actions"><span class="badge badge--warning">Required for marketing email</span></div>
    </div>
    <div class="card__body">
      <div class="grid-2">
        <div class="field">
          <label for="default_sender_name">From name</label>
          <input id="default_sender_name" type="text" name="default_sender_name" maxlength="120"
                 value="<?= e((string) ($org['default_sender_name'] ?? $org['name'])) ?>">
        </div>
        <div class="field">
          <label for="default_sender_email">From address</label>
          <input id="default_sender_email" type="email" name="default_sender_email" maxlength="255"
                 value="<?= e((string) ($org['default_sender_email'] ?? '')) ?>">
          <div class="hint">Must be on a domain you have verified.</div>
        </div>
      </div>
      <div class="field">
        <label for="reply_to_email">Reply-to address</label>
        <input id="reply_to_email" type="email" name="reply_to_email" maxlength="255"
               value="<?= e((string) ($org['reply_to_email'] ?? '')) ?>">
        <div class="hint">Must be a monitored mailbox. Replies to marketing email are often the useful ones.</div>
      </div>
    </div>
    <div class="card__foot"><button class="btn btn--primary" type="submit">Save sender identity</button></div>
  </div>
</form>

<div class="card">
  <div class="card__head"><h2>Sending status</h2></div>
  <div class="card__body">
    <div class="grid-3">
      <div>
        <div class="stat__label">Trust level</div>
        <div><span class="badge"><?= e((string) $org['trust_level']) ?></span></div>
      </div>
      <div>
        <div class="stat__label">Daily send limit</div>
        <div><strong><?= number_format((int) $org['daily_send_limit']) ?></strong> emails</div>
      </div>
      <div>
        <div class="stat__label">Campaign approval</div>
        <div><?= (int) $org['require_campaign_approval'] === 1 ? 'Required' : 'Not required' ?></div>
      </div>
    </div>
    <p class="tiny muted mt-2 mb-0">
      New accounts start with a conservative daily limit. It increases as the account establishes a
      history of sending clean email that people want — this keeps the inbox open for every
      sender on the platform, including you.
    </p>
  </div>
</div>

<?php $__view->endSection(); ?>
