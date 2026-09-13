<?php
$__view->extend('layouts.app');
$title    = (string) $form['name'];
$isLive   = (string) $form['status'] === 'published';
$publicUrl = $appUrl . '/f/' . (int) $organisationId . '/' . (string) $form['slug'];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p>
      <span class="badge <?= $isLive ? 'badge--success' : '' ?> badge--dot">
        <?= $isLive ? 'live' : 'not live yet' ?>
      </span>
      <?= number_format((int) $form['submission_count']) ?> filled in
    </p>
  </div>
  <div class="page-head__actions">
    <?php if (!$isLive): ?>
      <form method="post" action="/forms/<?= (int) $form['id'] ?>/publish">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <button class="btn btn--primary" type="submit">Make it live</button>
      </form>
    <?php else: ?>
      <a class="btn" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">See it</a>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>Wording</h2></div>
      <div class="card__body">
        <form method="post" action="/forms/<?= (int) $form['id'] ?>">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="heading">Heading</label>
            <input id="heading" type="text" name="heading" maxlength="200"
                   value="<?= e((string) $form['heading']) ?>">
          </div>
          <div class="field">
            <label for="intro">A line underneath (optional)</label>
            <input id="intro" type="text" name="intro" maxlength="500"
                   value="<?= e((string) ($form['intro'] ?? '')) ?>">
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="submit_label">Button text</label>
              <input id="submit_label" type="text" name="submit_label" maxlength="60"
                     value="<?= e((string) $form['submit_label']) ?>">
            </div>
            <div class="field">
              <label for="success_message">What they see afterwards</label>
              <input id="success_message" type="text" name="success_message" maxlength="500"
                     value="<?= e((string) $form['success_message']) ?>">
            </div>
          </div>

          <hr class="sep">

          <div class="field">
            <label for="consent_text">What they are agreeing to</label>
            <textarea id="consent_text" name="consent_text" rows="3" maxlength="1000"><?= e((string) $form['consent_text']) ?></textarea>
            <div class="hint">
              This exact wording is saved with every person who ticks the box, so if anybody ever
              asks what they agreed to, you can show them. Change it and everyone after today sees
              the new version — the people before keep the old one. Currently on version
              <?= e((string) $form['consent_version']) ?>.
            </div>
          </div>

          <div class="field">
            <label class="check">
              <input type="checkbox" name="consent_checkbox_required" value="1"
                     <?= (int) $form['consent_checkbox_required'] === 1 ? 'checked' : '' ?>>
              <span>They cannot submit without ticking it</span>
            </label>
          </div>

          <div class="field">
            <label class="check">
              <input type="checkbox" name="create_lead" value="1"
                     <?= (int) $form['create_lead'] === 1 ? 'checked' : '' ?>>
              <span>Treat each one as an enquiry</span>
            </label>
          </div>

          <button class="btn btn--primary" type="submit">Save</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Recent</h2></div>
      <div class="card__body card__body--tight">
        <?php if ($submissions === []): ?>
          <div class="empty"><p>Nobody has filled it in yet.</p></div>
        <?php else: ?>
          <table class="data">
            <thead><tr><th>When</th><th>Agreed to emails?</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($submissions as $submission): ?>
                <tr>
                  <td class="small"><?= e(substr((string) $submission['created_at'], 0, 16)) ?></td>
                  <td>
                    <span class="badge <?= (int) $submission['consent_given'] === 1 ? 'badge--success' : '' ?>">
                      <?= (int) $submission['consent_given'] === 1 ? 'yes' : 'no' ?>
                    </span>
                    <?php if ((int) $submission['is_spam'] === 1): ?>
                      <span class="badge badge--warning">looks like a bot</span>
                    <?php endif; ?>
                  </td>
                  <td class="right">
                    <?php if (!empty($submission['contact_id'])): ?>
                      <a class="btn btn--sm" href="/contacts/<?= (int) $submission['contact_id'] ?>">Open</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <?php if ($isLive): ?>
      <div class="card">
        <div class="card__head"><h2>Putting it on your website</h2></div>
        <div class="card__body small">
          <p class="mt-0"><strong>The easy way:</strong> link to it.</p>
          <p class="mono tiny" style="word-break:break-all"><?= e($publicUrl) ?></p>
          <hr class="sep">
          <p><strong>Or embed it</strong> — give this to whoever looks after your site:</p>
          <pre class="tiny" style="white-space:pre-wrap;word-break:break-all;background:var(--surface-2);padding:10px;border-radius:6px"><code>&lt;iframe src="<?= e($publicUrl) ?>"
        width="100%" height="520"
        style="border:0"
        title="<?= e((string) $form['heading']) ?>"&gt;
&lt;/iframe&gt;</code></pre>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><h2>Why we do it this way</h2></div>
      <div class="card__body small">
        <p class="mt-0">
          <strong>The box is never pre-ticked.</strong> It is not a setting. A pre-ticked box does
          not count as agreement anywhere we operate, and offering it would be selling you a
          problem dressed up as a feature.
        </p>
        <p>
          <strong>Filling in the form is not the same as agreeing to emails.</strong> Somebody
          asking for a quote wants an answer, not a newsletter. We record the two separately.
        </p>
        <p class="mb-0">
          <strong>There is a hidden trap for bots.</strong> A field people never see. If it gets
          filled in we quietly file the submission as spam rather than telling whoever sent it.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
