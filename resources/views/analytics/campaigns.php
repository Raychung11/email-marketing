<?php
$__view->extend('layouts.app');
$title = 'How campaigns did';
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>How campaigns did</h1>
    <p>Every campaign you have sent in the last <?= (int) $days ?> days, side by side.</p>
  </div>
  <div class="page-head__actions">
    <form method="get" action="/analytics/campaigns">
      <select name="days" data-auto-submit>
        <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 3 months', 365 => 'Last year'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $days === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn--sm" type="submit">Go</button></noscript>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($campaigns === []): ?>
      <div class="empty">
        <h3>Nothing to report yet</h3>
        <p>Once you have sent a campaign, this is where you compare it with the next one.</p>
        <a class="btn btn--primary" href="/campaigns/create">Write a campaign</a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Campaign</th>
              <th>Sent</th>
              <th class="num">Arrived</th>
              <th class="num">Clicked</th>
              <th class="num">Opened</th>
              <th class="num">Bounced</th>
              <th class="num">Spam</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($campaigns as $campaign): ?>
              <tr>
                <td>
                  <a href="/campaigns/<?= (int) $campaign['id'] ?>">
                    <strong><?= e((string) $campaign['name']) ?></strong>
                  </a>
                  <?php if ((string) $campaign['subject'] !== ''): ?>
                    <div class="tiny muted"><?= e((string) $campaign['subject']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small muted nowrap">
                  <?= e(substr((string) $campaign['send_started_at'], 0, 10)) ?>
                  <div class="tiny"><?= number_format((int) $campaign['sent']) ?> people</div>
                </td>
                <td class="num">
                  <strong><?= $campaign['delivery_rate'] ?>%</strong>
                  <div class="tiny muted"><?= number_format((int) $campaign['delivered']) ?></div>
                </td>
                <td class="num">
                  <strong><?= $campaign['click_rate'] ?>%</strong>
                  <div class="tiny muted"><?= number_format((int) $campaign['clicked']) ?> people</div>
                </td>
                <td class="num muted">
                  <?= $campaign['open_rate'] ?>%
                  <div class="tiny muted">rough guide</div>
                </td>
                <td class="num">
                  <?php if ((int) $campaign['bounced'] > 0): ?>
                    <span class="badge <?= $campaign['bounce_rate'] > 5 ? 'badge--danger' : '' ?>">
                      <?= number_format((int) $campaign['bounced']) ?>
                    </span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <?php if ((int) $campaign['complained'] > 0): ?>
                    <span class="badge badge--danger"><?= number_format((int) $campaign['complained']) ?></span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
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
  <div class="card__head"><h2>Reading this table</h2></div>
  <div class="card__body small">
    <p class="mt-0">
      <strong>Clicked</strong> is the one to watch. It means somebody read far enough to press
      something, which is as close to interest as email gets.
    </p>
    <p>
      <strong>Opened</strong> is greyed out on purpose. <?= e($caveat) ?>
    </p>
    <p class="mb-0">
      <strong>Bounced</strong> and <strong>Spam</strong> are the ones to act on. A campaign with a
      lot of either is telling you something about where those addresses came from — and if it keeps
      happening, Gmail and Outlook start sending everything you write to junk.
      <a href="/analytics/deliverability">Check whether your email is getting through</a>.
    </p>
  </div>
</div>

<?php $__view->endSection(); ?>
