<?php
$__view->extend('layouts.app');
$title = (string) ($lead['title'] ?? 'Enquiry');
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p>
      <span class="badge <?= $scoring['temperature'] === 'hot' ? 'badge--danger' : ($scoring['temperature'] === 'warm' ? 'badge--warning' : '') ?>">
        <?= e($scoring['temperature']) ?>
      </span>
      Came in <?= e(substr((string) $lead['created_at'], 0, 16)) ?>
    </p>
  </div>
  <div class="page-head__actions">
    <?php if ($lead['first_response_at'] === null): ?>
      <form method="post" action="/leads/<?= (int) $lead['id'] ?>/responded">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <button class="btn btn--primary" type="submit">I have replied to this</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($lead['first_response_at'] === null): ?>
  <div class="alert alert--warning">
    <strong>Nobody has replied to this yet</strong>
    Mark it as answered once you have been back to them, so it stops showing up as outstanding.
  </div>
<?php endif; ?>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>What they asked for</h2></div>
      <div class="card__body">
        <p class="mt-0" style="white-space:pre-line"><?= e((string) ($lead['enquiry'] ?? '')) ?: '<span class="muted">Nothing written down.</span>' ?></p>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Why this scored <?= (int) $scoring['score'] ?></h2></div>
      <div class="card__body small">
        <?php if ($scoring['reasons'] === []): ?>
          <p class="mt-0 mb-0 muted">
            Nothing stands out about this one yet. That does not mean it is not worth a call —
            it means we do not know much about them.
          </p>
        <?php else: ?>
          <?php foreach ($scoring['reasons'] as $reason): ?>
            <div style="margin-bottom:10px">
              <div class="flex-between">
                <strong><?= e((string) $reason['label']) ?></strong>
                <span class="badge">+<?= (int) $reason['points'] ?></span>
              </div>
              <div class="tiny muted"><?= e((string) $reason['why']) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <hr class="sep">
        <p class="tiny muted mb-0">
          The score only decides what order things appear in. It is not a judgement about the
          person, and every point above is something you can check for yourself.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
