<?php $__view->extend('layouts.app'); $title = 'Sending domains'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Sending domains</h1>
    <p>Mail from a verified domain authenticates. Mail from an unverified one goes to spam.</p>
  </div>
</div>

<?php if ($domains === []): ?>
  <div class="alert alert--info">
    <strong>You cannot send marketing email until a domain is verified</strong>
    This is not a formality. Unauthenticated mail from a shared IP pool is filtered, and an
    unverified domain can be spoofed by anyone. Setup takes three DNS records and a few minutes.
  </div>
<?php endif; ?>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__body card__body--tight">
        <?php if ($domains === []): ?>
          <div class="empty">
            <h3>No sending domains yet</h3>
            <p>Add the domain your customers already recognise — the one on your website.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>Domain</th><th>DKIM</th><th>SPF</th><th>DMARC</th><th>Last checked</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($domains as $domain): ?>
                  <tr>
                    <td>
                      <a href="/settings/domains/<?= (int) $domain['id'] ?>">
                        <strong><?= e((string) $domain['domain']) ?></strong>
                      </a>
                      <div class="tiny">
                        <?php $status = (string) $domain['status']; ?>
                        <span class="badge <?= $status === 'verified' ? 'badge--success' : ($status === 'failed' ? 'badge--danger' : 'badge--warning') ?> badge--dot">
                          <?= e($status) ?>
                        </span>
                      </div>
                    </td>
                    <?php foreach (['dkim_status', 'spf_status', 'dmarc_status'] as $field): ?>
                      <?php $value = (string) $domain[$field]; ?>
                      <td>
                        <span class="badge <?= $value === 'verified' ? 'badge--success' : ($value === 'failed' ? 'badge--danger' : '') ?>">
                          <?= e($value === 'not_checked' ? 'not checked' : $value) ?>
                        </span>
                      </td>
                    <?php endforeach; ?>
                    <td class="small muted nowrap">
                      <?= e(substr((string) ($domain['last_checked_at'] ?? ''), 0, 16) ?: 'never') ?>
                    </td>
                    <td class="right">
                      <a class="btn btn--sm" href="/settings/domains/<?= (int) $domain['id'] ?>">Set up</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>What each record does</h2></div>
      <div class="card__body small">
        <p class="mt-0">
          <strong>DKIM</strong> signs every message with a private key your provider holds, so a
          receiver can prove the mail really came from you and was not altered in transit.
          <strong>Required</strong> — a campaign cannot be sent without it.
        </p>
        <p>
          <strong>SPF</strong> lists which servers may send as your domain. We check that exactly one
          SPF record exists and that it authorises your provider. Two SPF records is a hard failure in
          the specification, and it is the mistake people make most often — merge, never add a second.
        </p>
        <p class="mb-0">
          <strong>DMARC</strong> tells receivers what to do when DKIM and SPF disagree, and sends you
          reports. Recommended, never blocking here: publishing <span class="mono">p=reject</span>
          before you know what else sends as you is how invoicing quietly stops working.
        </p>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Add a domain</h2></div>
      <div class="card__body">
        <form method="post" action="/settings/domains">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="domain">Domain</label>
            <input id="domain" type="text" name="domain" required maxlength="190" placeholder="perthplumbing.com.au">
            <div class="hint">
              A URL or an email address is fine — we will reduce it to the domain. Use the domain your
              customers recognise, not a lookalike.
            </div>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Add domain</button>
        </form>
      </div>
    </div>

    <?php if (empty($organisation['default_sender_email'])): ?>
      <div class="card">
        <div class="card__head"><h2>Next</h2></div>
        <div class="card__body small">
          <p class="mt-0 mb-0">
            Once a domain is verified, set a sender address on it in
            <a href="/settings#sender">Settings</a>. The from-address must be on a verified domain.
          </p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $__view->endSection(); ?>
