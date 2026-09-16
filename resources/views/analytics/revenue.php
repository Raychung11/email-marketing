<?php $__view->extend('layouts.app'); $title = 'Sales from email'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Sales from email</h1>
    <p>The last <?= (int) $days ?> days.</p>
  </div>
</div>

<div class="stats mb-2">
  <div class="stat stat--accent">
    <div class="stat__label">Sales we can trace to an email</div>
    <div class="stat__value"><?= e(money((float) $summary['attributed_value'], (string) $currency)) ?></div>
    <div class="stat__meta">
      of <?= e(money((float) $summary['total_value'], (string) $currency)) ?> in total
    </div>
  </div>
  <div class="stat">
    <div class="stat__label">Share of everything</div>
    <div class="stat__value"><?= $summary['attributed_share'] ?>%</div>
  </div>
  <div class="stat">
    <div class="stat__label">Sales</div>
    <div class="stat__value"><?= number_format((int) $summary['conversions']) ?></div>
    <div class="stat__meta"><?= number_format((int) $summary['attributed_conversions']) ?> traced to email</div>
  </div>
  <div class="stat">
    <div class="stat__label">Enquiries won</div>
    <div class="stat__value"><?= number_format((int) $leads['won']) ?></div>
    <div class="stat__meta"><?= $leads['win_rate'] ?>% of enquiries</div>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>Month by month</h2></div>
      <div class="card__body">
        <div style="height:260px">
          <canvas id="revenueChart"
                  data-months='<?= e(json_encode($monthly, JSON_UNESCAPED_SLASHES)) ?>'></canvas>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Which campaigns brought in work</h2></div>
      <div class="card__body card__body--tight">
        <?php if ($campaigns === []): ?>
          <div class="empty">
            <h3>Nothing traced yet</h3>
            <p>Once your website or till tells us about a sale, we work out which email led to it.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Campaign</th><th class="num">Sales</th><th class="num">Worth</th></tr></thead>
              <tbody>
                <?php foreach ($campaigns as $campaign): ?>
                  <tr>
                    <td>
                      <a href="/campaigns/<?= (int) $campaign['id'] ?>">
                        <strong><?= e((string) $campaign['name']) ?></strong>
                      </a>
                    </td>
                    <td class="num"><?= number_format((int) $campaign['conversions']) ?></td>
                    <td class="num"><strong><?= e(money((float) $campaign['value'], (string) $currency)) ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>How we decide</h2></div>
      <div class="card__body small">
        <p class="mt-0">
          If somebody presses a link in one of your emails and then buys within
          <strong><?= (int) $summary['window_days'] ?> days</strong>, we count that sale here.
        </p>
        <p>
          We use the <strong>last</strong> email they pressed before buying. It is the simplest
          rule there is, and that is on purpose: you can check it yourself. "They pressed the link
          in Tuesday's email, then booked on Thursday" is something you can go and verify. A
          cleverer rule that nobody can check is a rule nobody should believe.
        </p>
        <p>
          <strong>A click, never an open.</strong> An open might just be a mail server fetching an
          image. A click means a person did something.
        </p>
        <p class="mb-0">
          We always show what email brought in <em>next to</em> your total, never on its own.
          Most sales have more than one cause, and a number that pretends otherwise is not much
          use to you.
        </p>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Not seeing anything?</h2></div>
      <div class="card__body small">
        <p class="mt-0 mb-0">
          We only know about a sale if your website or booking system tells us. That is a one-off
          job for whoever looks after your site — send them
          <a href="/settings/api">the connection details</a>.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
