<?php $__view->extend('layouts.app'); $title = 'Do-not-email list'; $rows = $result['rows']; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Do-not-email list</h1>
    <p><?= number_format($result['total']) ?> addresses we will never send marketing email to.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/suppressions/export">Export CSV</a>
  </div>
</div>

<div class="alert alert--info">
  <strong>This list beats everything else</strong>
  An address on here is skipped by every campaign, no matter which list it is on or how many times
  its details get uploaded again. Taking someone off the list is a deliberate step, needs a reason,
  and is written down.
</div>

<div class="row">
  <div class="col">
    <div class="card mb-2">
      <div class="card__body">
        <form method="get" action="/suppressions" class="flex wrap">
          <input type="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Search for an address" style="max-width:260px">
          <select name="reason" data-auto-submit style="max-width:200px">
            <option value="">Any reason</option>
            <?php foreach ($reasons as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $filters['reason'] === $key ? 'selected' : '' ?>>
                <?= e($label) ?><?= isset($counts[$key]) ? ' (' . number_format($counts[$key]) . ')' : '' ?>
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
            <h3>Nobody on the list yet</h3>
            <p>People who unsubscribe, addresses that turn out not to exist, and anyone who marks
            your email as spam are added here automatically.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Address</th><th>Reason</th><th>Source</th><th>Since</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td class="mono small"><?= e((string) $row['email']) ?></td>
                    <td>
                      <span class="badge <?= in_array($row['reason'], ['complaint', 'hard_bounce', 'legal'], true) ? 'badge--danger' : '' ?>">
                        <?= e($reasons[$row['reason']] ?? (string) $row['reason']) ?>
                      </span>
                    </td>
                    <td class="small muted">
                      <?= e((string) ($row['source'] ?? '—')) ?>
                      <?php if (!empty($row['detail'])): ?>
                        <div class="tiny"><?= e(App\Support\Str::limit((string) $row['detail'], 60)) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="small muted nowrap"><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
                    <td class="right">
                      <form method="post" action="/suppressions/<?= (int) $row['id'] ?>/remove"
                            data-confirm="Take this address off the do-not-email list? Only do this if they have since told you it is fine to email them again. We record who did it and when.">
                        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="reason" value="Taken off the list by a team member">
                        <button class="btn btn--sm btn--danger" type="submit">Take off the list</button>
                      </form>
                    </td>
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
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Add an address</h2></div>
      <div class="card__body">
        <form method="post" action="/suppressions">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" required maxlength="255">
          </div>
          <div class="field">
            <label for="detail">Why? (only your team sees this)</label>
            <input id="detail" type="text" name="detail" maxlength="255" placeholder="e.g. rang up and asked us to stop">
          </div>
          <button class="btn btn--primary btn--block" type="submit">Add to the list</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Why people are on here</h2></div>
      <div class="card__body">
        <?php foreach ($reasons as $key => $label): ?>
          <div class="flex-between small">
            <span><?= e($label) ?></span><strong><?= number_format($counts[$key] ?? 0) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
