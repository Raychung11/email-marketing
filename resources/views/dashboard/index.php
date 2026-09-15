<?php
/**
 * Dashboard.
 *
 * Leads with business outcomes and with actions. Open rate appears, but labelled
 * as unreliable — building a business on it would mislead the user, so the tile
 * says so rather than quietly presenting it as fact.
 */
$__view->extend('layouts.app');
$title = 'Dashboard';

$t   = $metrics['totals'];
$em  = $metrics['email'];
$rev = $metrics['revenue'];
$lead = $metrics['leads'];
$currency = $metrics['currency'];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <p>
      <?= e((string) ($organisation['name'] ?? '')) ?> &middot;
      last 30 days &middot; times shown in <?= e($metrics['timezone']) ?>
    </p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/contacts/import">Import contacts</a>
    <a class="btn btn--primary" href="/contacts/create">Add contact</a>
  </div>
</div>

<?php if (!$progress['completed']): ?>
  <div class="card mb-2">
    <div class="card__body">
      <div class="flex-between mb-1">
        <strong>Finish setting up — step <?= (int) $progress['step'] ?> of <?= (int) $progress['total'] ?></strong>
        <a class="btn btn--sm btn--primary" href="/onboarding">Continue setup</a>
      </div>
      <div class="progress-track"><div class="progress-bar" style="width:<?= (int) $progress['percent'] ?>%"></div></div>
      <div class="flex wrap mt-1">
        <?php foreach ($metrics['checklist'] as $item): ?>
          <span class="badge <?= $item['done'] ? 'badge--success' : ($item['critical'] ? 'badge--warning' : '') ?>">
            <?= $item['done'] ? '✓' : '•' ?> <?= e($item['label']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Business outcomes first. -->
<div class="stats mb-2">
  <div class="stat">
    <div class="stat__label">Contacts</div>
    <div class="stat__value"><?= number_format($t['contacts']) ?></div>
    <div class="stat__meta">+<?= number_format($t['new_contacts_30d']) ?> in 30 days</div>
  </div>
  <div class="stat">
    <div class="stat__label">Customers</div>
    <div class="stat__value"><?= number_format($t['customers']) ?></div>
    <div class="stat__meta"><?= number_format($t['leads']) ?> leads</div>
  </div>
  <div class="stat stat--accent">
    <div class="stat__label">Sales from email</div>
    <div class="stat__value"><?= e(money($rev['attributed_value'], $currency)) ?></div>
    <div class="stat__meta"><?= number_format($rev['attributed_conversions']) ?> attributed conversions</div>
  </div>
  <div class="stat">
    <div class="stat__label">Conversions</div>
    <div class="stat__value"><?= number_format($rev['conversions']) ?></div>
    <div class="stat__meta"><?= e(money($rev['total_value'], $currency)) ?> total value</div>
  </div>
  <div class="stat">
    <div class="stat__label">Leads won</div>
    <div class="stat__value"><?= number_format($lead['won']) ?></div>
    <div class="stat__meta"><?= $lead['conversion_rate'] ?>% of all leads</div>
  </div>
  <div class="stat">
    <div class="stat__label">Lifetime revenue</div>
    <div class="stat__value"><?= e(money($t['lifetime_revenue'], $currency)) ?></div>
    <div class="stat__meta">across all contacts</div>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head">
        <h2>Worth doing next</h2>
        <div class="card__actions"><span class="badge">From your data</span></div>
      </div>
      <div class="card__body">
        <?php if ($recommendations === []): ?>
          <div class="empty" style="padding:24px">
            <h3>Nothing needs you right now</h3>
            <p>Once you have sent a few campaigns we will start pointing out things worth doing.</p>
          </div>
        <?php else: ?>
          <?php foreach ($recommendations as $rec): ?>
            <div class="rec">
              <div class="rec__body">
                <div class="rec__title"><?= e($rec['title']) ?></div>
                <div class="rec__text"><?= e($rec['body']) ?></div>
                <div class="rec__meta">
                  <span class="badge <?= $rec['impact'] === 'high' ? 'badge--warning' : '' ?>">
                    <?= e(ucfirst($rec['impact'])) ?> impact
                  </span>
                  <!-- Observed / calculated / AI-recommended is always stated. -->
                  <span class="badge badge--info"><?= e(str_replace('_', ' ', $rec['data_basis'])) ?></span>
                </div>
              </div>
              <div>
                <a class="btn btn--sm btn--primary" href="<?= e($rec['action_url']) ?>">
                  <?= e($rec['action_label']) ?>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>New contacts</h2></div>
      <div class="card__body">
        <canvas id="growthChart" height="110"
                data-growth='<?= e(json_encode(array_map(static fn (array $r): array => [
                    'period' => (string) $r['period'],
                    'total'  => (int) $r['total'],
                ], $metrics['growth']), JSON_UNESCAPED_SLASHES)) ?>'></canvas>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Email, last 30 days</h2></div>
      <div class="card__body">
        <table class="data">
          <tbody>
            <tr><td>Sent</td><td class="num"><?= number_format($em['sent']) ?></td></tr>
            <tr><td>Delivered</td><td class="num"><?= $em['delivery_rate'] ?>%</td></tr>
            <tr>
              <td><strong>Click rate</strong><div class="tiny muted">The number worth watching</div></td>
              <td class="num"><strong><?= $em['click_rate'] ?>%</strong></td>
            </tr>
            <tr>
              <td>
                Open rate
                <div class="tiny muted">
                  Indicative only — mail privacy features inflate and distort opens.
                </div>
              </td>
              <td class="num muted"><?= $em['open_rate'] ?>%</td>
            </tr>
            <tr>
              <td>Bounce rate</td>
              <td class="num <?= $em['bounce_rate'] > 5 ? '' : '' ?>">
                <?= $em['bounce_rate'] ?>%
                <?php if ($em['bounce_rate'] > 5): ?><span class="badge badge--danger">High</span><?php endif; ?>
              </td>
            </tr>
            <tr>
              <td>Complaint rate</td>
              <td class="num">
                <?= $em['complaint_rate'] ?>%
                <?php if ($em['complaint_rate'] > 0.1): ?><span class="badge badge--danger">High</span><?php endif; ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Who you can email</h2></div>
      <div class="card__body">
        <div class="stat" style="border:0;box-shadow:none;padding:0">
          <div class="stat__label">You can email these</div>
          <div class="stat__value"><?= number_format($t['marketable']) ?></div>
          <div class="stat__meta">of <?= number_format($t['contacts']) ?> contacts</div>
        </div>

        <hr class="sep">

        <div class="flex-between small">
          <span>Said yes</span>
          <strong><?= number_format($metrics['consent']['granted'] ?? 0) ?></strong>
        </div>
        <div class="flex-between small">
          <span>Never asked</span>
          <strong><?= number_format($metrics['consent']['unknown'] ?? 0) ?></strong>
        </div>
        <div class="flex-between small">
          <span>Asked us to stop</span>
          <strong><?= number_format($metrics['consent']['withdrawn'] ?? 0) ?></strong>
        </div>
        <div class="flex-between small">
          <span>On the do-not-email list</span>
          <strong><?= number_format($t['suppressed']) ?></strong>
        </div>

        <a class="btn btn--sm btn--block mt-2" href="/compliance">Email rules</a>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Leads</h2></div>
      <div class="card__body">
        <div class="flex-between small"><span>Open</span><strong><?= number_format($lead['open']) ?></strong></div>
        <div class="flex-between small">
          <span>No first response yet</span>
          <strong><?= number_format($lead['awaiting_first_response']) ?></strong>
        </div>
        <div class="flex-between small">
          <span>Overdue follow-ups</span>
          <strong><?= number_format($lead['overdue_followups']) ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($activity !== []): ?>
  <div class="card mt-2">
    <div class="card__head"><h2>Recent activity</h2></div>
    <div class="card__body">
      <div class="timeline">
        <?php foreach ($activity as $item): ?>
          <div class="timeline__item">
            <div class="timeline__time"><?= e((string) $item['occurred_at']) ?> UTC</div>
            <div class="timeline__text">
              <?= e((string) ($item['description'] ?? $item['activity_type'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php $__view->endSection(); ?>

<?php $__view->startSection('scripts'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script src="/assets/js/charts.js" defer></script>
<?php $__view->endSection(); ?>
