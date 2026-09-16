<?php $__view->extend('layouts.app'); $title = 'Journeys'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Journeys</h1>
    <p>Things that happen on their own, so you do not have to remember them.</p>
  </div>
</div>

<?php if ($automations === []): ?>
  <div class="alert alert--info">
    <strong>A journey is a chain of things that happen after something else</strong>
    "When somebody new is added, wait a day, send them a hello, then put a phone call on Dave's
    list." You set it up once and it runs for every customer from then on.
  </div>

  <div class="row">
    <?php foreach ($templates as $key => $template): ?>
      <div class="col">
        <div class="card">
          <div class="card__head"><h2><?= e((string) $template['name']) ?></h2></div>
          <div class="card__body">
            <p class="small mt-0"><?= e((string) $template['description']) ?></p>
            <p class="tiny muted"><strong>Why bother:</strong> <?= e((string) $template['why']) ?></p>
            <ol class="tiny muted">
              <?php foreach ($template['steps'] as $step): ?>
                <li><?= e((string) $step[2]) ?></li>
              <?php endforeach; ?>
            </ol>
            <form method="post" action="/automations">
              <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
              <input type="hidden" name="name" value="<?= e((string) $template['name']) ?>">
              <input type="hidden" name="description" value="<?= e((string) $template['description']) ?>">
              <input type="hidden" name="trigger_type" value="<?= e((string) $template['trigger']) ?>">
              <button class="btn btn--primary btn--sm btn--block" type="submit">Start from this</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card__body card__body--tight">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>Journey</th><th>Starts when</th><th>Status</th>
                <th class="num">In it now</th><th class="num">Been through</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($automations as $automation): ?>
              <tr>
                <td>
                  <a href="/automations/<?= (int) $automation['id'] ?>">
                    <strong><?= e((string) $automation['name']) ?></strong>
                  </a>
                  <?php if (!empty($automation['description'])): ?>
                    <div class="tiny muted"><?= e((string) $automation['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small muted">
                  <?= e((string) ($triggers[$automation['trigger_type']]['label'] ?? $automation['trigger_type'])) ?>
                </td>
                <td>
                  <?php $status = (string) $automation['status']; ?>
                  <span class="badge <?= $status === 'active' ? 'badge--success' : ($status === 'paused' ? 'badge--warning' : '') ?> badge--dot">
                    <?= e($status === 'active' ? 'running' : ($status === 'draft' ? 'not switched on' : $status)) ?>
                  </span>
                </td>
                <td class="num"><?= number_format((int) $automation['active_count']) ?></td>
                <td class="num muted"><?= number_format((int) $automation['completed_count']) ?></td>
                <td class="right"><a class="btn btn--sm" href="/automations/<?= (int) $automation['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Start another one</h2></div>
    <div class="card__body">
      <form method="post" action="/automations">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <div class="grid-2">
          <div class="field">
            <label for="name">What is it called?</label>
            <input id="name" type="text" name="name" required maxlength="200"
                   placeholder="e.g. Chase an enquiry nobody answered">
          </div>
          <div class="field">
            <label for="trigger_type">What starts it?</label>
            <select id="trigger_type" name="trigger_type" required>
              <?php foreach ($triggers as $key => $trigger): ?>
                <option value="<?= e($key) ?>"><?= e((string) $trigger['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button class="btn btn--primary" type="submit">Create it</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php $__view->endSection(); ?>
