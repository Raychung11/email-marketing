<?php $__view->extend('layouts.app'); $title = 'Segments'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Segments</h1>
    <p>Rules that evaluate themselves. A segment always reflects who matches right now.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--primary" href="/segments/create">New segment</a>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($segments === []): ?>
      <div class="empty">
        <h3>No segments yet</h3>
        <p>Build one from any combination of location, spend, purchase recency, tags, engagement and custom fields.</p>
        <a class="btn btn--primary" href="/segments/create">Create a segment</a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>Segment</th><th>Rules</th><th class="num">Matching</th><th class="num">Can be emailed</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($segments as $segment): ?>
              <tr>
                <td>
                  <a href="/segments/<?= (int) $segment['id'] ?>"><strong><?= e((string) $segment['name']) ?></strong></a>
                  <?php if ((int) $segment['is_system'] === 1): ?>
                    <span class="badge tiny">starter</span>
                  <?php endif; ?>
                  <?php if ((string) $segment['created_via'] === 'ai'): ?>
                    <span class="badge badge--info tiny">AI suggested</span>
                  <?php endif; ?>
                  <?php if (!empty($segment['description'])): ?>
                    <div class="tiny muted"><?= e((string) $segment['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small muted"><?= e((string) $segment['summary']) ?></td>
                <td class="num">
                  <?= number_format((int) $segment['cached_count']) ?>
                  <?php if ($segment['stale']): ?>
                    <div class="tiny muted">needs refresh</div>
                  <?php endif; ?>
                </td>
                <td class="num"><strong><?= number_format((int) $segment['cached_eligible_count']) ?></strong></td>
                <td class="right">
                  <div class="flex" style="justify-content:flex-end">
                    <a class="btn btn--sm" href="/segments/<?= (int) $segment['id'] ?>">Open</a>
                    <a class="btn btn--sm" href="/segments/<?= (int) $segment['id'] ?>/edit">Edit</a>
                  </div>
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
  <div class="card__head"><h2>Why two numbers?</h2></div>
  <div class="card__body small">
    <p class="mt-0 mb-0">
      <strong>Matching</strong> is how many contacts meet the rules. <strong>Can be emailed</strong> is how
      many of those actually pass the suppression list and the consent rules for their country. The gap
      between them is the number a campaign report would otherwise quietly hide, so both are shown
      everywhere an audience is chosen.
    </p>
  </div>
</div>

<?php $__view->endSection(); ?>
