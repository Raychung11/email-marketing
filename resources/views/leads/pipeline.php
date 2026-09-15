<?php $__view->extend('layouts.app'); $title = 'Pipeline'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Pipeline</h1>
    <p>Where every open enquiry has got to.</p>
  </div>
  <div class="page-head__actions"><a class="btn" href="/leads">Back to enquiries</a></div>
</div>

<div class="row">
  <?php foreach ($board as $column): ?>
    <div class="col">
      <div class="card">
        <div class="card__head">
          <h2><?= e((string) $column['stage']['label']) ?></h2>
          <div class="card__actions">
            <span class="badge"><?= count($column['leads']) ?></span>
          </div>
        </div>
        <div class="card__body card__body--tight">
          <?php if ($column['leads'] === []): ?>
            <p class="small muted" style="padding:12px 14px;margin:0">Nothing here.</p>
          <?php else: ?>
            <?php foreach ($column['leads'] as $lead): ?>
              <div style="padding:10px 14px;border-bottom:1px solid var(--line)">
                <a href="/leads/<?= (int) $lead['id'] ?>">
                  <strong class="small"><?= e(trim((string) $lead['first_name'] . ' ' . (string) $lead['last_name']) ?: (string) $lead['email']) ?></strong>
                </a>
                <?php if (!empty($lead['title'])): ?>
                  <div class="tiny muted"><?= e((string) $lead['title']) ?></div>
                <?php endif; ?>
                <div class="flex-between tiny" style="margin-top:4px">
                  <span class="muted">
                    <?= !empty($lead['estimated_value'])
                        ? e(money((float) $lead['estimated_value'], (string) $stats['currency']))
                        : '—' ?>
                  </span>
                  <?php if ($lead['first_response_at'] === null): ?>
                    <span style="color:#b91c1c">not answered</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if ($column['value'] > 0): ?>
          <div class="card__foot small muted">
            <?= e(money((float) $column['value'], (string) $stats['currency'])) ?> in this column
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php $__view->endSection(); ?>
