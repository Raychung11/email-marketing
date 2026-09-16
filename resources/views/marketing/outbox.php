<?php
/**
 * @var array<string,mixed> $result
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $filters
 * @var array<int,array{id:int,name:string}> $campaigns
 * @var array<int,string> $statuses
 * @var array<int,string> $windows
 */
$__view->extend('layouts.app');
$title = 'Sent email';
$rows  = $result['rows'];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Sent email</h1>
    <p>Every message this account has produced, and what became of it.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/outbox/export?<?= e(http_build_query(array_filter($filters))) ?>">Export CSV</a>
  </div>
</div>

<div class="stats mb-2">
  <div class="stat">
    <div class="stat__label">Sent</div>
    <div class="stat__value"><?= number_format((int) $summary['sent']) ?></div>
    <div class="stat__meta">accepted by the email service</div>
  </div>
  <div class="stat stat--accent">
    <div class="stat__label">Arrived</div>
    <div class="stat__value"><?= $summary['arrival_rate'] ?>%</div>
    <div class="stat__meta"><?= number_format((int) $summary['arrived']) ?> confirmed</div>
  </div>
  <div class="stat">
    <div class="stat__label">Still to go</div>
    <div class="stat__value"><?= number_format((int) $summary['waiting']) ?></div>
    <div class="stat__meta">queued or going out now</div>
  </div>
  <div class="stat">
    <div class="stat__label">Did not arrive</div>
    <div class="stat__value"><?= number_format((int) $summary['bounced']) ?></div>
    <div class="stat__meta">bounced back</div>
  </div>
  <div class="stat">
    <div class="stat__label">Never sent</div>
    <div class="stat__value"><?= number_format((int) $summary['failed']) ?></div>
    <div class="stat__meta">failed, refused or skipped</div>
  </div>
</div>

<?php if ((int) $summary['awaiting_news'] > 0): ?>
  <div class="alert alert--info">
    <strong>Why "Sent" is higher than "Arrived"</strong>
    <?= number_format((int) $summary['awaiting_news']) ?>
    <?= (int) $summary['awaiting_news'] === 1 ? 'message has' : 'messages have' ?>
    been accepted by Amazon but have not been confirmed as delivered yet. Confirmation normally
    lands within a minute or two. If it never does, the message is shown here as sent — that is the
    most we honestly know.
  </div>
<?php endif; ?>

<div class="card mb-2">
  <div class="card__body">
    <form method="get" action="/outbox" class="flex wrap">
      <input type="search" name="search" value="<?= e((string) $filters['search']) ?>"
             placeholder="Search for an address" style="max-width:240px">

      <select name="status" data-auto-submit style="max-width:220px">
        <option value="">Anything</option>
        <?php foreach ($statuses as $status): ?>
          <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
            <?= e(App\Support\MessageStatus::label($status)) ?>
            <?php $n = (int) ($summary['by_status'][$status] ?? 0); ?>
            <?= $n > 0 ? ' (' . number_format($n) . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="campaign" data-auto-submit style="max-width:220px">
        <option value="">Any campaign</option>
        <?php foreach ($campaigns as $campaign): ?>
          <option value="<?= (int) $campaign['id'] ?>" <?= (int) $filters['campaign'] === $campaign['id'] ? 'selected' : '' ?>>
            <?= e(App\Support\Str::limit($campaign['name'], 40)) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="class" data-auto-submit style="max-width:180px">
        <option value="">All email</option>
        <option value="marketing" <?= $filters['class'] === 'marketing' ? 'selected' : '' ?>>Campaigns only</option>
        <option value="transactional" <?= $filters['class'] === 'transactional' ? 'selected' : '' ?>>One-off and system email</option>
      </select>

      <select name="days" data-auto-submit style="max-width:160px">
        <?php foreach ($windows as $days => $label): ?>
          <option value="<?= (int) $days ?>" <?= (int) $filters['days'] === (int) $days ? 'selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button class="btn btn--sm btn--primary" type="submit">Filter</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($rows === []): ?>
      <div class="empty">
        <h3>Nothing here yet</h3>
        <p>
          <?php if ((string) $filters['search'] !== '' || (string) $filters['status'] !== '' || (int) $filters['campaign'] > 0): ?>
            No messages match what you are looking for. Try widening the date range.
          <?php else: ?>
            Every email you send — campaigns, journeys and test messages — is listed here the
            moment it is queued.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>To</th><th>Subject</th><th>What happened</th><th>Read</th><th>When</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td class="mono small"><?= e((string) $row['email']) ?></td>
                <td class="small">
                  <?= e(App\Support\Str::limit((string) ($row['subject'] ?? '—'), 50)) ?>
                  <?php if (($row['campaign_name'] ?? null) !== null): ?>
                    <div class="tiny muted">
                      <a href="/campaigns/<?= (int) $row['campaign_id'] ?>"><?= e(App\Support\Str::limit((string) $row['campaign_name'], 40)) ?></a>
                    </div>
                  <?php elseif ((string) $row['message_class'] === 'transactional'): ?>
                    <div class="tiny muted">One-off message</div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= e((string) $row['status_tone']) ?>"><?= e((string) $row['status_label']) ?></span>
                  <?php if (!empty($row['failure_reason'])): ?>
                    <div class="tiny muted" style="word-break:break-word">
                      <?= e(App\Support\Str::limit((string) $row['failure_reason'], 90)) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="small muted nowrap">
                  <?php if (($row['clicked_at'] ?? null) !== null): ?>
                    Clicked
                  <?php elseif (($row['opened_at'] ?? null) !== null): ?>
                    Opened<span class="tiny"> *</span>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="small muted nowrap"><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="tiny muted" style="padding:10px 14px 0">
        Times are UTC. * An "open" only means the images in the message were fetched, which some
        mail apps do on their own — treat clicks as the real signal.
      </p>
    <?php endif; ?>
  </div>

  <?php if ($result['pages'] > 1): ?>
    <div class="card__foot">
      <?= $__view->include('partials.pagination', [
          'page' => $result['page'], 'pages' => $result['pages'],
          'total' => $result['total'], 'query' => $filters,
      ]) ?>
    </div>
  <?php endif; ?>
</div>

<?php $__view->endSection(); ?>
