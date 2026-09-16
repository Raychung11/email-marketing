<?php
$__view->extend('layouts.app');
$title   = 'Inbox delivery';
$totals  = $report['totals'];
$verdict = $report['verdict'];

$verdictClass = match ($verdict['level']) {
    'good'  => 'alert--success',
    'watch' => 'alert--warning',
    'bad'   => 'alert--danger',
    default => 'alert--info',
};
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Is your email getting through?</h1>
    <p>The last <?= (int) $days ?> days.</p>
  </div>
  <div class="page-head__actions">
    <form method="get" action="/analytics/deliverability">
      <select name="days" data-auto-submit>
        <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 3 months'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $days === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn--sm" type="submit">Go</button></noscript>
    </form>
  </div>
</div>

<div class="alert <?= e($verdictClass) ?>">
  <strong><?= e($verdict['headline']) ?></strong>
  <?= e($verdict['detail']) ?>
</div>

<div class="stats mb-2">
  <div class="stat">
    <div class="stat__label">Sent</div>
    <div class="stat__value"><?= number_format((int) $totals['sent']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Arrived</div>
    <div class="stat__value"><?= $totals['delivery_rate'] ?>%</div>
    <div class="stat__meta"><?= number_format((int) $totals['delivered']) ?> emails</div>
  </div>
  <div class="stat">
    <div class="stat__label">Address did not exist</div>
    <div class="stat__value"><?= $totals['bounce_rate'] ?>%</div>
    <div class="stat__meta">
      keep under <?= round($report['thresholds']['bounce'], 1) ?>%
    </div>
  </div>
  <div class="stat">
    <div class="stat__label">Marked as spam</div>
    <div class="stat__value"><?= $totals['complaint_rate'] ?>%</div>
    <div class="stat__meta">
      keep under <?= round($report['thresholds']['complaint'], 2) ?>%
    </div>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>Where your customers read email</h2></div>
      <div class="card__body card__body--tight">
        <?php if ($report['providers'] === []): ?>
          <div class="empty"><p>Nothing sent in this period.</p></div>
        <?php else: ?>
          <p class="small muted" style="padding:12px 14px 0;margin:0">
            Trouble is usually lopsided: Gmail quietly sending your email to junk while Outlook
            delivers it fine does not show up in the numbers above. Compare the rows.
          </p>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>Email provider</th><th class="num">Sent</th><th class="num">Arrived</th>
                    <th class="num">Clicked</th><th class="num">Bounced</th><th class="num">Spam</th></tr>
              </thead>
              <tbody>
                <?php foreach ($report['providers'] as $provider): ?>
                  <tr>
                    <td>
                      <strong><?= e((string) $provider['provider']) ?></strong>
                      <?php if ($provider['provider'] !== $provider['domain']): ?>
                        <div class="tiny muted"><?= e((string) $provider['domain']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="num"><?= number_format((int) $provider['sent']) ?></td>
                    <td class="num"><strong><?= $provider['delivery_rate'] ?>%</strong></td>
                    <td class="num"><?= $provider['click_rate'] ?>%</td>
                    <td class="num">
                      <?= (int) $provider['bounced'] > 0 ? number_format((int) $provider['bounced']) : '—' ?>
                    </td>
                    <td class="num">
                      <?= (int) $provider['complained'] > 0 ? number_format((int) $provider['complained']) : '—' ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($report['daily'] !== []): ?>
      <div class="card">
        <div class="card__head"><h2>Day by day</h2></div>
        <div class="card__body card__body--tight">
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>Day</th><th class="num">Sent</th><th class="num">Arrived</th>
                    <th class="num">Bounced</th><th class="num">Spam</th></tr>
              </thead>
              <tbody>
                <?php foreach (array_reverse($report['daily']) as $day): ?>
                  <tr>
                    <td class="small nowrap"><?= e((string) $day['date']) ?></td>
                    <td class="num"><?= number_format((int) $day['sent']) ?></td>
                    <td class="num"><?= number_format((int) $day['delivered']) ?></td>
                    <td class="num"><?= (int) $day['bounced'] > 0 ? number_format((int) $day['bounced']) : '—' ?></td>
                    <td class="num"><?= (int) $day['complained'] > 0 ? number_format((int) $day['complained']) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Your email set-up</h2></div>
      <div class="card__body">
        <?php if ($domains === []): ?>
          <p class="small mt-0">
            You have not set up a web address to send from yet, which is the single biggest reason
            email lands in junk.
          </p>
          <a class="btn btn--primary btn--sm btn--block" href="/settings/domains">Set it up</a>
        <?php else: ?>
          <?php foreach ($domains as $domain): ?>
            <div class="flex-between small">
              <span><?= e((string) $domain['domain']) ?></span>
              <span class="badge <?= (string) $domain['status'] === 'verified' ? 'badge--success' : 'badge--warning' ?>">
                <?= (string) $domain['status'] === 'verified' ? 'working' : 'needs attention' ?>
              </span>
            </div>
          <?php endforeach; ?>
          <a class="btn btn--sm btn--block mt-2" href="/settings/domains">Check the set-up</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($report['alerts'] !== []): ?>
      <div class="card">
        <div class="card__head"><h2>Warnings we have raised</h2></div>
        <div class="card__body">
          <?php foreach ($report['alerts'] as $alert): ?>
            <div class="small" style="margin-bottom:10px">
              <span class="badge <?= (string) $alert['severity'] === 'critical' ? 'badge--danger' : 'badge--warning' ?>">
                <?= e(substr((string) $alert['created_at'], 0, 10)) ?>
              </span>
              <div class="tiny" style="margin-top:4px"><?= e((string) $alert['message']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><h2>How to stay in the inbox</h2></div>
      <div class="card__body small">
        <p class="mt-0">Three things matter more than anything else:</p>
        <p>
          <strong>1. Only email people who asked.</strong> Every list you buy costs you the ability to
          reach the customers you already have.
        </p>
        <p>
          <strong>2. Stop emailing people who never open anything.</strong> After a year of silence
          they are not going to start, and Gmail notices that nobody engages with your mail.
        </p>
        <p class="mb-0">
          <strong>3. Send regularly rather than in bursts.</strong> A quiet six months followed by
          ten thousand emails in an hour looks exactly like a hacked account.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
