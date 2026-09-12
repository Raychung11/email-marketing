<?php
$__view->extend('layouts.app');
$title  = (string) $domain['domain'];
$status = (string) $domain['status'];

$steps = ['Add domain', 'Get records', 'Publish DNS', 'Verify', 'Send a test'];

$currentStep = match (true) {
    $status === 'verified' => 5,
    (string) $domain['dkim_status'] === 'failed' => 3,
    default => 3,
};
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e((string) $domain['domain']) ?></h1>
    <p>
      <span class="badge <?= $status === 'verified' ? 'badge--success' : ($status === 'failed' ? 'badge--danger' : 'badge--warning') ?> badge--dot">
        <?= e($status) ?>
      </span>
      <?php if (!empty($domain['verified_at'])): ?>
        <span class="muted small">verified <?= e(substr((string) $domain['verified_at'], 0, 16)) ?> UTC</span>
      <?php endif; ?>
    </p>
  </div>
  <div class="page-head__actions">
    <form method="post" action="/settings/domains/<?= (int) $domain['id'] ?>/verify">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <button class="btn btn--primary" type="submit">Check DNS now</button>
    </form>
    <form method="post" action="/settings/domains/<?= (int) $domain['id'] ?>/delete"
          data-confirm="Remove this domain? Campaigns sending from it will stop validating.">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <button class="btn btn--danger" type="submit">Remove</button>
    </form>
  </div>
</div>

<?= $__view->include('partials.import_steps', ['steps' => $steps, 'current' => $currentStep]) ?>

<?php if ($findings !== []): ?>
  <div class="card mb-2">
    <div class="card__head"><h2>Last check</h2></div>
    <div class="card__body card__body--tight">
      <table class="data">
        <tbody>
          <?php foreach ($findings as $finding): ?>
            <tr>
              <td style="width:90px">
                <span class="badge <?= $finding['status'] === 'verified' ? 'badge--success' : ($finding['status'] === 'failed' ? 'badge--danger' : 'badge--warning') ?>">
                  <?= e((string) $finding['record']) ?>
                </span>
              </td>
              <td class="small"><?= e((string) $finding['message']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php elseif (!empty($domain['last_error'])): ?>
  <div class="alert alert--warning">
    <strong>Last check did not pass</strong>
    <?= e((string) $domain['last_error']) ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <h2>DNS records to publish</h2>
    <div class="card__actions">
      <form method="post" action="/settings/domains/<?= (int) $domain['id'] ?>/refresh">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <button class="btn btn--sm" type="submit">Refresh from provider</button>
      </form>
    </div>
  </div>
  <div class="card__body">
    <p class="small muted mt-0">
      Add these at your DNS host — wherever you manage the domain, which is often your registrar or
      Cloudflare. Publish <strong>all</strong> of the DKIM records: two out of three does not work.
      Propagation usually takes minutes but can take up to 72 hours.
    </p>

    <?php if ($domain['dns_records'] === []): ?>
      <div class="alert alert--warning" style="margin-bottom:0">
        No records have been issued yet. Press <em>Refresh from provider</em>. If your email provider
        is not configured on this installation, the records will appear once it is.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Type</th><th>Name / host</th><th>Value</th></tr></thead>
          <tbody>
            <?php foreach ($domain['dns_records'] as $record): ?>
              <tr>
                <td><span class="badge"><?= e((string) $record['type']) ?></span></td>
                <td class="mono" style="word-break:break-all"><?= e((string) $record['name']) ?></td>
                <td class="mono" style="word-break:break-all">
                  <?= e((string) $record['value']) ?>
                  <div class="tiny muted" style="font-family:inherit"><?= e((string) $record['purpose']) ?></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>Current status</h2></div>
      <div class="card__body card__body--tight">
        <table class="data">
          <tbody>
            <tr>
              <td>
                <strong>DKIM</strong>
                <div class="tiny muted">Required to send. Signs your mail so receivers can verify it.</div>
              </td>
              <td class="right">
                <span class="badge <?= (string) $domain['dkim_status'] === 'verified' ? 'badge--success' : ((string) $domain['dkim_status'] === 'failed' ? 'badge--danger' : 'badge--warning') ?>">
                  <?= e((string) $domain['dkim_status']) ?>
                </span>
              </td>
            </tr>
            <tr>
              <td>
                <strong>SPF</strong>
                <div class="tiny muted">One record, authorising your provider. Never two.</div>
              </td>
              <td class="right">
                <span class="badge <?= (string) $domain['spf_status'] === 'verified' ? 'badge--success' : ((string) $domain['spf_status'] === 'failed' ? 'badge--danger' : 'badge--warning') ?>">
                  <?= e(str_replace('_', ' ', (string) $domain['spf_status'])) ?>
                </span>
              </td>
            </tr>
            <tr>
              <td>
                <strong>DMARC</strong>
                <div class="tiny muted">Recommended. Never blocks sending here.</div>
              </td>
              <td class="right">
                <span class="badge <?= (string) $domain['dmarc_status'] === 'verified' ? 'badge--success' : '' ?>">
                  <?= e(str_replace('_', ' ', (string) $domain['dmarc_status'])) ?>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Send a test</h2></div>
      <div class="card__body">
        <?php if ($status !== 'verified'): ?>
          <p class="small muted mb-0">Available once the domain is verified.</p>
        <?php else: ?>
          <form method="post" action="/settings/domains/<?= (int) $domain['id'] ?>/test">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <div class="field">
              <label for="recipient">Send to</label>
              <input id="recipient" type="email" name="recipient" required
                     value="<?= e((string) ($currentUser['email'] ?? '')) ?>">
              <div class="hint">Check the message lands in the inbox, not the spam folder.</div>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Send test message</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($status === 'verified'): ?>
      <div class="card">
        <div class="card__head"><h2>Next</h2></div>
        <div class="card__body small">
          <p class="mt-0">
            Set a sender address on this domain in <a href="/settings#sender">Settings</a>, then
            build your first campaign.
          </p>
          <a class="btn btn--sm btn--block" href="/campaigns/create">Create a campaign</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $__view->endSection(); ?>
