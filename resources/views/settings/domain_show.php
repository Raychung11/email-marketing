<?php
$__view->extend('layouts.app');
$title  = (string) $domain['domain'];
$status = (string) $domain['status'];

$steps = ['Add web address', 'Get the settings', 'Copy them across', 'We check them', 'Send yourself a test'];

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
      <button class="btn btn--primary" type="submit">Check it now</button>
    </form>
    <form method="post" action="/settings/domains/<?= (int) $domain['id'] ?>/delete"
          data-confirm="Remove this web address? Any campaign that sends from it will stop working.">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <button class="btn btn--danger" type="submit">Remove</button>
    </form>
  </div>
</div>

<?= $__view->include('partials.import_steps', ['steps' => $steps, 'current' => $currentStep]) ?>

<?php if ($findings !== []): ?>
  <div class="card mb-2">
    <div class="card__head"><h2>What we found last time we looked</h2></div>
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
    <strong>Not working yet</strong>
    <?= e((string) $domain['last_error']) ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <h2>Settings to copy across</h2>
    <div class="card__actions">
      <form method="post" action="/settings/domains/<?= (int) $domain['id'] ?>/refresh">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <button class="btn btn--sm" type="submit">Get them again</button>
      </form>
    </div>
  </div>
  <div class="card__body">
    <p class="small muted mt-0">
      Sign in wherever you bought your web address — GoDaddy, Crazy Domains, Squarespace,
      Cloudflare — and find the DNS settings. Add each line below exactly as it is written. Copy
      <strong>every</strong> DKIM line: two out of three will not work. If a web person looks after
      this for you, forward them this page.
    </p>
    <p class="small muted">
      After you save them it usually starts working within a few minutes, though it can take up to
      three days. You do not have to wait around — we keep checking and will mark it done.
    </p>

    <?php if ($domain['dns_records'] === []): ?>
      <div class="alert alert--warning" style="margin-bottom:0">
        Nothing to copy yet. Press <em>Get them again</em>. If your email sending service has not
        been connected on this installation, the settings will appear as soon as it is.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Type</th><th>Name / host</th><th>Value to paste</th></tr></thead>
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
      <div class="card__head"><h2>Where you are up to</h2></div>
      <div class="card__body card__body--tight">
        <table class="data">
          <tbody>
            <tr>
              <td>
                <strong>DKIM</strong>
                <div class="tiny muted">You must have this. It signs your email so Gmail can tell it really came from you.</div>
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
                <div class="tiny muted">Says who is allowed to send email as you. Only ever have one of these.</div>
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
                <div class="tiny muted">Nice to have. We will never stop you sending without it.</div>
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
      <div class="card__head"><h2>Send yourself a test</h2></div>
      <div class="card__body">
        <?php if ($status !== 'verified'): ?>
          <p class="small muted mb-0">You can do this once the settings above are working.</p>
        <?php else: ?>
          <form method="post" action="/settings/domains/<?= (int) $domain['id'] ?>/test">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <div class="field">
              <label for="recipient">Send to</label>
              <input id="recipient" type="email" name="recipient" required
                     value="<?= e((string) ($currentUser['email'] ?? '')) ?>">
              <div class="hint">Then go and look: did it land in the inbox, or in junk?</div>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Send me a test</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($status === 'verified'): ?>
      <div class="card">
        <div class="card__head"><h2>What happens next</h2></div>
        <div class="card__body small">
          <p class="mt-0">
            Choose the address your emails come from in <a href="/settings#sender">Settings</a>,
            then write your first campaign.
          </p>
          <a class="btn btn--sm btn--block" href="/campaigns/create">Create a campaign</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $__view->endSection(); ?>
