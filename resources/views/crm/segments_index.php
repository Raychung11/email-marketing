<?php $__view->extend('layouts.app'); $title = 'Smart lists'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Smart lists</h1>
    <p>A list that keeps itself up to date. Set the rules once, and anyone who fits them is in it —
    today, and every day after.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--primary" href="/segments/create">New smart list</a>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($segments === []): ?>
      <div class="empty">
        <h3>No smart lists yet</h3>
        <p>Mix and match: where they live, how much they have spent, when they last bought, their
        tags, whether they open your email, and anything else you track.</p>
        <a class="btn btn--primary" href="/segments/create">Create a smart list</a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>Smart list</th><th>Rules</th><th class="num">People in it</th><th class="num">Can be emailed</th><th></th></tr>
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
  <div class="card__head"><h2>Why are there two numbers?</h2></div>
  <div class="card__body small">
    <p class="mt-0 mb-0">
      <strong>People in it</strong> is how many of your contacts fit the rules.
      <strong>Can be emailed</strong> is how many of those you are actually allowed to email —
      the rest have unsubscribed, bounced, or never agreed to hear from you.
      The difference between the two numbers is the one most tools quietly hide, so we show you
      both every time you pick who a campaign goes to.
    </p>
  </div>
</div>

<?php $__view->endSection(); ?>
