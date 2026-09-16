<?php $__view->extend('layouts.app'); $title = 'Enquiries'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Enquiries</h1>
    <p>People who asked you for work. The last 90 days.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/leads/pipeline">See the board</a>
  </div>
</div>

<?php if ($unanswered !== []): ?>
  <div class="alert alert--danger">
    <strong><?= count($unanswered) ?> <?= count($unanswered) === 1 ? 'enquiry has' : 'enquiries have' ?> had no reply</strong>
    These are the most valuable minutes in your day. An enquiry that sits for more than a day
    rarely turns into work — somebody else usually rings them back first.
  </div>

  <div class="card">
    <div class="card__body card__body--tight">
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Who</th><th>What they asked for</th><th>Came in</th><th class="num">Score</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($unanswered as $lead): ?>
              <tr>
                <td>
                  <a href="/leads/<?= (int) $lead['id'] ?>">
                    <strong><?= e(trim((string) $lead['first_name'] . ' ' . (string) $lead['last_name']) ?: (string) $lead['email']) ?></strong>
                  </a>
                  <?php if (!empty($lead['phone'])): ?>
                    <div class="tiny"><a href="tel:<?= e((string) $lead['phone']) ?>"><?= e((string) $lead['phone']) ?></a></div>
                  <?php endif; ?>
                </td>
                <td class="small"><?= e(App\Support\Str::limit((string) ($lead['enquiry'] ?? $lead['title']), 90)) ?></td>
                <td class="small muted nowrap"><?= e(substr((string) $lead['created_at'], 0, 16)) ?></td>
                <td class="num">
                  <span class="badge <?= (int) $lead['lead_score'] >= 50 ? 'badge--danger' : ((int) $lead['lead_score'] >= 25 ? 'badge--warning' : '') ?>">
                    <?= (int) $lead['lead_score'] ?>
                  </span>
                </td>
                <td class="right"><a class="btn btn--sm btn--primary" href="/leads/<?= (int) $lead['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="alert alert--success">
    <strong>Everyone has had a reply</strong>
    Nothing is sitting unanswered. This is the one number on this page worth keeping at zero.
  </div>
<?php endif; ?>

<div class="stats mb-2">
  <div class="stat">
    <div class="stat__label">Enquiries</div>
    <div class="stat__value"><?= number_format((int) $stats['total']) ?></div>
  </div>
  <div class="stat stat--accent">
    <div class="stat__label">Turned into work</div>
    <div class="stat__value"><?= $stats['win_rate'] ?>%</div>
    <div class="stat__meta"><?= number_format((int) $stats['won']) ?> of <?= number_format((int) $stats['total']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Worth</div>
    <div class="stat__value"><?= e(money($stats['won_value'], (string) $stats['currency'])) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Still open</div>
    <div class="stat__value"><?= number_format((int) $stats['open']) ?></div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Add an enquiry</h2></div>
  <div class="card__body">
    <p class="small muted mt-0">
      Somebody rang up? Put them in here so they end up in the same place as the ones from your
      website, and nothing gets forgotten.
    </p>
    <form method="post" action="/leads">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <div class="grid-3">
        <div class="field">
          <label for="email">Their email</label>
          <input id="email" type="email" name="email" required maxlength="255">
        </div>
        <div class="field">
          <label for="title">What is it about?</label>
          <input id="title" type="text" name="title" maxlength="200" placeholder="e.g. Boiler replacement quote">
        </div>
        <div class="field">
          <label for="source">Where did they come from?</label>
          <select id="source" name="source">
            <?php foreach ($sources as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $key === 'phone' ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="enquiry">What did they say?</label>
        <textarea id="enquiry" name="enquiry" rows="3" maxlength="5000"></textarea>
      </div>
      <button class="btn btn--primary" type="submit">Save it</button>
    </form>
  </div>
</div>

<?php $__view->endSection(); ?>
