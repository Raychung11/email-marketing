<?php $__view->extend('layouts.app'); $title = 'Signup forms'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Signup forms</h1>
    <p>How people join your list, and how you prove they agreed to it.</p>
  </div>
</div>

<div class="alert alert--info">
  <strong>This is where permission comes from</strong>
  Everything else in here protects a permission that was given on one of these forms. So we store
  the exact wording somebody saw when they ticked the box, the date, and where they were —
  and we never tick the box for them.
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($forms === []): ?>
      <div class="empty">
        <h3>No forms yet</h3>
        <p>Make one, put it on your website, and people can ask you for a quote or join your list.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Form</th><th>Status</th><th class="num">Filled in</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($forms as $form): ?>
              <tr>
                <td>
                  <a href="/forms/<?= (int) $form['id'] ?>"><strong><?= e((string) $form['name']) ?></strong></a>
                  <?php if ((string) $form['status'] === 'published'): ?>
                    <div class="tiny muted mono">/f/<?= (int) $organisationId ?>/<?= e((string) $form['slug']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= (string) $form['status'] === 'published' ? 'badge--success' : '' ?> badge--dot">
                    <?= e((string) $form['status'] === 'published' ? 'live' : 'not live yet') ?>
                  </span>
                </td>
                <td class="num"><?= number_format((int) $form['submission_count']) ?></td>
                <td class="right"><a class="btn btn--sm" href="/forms/<?= (int) $form['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Make a form</h2></div>
  <div class="card__body">
    <form method="post" action="/forms">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label for="name">What is it for?</label>
        <input id="name" type="text" name="name" required maxlength="160"
               placeholder="e.g. Get a quote">
      </div>
      <div class="field">
        <label class="check">
          <input type="checkbox" name="create_lead" value="1" checked>
          <span>Treat each one as an enquiry
            <div class="tiny muted">It shows up in your Enquiries list so nobody forgets to ring back.</div>
          </span>
        </label>
      </div>
      <div class="field">
        <label class="check">
          <input type="checkbox" name="consent_checkbox_required" value="1">
          <span>They must tick the marketing box to submit
            <div class="tiny muted">
              Leave this off for a "get a quote" form — people should be able to ask you a question
              without signing up to your newsletter.
            </div>
          </span>
        </label>
      </div>
      <button class="btn btn--primary" type="submit">Create it</button>
    </form>
  </div>
</div>

<?php $__view->endSection(); ?>
