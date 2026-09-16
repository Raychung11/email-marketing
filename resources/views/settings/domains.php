<?php $__view->extend('layouts.app'); $title = 'Your email address'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Your email address</h1>
    <p>Prove you own your website address, and your email lands in the inbox. Skip this and it lands
    in spam.</p>
  </div>
</div>

<?php if ($domains === []): ?>
  <div class="alert alert--info">
    <strong>You need to do this before you can send anything</strong>
    Gmail and Outlook only trust email that can prove where it came from. Until you finish this,
    your emails get filtered out — and anyone else could send email pretending to be you. It is a
    few settings at your domain provider and takes about ten minutes.
  </div>
<?php endif; ?>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__body card__body--tight">
        <?php if ($domains === []): ?>
          <div class="empty">
            <h3>Nothing set up yet</h3>
            <p>Start with the web address your customers already know — the one on your website
            and your van.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>Web address</th><th>DKIM</th><th>SPF</th><th>DMARC</th><th>Last checked</th><th></th></tr>
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
      <div class="card__head"><h2>What these three things are</h2></div>
      <div class="card__body small">
        <p class="mt-0">
          They have unfriendly names because they were invented by engineers, but each one is simple.
          You are copying three settings into whoever looks after your web address — GoDaddy,
          Crazy Domains, Squarespace, your web person.
        </p>
        <p>
          <strong>DKIM</strong> puts an invisible signature on every email you send, so Gmail can
          check it really came from you and nobody changed it on the way.
          <strong>You must have this</strong> — we will not let you send a campaign without it.
        </p>
        <p>
          <strong>SPF</strong> is the list of who is allowed to send email using your web address.
          You can only have one of these. If you already have an SPF line, add us to it — do not
          create a second one, because two of them stops both from working. This is the mistake
          almost everyone makes.
        </p>
        <p class="mb-0">
          <strong>DMARC</strong> tells Gmail what to do if something looks wrong, and emails you a
          weekly report. We recommend it but we will never block you for not having it. Start gently:
          turning it up to full strength before you know everything that sends email as you is how
          people accidentally stop their own invoices arriving.
        </p>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Add your web address</h2></div>
      <div class="card__body">
        <form method="post" action="/settings/domains">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="domain">Web address</label>
            <input id="domain" type="text" name="domain" required maxlength="190" placeholder="perthplumbing.com.au">
            <div class="hint">
              Paste your website or your email address — we will work out the rest. Use the one your
              customers know you by, not a similar-looking spare.
            </div>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Add it</button>
        </form>
      </div>
    </div>

    <?php if (empty($organisation['default_sender_email'])): ?>
      <div class="card">
        <div class="card__head"><h2>What happens next</h2></div>
        <div class="card__body small">
          <p class="mt-0 mb-0">
            Once this is working, choose the address your emails come from in
            <a href="/settings#sender">Settings</a>. It has to use the web address you set up here.
          </p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $__view->endSection(); ?>
