<?php
/**
 * Setup wizard.
 *
 * Order is deliberate: identity and compliance context (country, timezone,
 * postal address) come before anything that can send, because they are
 * preconditions for a lawful marketing email rather than nice-to-haves.
 */
$__view->extend('layouts.app');
$title = 'Set up your account';
$step  = (int) $progress['step'];
$org   = $organisation;
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Set up your account</h1>
    <p>Step <?= $step ?> of <?= (int) $progress['total'] ?> — <?= e((string) $progress['label']) ?></p>
  </div>
  <div class="page-head__actions">
    <form method="post" action="/onboarding-skip">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <button class="btn btn--ghost" type="submit">Skip for now</button>
    </form>
  </div>
</div>

<div class="progress-track mb-2"><div class="progress-bar" style="width:<?= (int) $progress['percent'] ?>%"></div></div>

<div class="steps">
  <?php foreach ($steps as $number => $definition): ?>
    <div class="step <?= $number < $step ? 'is-done' : ($number === $step ? 'is-current' : '') ?>">
      <small>Step <?= (int) $number ?></small>
      <?= e((string) $definition['label']) ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2><?= e((string) ($steps[$step]['label'] ?? 'Setup')) ?></h2></div>
      <div class="card__body">
        <form method="post" action="/onboarding/<?= $step ?>">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

          <?php if ($step === 1): ?>
            <div class="field">
              <label for="name">Business name</label>
              <input id="name" type="text" name="name" required maxlength="200" value="<?= e((string) $org['name']) ?>">
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
                <div class="hint">Used to tailor AI suggestions and campaign templates.</div>
              </div>
              <div class="field">
                <label for="website">Website</label>
                <input id="website" type="text" name="website" maxlength="255" value="<?= e((string) ($org['website'] ?? '')) ?>">
              </div>
            </div>

          <?php elseif ($step === 2): ?>
            <p class="small muted mt-0">
              Country decides which marketing rules apply by default. Timezone decides how every
              date in the product is displayed — storage is always UTC.
            </p>
            <div class="grid-3">
              <div class="field">
                <label for="country">Country</label>
                <select id="country" name="country" required>
                  <?php foreach ($countries as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= ($org['country'] ?? 'US') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone" required>
                  <?php foreach ($timezones as $tz): ?>
                    <option value="<?= e($tz) ?>" <?= ($org['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                  <?php endforeach; ?>
                </select>
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

          <?php elseif ($step === 3): ?>
            <div class="alert alert--warning">
              <strong>This is a compliance requirement, not paperwork</strong>
              US commercial marketing email must include a valid physical postal address, and a
              campaign will not pass validation without one. It is rendered in every marketing footer.
            </div>
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
                <label for="address_city">City</label>
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
            <div class="grid-2">
              <div class="field">
                <label for="contact_phone">Contact phone</label>
                <input id="contact_phone" type="tel" name="contact_phone" maxlength="40" value="<?= e((string) ($org['contact_phone'] ?? '')) ?>">
              </div>
              <div class="field">
                <label for="contact_email">Contact email</label>
                <input id="contact_email" type="email" name="contact_email" maxlength="255" value="<?= e((string) ($org['contact_email'] ?? '')) ?>">
              </div>
            </div>

          <?php elseif ($step === 4): ?>
            <div class="alert alert--info">
              <strong>Sending domains arrive with the email engine in phase 2</strong>
              When it lands, this step walks you through the DKIM, SPF and DMARC records to publish,
              then verifies them. Until a domain is verified, marketing campaigns cannot be sent —
              unauthenticated mail does not reach inboxes.
            </div>
            <p class="small muted">You can continue and come back to this.</p>

          <?php elseif ($step === 5): ?>
            <div class="grid-2">
              <div class="field">
                <label for="default_sender_name">From name</label>
                <input id="default_sender_name" type="text" name="default_sender_name" maxlength="120"
                       value="<?= e((string) ($org['default_sender_name'] ?? $org['name'])) ?>">
                <div class="hint">What recipients see in their inbox. Your business name usually works best.</div>
              </div>
              <div class="field">
                <label for="default_sender_email">From address</label>
                <input id="default_sender_email" type="email" name="default_sender_email" maxlength="255"
                       value="<?= e((string) ($org['default_sender_email'] ?? '')) ?>">
              </div>
            </div>
            <div class="field">
              <label for="reply_to_email">Reply-to address</label>
              <input id="reply_to_email" type="email" name="reply_to_email" maxlength="255"
                     value="<?= e((string) ($org['reply_to_email'] ?? '')) ?>">
              <div class="hint">Use a mailbox somebody reads. Replies to marketing email are often the valuable ones.</div>
            </div>

          <?php elseif ($step === 6): ?>
            <p class="mt-0">Bring your customers in. You will be asked how the list was obtained — that
              answer decides what can be sent to them.</p>
            <div class="flex wrap">
              <a class="btn btn--primary" href="/contacts/import">Import a CSV</a>
              <a class="btn" href="/contacts/create">Add one by hand</a>
            </div>
            <hr class="sep">
            <p class="small muted mb-0">Continue when you are done, or skip and import later.</p>

          <?php elseif ($step === 7): ?>
            <label class="check">
              <input type="checkbox" name="require_campaign_approval" value="1"
                <?= (int) ($org['require_campaign_approval'] ?? 1) === 1 ? 'checked' : '' ?>>
              <span>
                Require a second person to approve campaigns before they send
                <div class="tiny muted">Recommended for any team larger than one. The author cannot approve their own campaign.</div>
              </span>
            </label>
            <hr class="sep">
            <p class="small muted mb-0">
              Consent rules themselves are set by the jurisdiction, not by preference, and cannot be
              switched off here. See the <a href="/compliance">compliance centre</a> for what applies
              to your contacts.
            </p>

          <?php else: ?>
            <p class="mt-0">You are set up. Campaign creation arrives with the email engine in phase 2 —
              in the meantime, the useful next step is to find the part of your list worth contacting first.</p>
            <div class="flex wrap">
              <a class="btn btn--primary" href="/segments/create">Build a segment</a>
              <a class="btn" href="/dashboard">Go to the dashboard</a>
            </div>
          <?php endif; ?>

          <div class="flex mt-2">
            <button class="btn btn--primary" type="submit">
              <?= $step >= (int) $progress['total'] ? 'Finish setup' : 'Save and continue' ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Setup checklist</h2></div>
      <div class="card__body">
        <?php foreach ($checklist as $item): ?>
          <div class="flex-between small" style="padding:4px 0">
            <span>
              <?= $item['done'] ? '✓' : '○' ?> <?= e((string) $item['label']) ?>
              <?php if ($item['critical'] && !$item['done']): ?>
                <div class="tiny" style="color:#b45309">Required before sending</div>
              <?php endif; ?>
            </span>
            <?php if (!$item['done']): ?>
              <a class="btn btn--sm btn--ghost" href="<?= e((string) $item['url']) ?>">Do it</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
