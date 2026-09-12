<?php
$__view->extend('layouts.app');

$isEdit = $campaign !== null;
$title  = $isEdit ? 'Edit campaign' : 'New campaign';
$action = $isEdit ? '/campaigns/' . (int) $campaign['id'] : '/campaigns';

$value = static function (string $field, string $default = '') use ($campaign, $old): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($campaign[$field] ?? $default);
};
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p>Audience, content and sender. The validator checks the rest before anyone can approve it.</p>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="row">
    <div class="col">
      <div class="card">
        <div class="card__head"><h2>Campaign</h2></div>
        <div class="card__body">
          <div class="field">
            <label for="name">Internal name</label>
            <input id="name" type="text" name="name" required maxlength="200" value="<?= e($value('name')) ?>"
                   placeholder="e.g. Autumn service reminder">
            <div class="hint">Only your team sees this.</div>
          </div>

          <div class="field">
            <label for="campaign_type">Type</label>
            <select id="campaign_type" name="campaign_type" required>
              <?php foreach ($types as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $value('campaign_type', 'newsletter') === $key ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Used for reporting and for choosing a matching template layout.</div>
          </div>

          <div class="field">
            <label for="subject">Subject line</label>
            <input id="subject" type="text" name="subject" maxlength="255" value="<?= e($value('subject')) ?>"
                   placeholder="Your annual plumbing check is due">
            <div class="hint">
              Say what the email is. A subject that pretends to be a reply or a receipt
              (<span class="mono">Re:</span>, <span class="mono">Your order confirmation</span>) will be
              refused — in the US that one is against the law, not just bad manners.
            </div>
            <?php if ($campaign !== null): ?>
              <button class="btn btn--sm mt-1" type="button"
                      data-ai-subjects="/campaigns/<?= (int) $campaign['id'] ?>/ai/subjects">
                Suggest some subject lines
              </button>
              <div class="ai-subjects tiny" hidden></div>
            <?php endif; ?>
          </div>

          <div class="field">
            <label for="preview_text">Preview text</label>
            <input id="preview_text" type="text" name="preview_text" maxlength="255" value="<?= e($value('preview_text')) ?>">
            <div class="hint">The grey line inboxes show after the subject.</div>
          </div>
        </div>
      </div>

      <?php if ($isEdit): ?>
        <div class="card">
          <div class="card__head"><h2>Sender</h2></div>
          <div class="card__body">
            <div class="grid-2">
              <div class="field">
                <label for="from_name">From name</label>
                <input id="from_name" type="text" name="from_name" maxlength="120" value="<?= e($value('from_name')) ?>">
              </div>
              <div class="field">
                <label for="from_email">From address</label>
                <input id="from_email" type="email" name="from_email" maxlength="255" value="<?= e($value('from_email')) ?>">
                <div class="hint">Must be on a <a href="/settings/domains">verified domain</a>.</div>
              </div>
            </div>
            <div class="field">
              <label for="reply_to">Reply-to</label>
              <input id="reply_to" type="email" name="reply_to" maxlength="255" value="<?= e($value('reply_to')) ?>">
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card__head"><h2>Link tracking</h2></div>
          <div class="card__body">
            <p class="small muted mt-0">
              Appended to every tracked link so your analytics can attribute the visit.
            </p>
            <div class="grid-3">
              <div class="field">
                <label for="utm_source">utm_source</label>
                <input id="utm_source" type="text" name="utm_source" maxlength="80" value="<?= e($value('utm_source', 'email')) ?>">
              </div>
              <div class="field">
                <label for="utm_medium">utm_medium</label>
                <input id="utm_medium" type="text" name="utm_medium" maxlength="80" value="<?= e($value('utm_medium', 'email')) ?>">
              </div>
              <div class="field">
                <label for="utm_campaign">utm_campaign</label>
                <input id="utm_campaign" type="text" name="utm_campaign" maxlength="120" value="<?= e($value('utm_campaign')) ?>">
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col col--narrow">
      <div class="card">
        <div class="card__head"><h2>Audience</h2></div>
        <div class="card__body">
          <div class="field">
            <label for="segment_id">Smart list</label>
            <select id="segment_id" name="segment_id">
              <option value="">—</option>
              <?php foreach ($segments as $segment): ?>
                <option value="<?= (int) $segment['id'] ?>" <?= (int) $value('segment_id') === (int) $segment['id'] ? 'selected' : '' ?>>
                  <?= e((string) $segment['name']) ?>
                  (<?= number_format((int) $segment['cached_eligible_count']) ?> eligible)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="list_id">Or a list</label>
            <select id="list_id" name="list_id">
              <option value="">—</option>
              <?php foreach ($lists as $list): ?>
                <option value="<?= (int) $list['id'] ?>" <?= (int) $value('list_id') === (int) $list['id'] ? 'selected' : '' ?>>
                  <?= e((string) $list['name']) ?> (<?= number_format((int) $list['contact_count']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">A smart list wins if both are set.</div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><h2>Content</h2></div>
        <div class="card__body">
          <div class="field">
            <label for="template_id">Start from a template</label>
            <select id="template_id" name="template_id">
              <option value="">—</option>
              <?php foreach ($templates as $template): ?>
                <option value="<?= (int) $template['id'] ?>" <?= (int) $value('template_id') === (int) $template['id'] ? 'selected' : '' ?>>
                  <?= e((string) $template['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">
              The content is copied into the campaign, so editing the template later cannot change
              a campaign that has already been approved.
            </div>
          </div>
        </div>
      </div>

      <div class="flex mt-2">
        <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save campaign' : 'Create campaign' ?></button>
        <a class="btn btn--ghost" href="<?= $isEdit ? '/campaigns/' . (int) $campaign['id'] : '/campaigns' ?>">Cancel</a>
      </div>
    </div>
  </div>
</form>

<?php $__view->endSection(); ?>
