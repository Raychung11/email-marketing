<?php
$__view->extend('layouts.app');
$title  = (string) $automation['name'];
$status = (string) $automation['status'];

// Order the steps by following the connections, so the screen reads the way the
// journey actually runs rather than in the order somebody happened to add them.
$byId = [];
foreach ($automation['nodes'] as $node) { $byId[(int) $node['id']] = $node; }

$next = [];
foreach ($automation['connections'] as $edge) {
    $next[(int) $edge['from_node_id']][(string) $edge['branch']] = (int) $edge['to_node_id'];
}

$ordered = [];
$cursor  = null;
foreach ($automation['nodes'] as $node) {
    if ((string) $node['node_type'] === 'trigger') { $cursor = (int) $node['id']; }
}
$guard = 0;
while ($cursor !== null && $guard++ < 60) {
    $ordered[] = $byId[$cursor];
    $cursor = $next[$cursor]['default'] ?? $next[$cursor]['yes'] ?? null;
}
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p>
      <span class="badge <?= $status === 'active' ? 'badge--success' : ($status === 'paused' ? 'badge--warning' : '') ?> badge--dot">
        <?= e($status === 'active' ? 'running' : ($status === 'draft' ? 'not switched on' : $status)) ?>
      </span>
      Starts when: <?= e((string) ($triggers[$automation['trigger_type']]['label'] ?? '')) ?>
    </p>
  </div>
  <div class="page-head__actions">
    <?php if ($status === 'active'): ?>
      <form method="post" action="/automations/<?= (int) $automation['id'] ?>/pause">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <button class="btn btn--danger" type="submit">Pause it</button>
      </form>
    <?php else: ?>
      <form method="post" action="/automations/<?= (int) $automation['id'] ?>/activate">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <button class="btn btn--primary" type="submit" <?= $problems === [] ? '' : 'disabled' ?>>
          Switch it on
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($problems !== []): ?>
  <div class="alert alert--warning">
    <strong>Not ready to switch on yet</strong>
    <ul>
      <?php foreach ($problems as $problem): ?><li><?= e((string) $problem) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php elseif ($status === 'draft'): ?>
  <div class="alert alert--info">
    <strong>Ready when you are</strong>
    Nothing happens until you switch it on. Once you do, it starts picking up people who match —
    from that moment forward, not retrospectively.
  </div>
<?php endif; ?>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>What happens</h2></div>
      <div class="card__body">
        <ol class="small">
          <?php foreach ($ordered as $node): ?>
            <li style="margin-bottom:8px">
              <?php $type = (string) $node['node_type']; $config = (array) $node['config']; ?>
              <?php if ($type === 'trigger'): ?>
                <strong><?= e((string) ($triggers[$automation['trigger_type']]['label'] ?? 'Something happens')) ?></strong>
                <div class="tiny muted"><?= e((string) ($triggers[$automation['trigger_type']]['description'] ?? '')) ?></div>
              <?php elseif ($type === 'wait'): ?>
                <strong>Wait <?= (int) $node['wait_minutes'] >= 1440
                    ? round((int) $node['wait_minutes'] / 1440) . ' day(s)'
                    : round((int) $node['wait_minutes'] / 60, 1) . ' hour(s)' ?></strong>
              <?php elseif ($type === 'condition'): ?>
                <strong>Only carry on if they match</strong>
              <?php elseif ($type === 'exit'): ?>
                <strong>Journey ends here</strong>
              <?php else: ?>
                <strong><?= e((string) ($actions[$node['action_type']]['label'] ?? $node['action_type'])) ?></strong>
                <?php if (($config['subject'] ?? '') !== ''): ?>
                  <div class="tiny muted">Subject: <?= e((string) $config['subject']) ?></div>
                <?php endif; ?>
                <?php if (($config['title'] ?? '') !== ''): ?>
                  <div class="tiny muted"><?= e((string) $config['title']) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </div>

    <?php if ($status !== 'active'): ?>
      <div class="card">
        <div class="card__head"><h2>Add a step to the end</h2></div>
        <div class="card__body">
          <form method="post" action="/automations/<?= (int) $automation['id'] ?>/steps">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <div class="grid-2">
              <div class="field">
                <label for="node_type">What kind of step?</label>
                <select id="node_type" name="node_type">
                  <option value="action">Do something</option>
                  <option value="wait">Wait a while</option>
                  <option value="exit">End the journey here</option>
                </select>
              </div>
              <div class="field">
                <label for="action_type">Do what?</label>
                <select id="action_type" name="action_type">
                  <?php foreach ($actions as $key => $action): ?>
                    <option value="<?= e($key) ?>"><?= e((string) $action['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="grid-3">
              <div class="field">
                <label for="wait_minutes">If waiting, how long (minutes)?</label>
                <input id="wait_minutes" type="number" name="wait_minutes" min="1" value="1440">
              </div>
              <div class="field">
                <label for="config_template">If emailing, which email?</label>
                <select id="config_template" name="config[template_id]">
                  <option value="">Choose…</option>
                  <?php foreach ($emails as $email): ?>
                    <option value="<?= (int) $email['id'] ?>"><?= e((string) $email['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="config_subject">Subject line</label>
                <input id="config_subject" type="text" name="config[subject]" maxlength="255">
              </div>
            </div>
            <div class="grid-2">
              <div class="field">
                <label for="config_tag">If tagging, which tag?</label>
                <select id="config_tag" name="config[tag_id]">
                  <option value="">Choose…</option>
                  <?php foreach ($tags as $tag): ?>
                    <option value="<?= (int) $tag['id'] ?>"><?= e((string) $tag['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="config_title">If it is a job for somebody, what is it?</label>
                <input id="config_title" type="text" name="config[title]" maxlength="200"
                       placeholder="e.g. Ring them and check they are happy">
              </div>
            </div>
            <button class="btn btn--primary" type="submit">Add this step</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Who is in it</h2></div>
      <div class="card__body card__body--tight">
        <?php if ($runs === []): ?>
          <div class="empty"><p>Nobody yet.</p></div>
        <?php else: ?>
          <table class="data">
            <tbody>
              <?php foreach ($runs as $run): ?>
                <tr>
                  <td class="small">
                    <?= e(substr((string) $run['started_at'], 0, 16)) ?>
                    <?php if (!empty($run['last_error'])): ?>
                      <div class="tiny" style="color:#b91c1c"><?= e((string) $run['last_error']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="right">
                    <span class="badge <?= (string) $run['status'] === 'completed' ? 'badge--success' : ((string) $run['status'] === 'failed' ? 'badge--danger' : '') ?>">
                      <?= e(str_replace('_', ' ', (string) $run['status'])) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>How this behaves</h2></div>
      <div class="card__body small">
        <p class="mt-0">
          <strong>Everyone gets it once.</strong>
          <?= (int) $automation['allow_reentry'] === 1
              ? 'Unless they qualify again after ' . (int) $automation['reentry_cooldown_hours'] . ' hours.'
              : 'Somebody who has already been through will not go through again.' ?>
        </p>
        <p>
          <strong>Permission still applies.</strong> Every email here goes through the same checks as
          a campaign: anyone on your do-not-email list, or who never agreed to hear from you, is
          skipped and the reason is written down.
        </p>
        <p class="mb-0">
          <strong>Pausing is safe.</strong> People part-way through stay exactly where they are.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
