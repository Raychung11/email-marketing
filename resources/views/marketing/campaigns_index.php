<?php $__view->extend('layouts.app'); $title = 'Campaigns'; $rows = $result['rows']; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Campaigns</h1>
    <p><?= number_format($result['total']) ?> campaigns</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--primary" href="/campaigns/create">New campaign</a>
  </div>
</div>

<div class="stats mb-2">
  <?php foreach (['draft' => 'Drafts', 'pending_review' => 'Awaiting review', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'completed' => 'Completed'] as $key => $label): ?>
    <div class="stat">
      <div class="stat__label"><?= e($label) ?></div>
      <div class="stat__value"><?= number_format($counts[$key] ?? 0) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mb-2">
  <div class="card__body">
    <form method="get" action="/campaigns" class="flex wrap">
      <input type="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Name or subject" style="max-width:240px">
      <select name="status" data-auto-submit style="max-width:200px">
        <option value="">Any status</option>
        <?php foreach ($statuses as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="type" data-auto-submit style="max-width:200px">
        <option value="">Any type</option>
        <?php foreach ($types as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $filters['type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn--sm btn--primary" type="submit">Filter</button>
      <a class="btn btn--sm btn--ghost" href="/campaigns">Clear</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($rows === []): ?>
      <div class="empty">
        <h3>No campaigns yet</h3>
        <p>A campaign needs a verified sending domain, an audience and content. The validator will
          tell you what is missing before anyone can approve it.</p>
        <a class="btn btn--primary" href="/campaigns/create">Create a campaign</a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Campaign</th><th>Status</th><th>Audience</th>
              <th class="num">Sent</th><th class="num">Clicks</th><th class="num">Revenue</th>
              <th>When</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $campaign): ?>
              <?php $status = (string) $campaign['status']; ?>
              <tr>
                <td>
                  <a href="/campaigns/<?= (int) $campaign['id'] ?>"><strong><?= e((string) $campaign['name']) ?></strong></a>
                  <div class="tiny muted"><?= e((string) ($campaign['subject'] ?? 'No subject yet')) ?></div>
                </td>
                <td>
                  <span class="badge <?= match ($status) {
                      'completed' => 'badge--success',
                      'sending', 'scheduled' => 'badge--info',
                      'pending_review', 'paused' => 'badge--warning',
                      'failed', 'cancelled' => 'badge--danger',
                      default => '',
                  } ?>"><?= e($statuses[$status] ?? $status) ?></span>
                </td>
                <td class="small muted">
                  <?= number_format((int) $campaign['eligible_count']) ?> eligible
                  <?php if ((int) $campaign['recipient_count'] > (int) $campaign['eligible_count']): ?>
                    <div class="tiny">of <?= number_format((int) $campaign['recipient_count']) ?> matching</div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= number_format((int) $campaign['sent_count']) ?></td>
                <td class="num">
                  <?= number_format((int) $campaign['unique_click_count']) ?>
                  <?php if ((int) $campaign['delivered_count'] > 0): ?>
                    <div class="tiny muted">
                      <?= round((int) $campaign['unique_click_count'] / (int) $campaign['delivered_count'] * 100, 1) ?>%
                    </div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= e(money((float) $campaign['attributed_revenue'], (string) ($campaign['currency'] ?: ($organisation['currency'] ?? 'USD')))) ?></td>
                <td class="small muted nowrap">
                  <?= e(substr((string) ($campaign['scheduled_at'] ?? $campaign['created_at']), 0, 16)) ?>
                </td>
                <td class="right"><a class="btn btn--sm" href="/campaigns/<?= (int) $campaign['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
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
