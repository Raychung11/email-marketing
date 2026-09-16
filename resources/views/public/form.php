<?php $__view->extend('layouts.public'); $title = (string) $form['heading']; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:20px;margin:0 0 8px"><?= e((string) $form['heading']) ?></h1>

<?php if (!empty($form['intro'])): ?>
  <p class="small muted" style="margin:0 0 14px"><?= e((string) $form['intro']) ?></p>
<?php endif; ?>

<?php if ($errors !== []): ?>
  <div class="alert alert--danger">
    <?php foreach ($errors as $messages): ?>
      <?php foreach ((array) $messages as $message): ?>
        <div><?= e((string) $message) ?></div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" action="/f/<?= (int) $organisation['id'] ?>/<?= e((string) $form['slug']) ?>">
  <?php foreach ($form['fields'] as $field): ?>
    <div class="field">
      <label for="<?= e((string) $field['field_key']) ?>">
        <?= e((string) $field['label']) ?><?= (int) $field['is_required'] === 1 ? ' *' : '' ?>
      </label>
      <?php if ((string) $field['field_type'] === 'textarea'): ?>
        <textarea id="<?= e((string) $field['field_key']) ?>" name="<?= e((string) $field['field_key']) ?>"
                  rows="4" maxlength="2000"
                  <?= (int) $field['is_required'] === 1 ? 'required' : '' ?>><?= e((string) ($old[$field['field_key']] ?? '')) ?></textarea>
      <?php else: ?>
        <input id="<?= e((string) $field['field_key']) ?>" name="<?= e((string) $field['field_key']) ?>"
               type="<?= e(in_array($field['field_type'], ['email', 'number', 'date', 'tel'], true)
                   ? (string) $field['field_type'] : 'text') ?>"
               maxlength="255"
               value="<?= e((string) ($old[$field['field_key']] ?? '')) ?>"
               placeholder="<?= e((string) ($field['placeholder'] ?? '')) ?>"
               <?= (int) $field['is_required'] === 1 ? 'required' : '' ?>>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php /*
     The honeypot. Hidden from people, irresistible to bots. Not display:none,
     which some form-fillers skip — off-screen with an explicit instruction to
     screen readers to leave it alone.
  */ ?>
  <div style="position:absolute;left:-9999px" aria-hidden="true">
    <label for="website_url">Leave this empty</label>
    <input id="website_url" name="website_url" type="text" tabindex="-1" autocomplete="off">
  </div>

  <?php if ((int) $form['consent_checkbox_enabled'] === 1): ?>
    <div class="field">
      <label class="check">
        <?php /* Never checked. Not a setting — a pre-ticked box is not consent. */ ?>
        <input type="checkbox" name="consent" value="1"
               <?= (int) $form['consent_checkbox_required'] === 1 ? 'required' : '' ?>>
        <span><?= e((string) $form['consent_text']) ?></span>
      </label>
    </div>
  <?php endif; ?>

  <button class="btn btn--primary btn--block" type="submit"><?= e((string) $form['submit_label']) ?></button>
</form>

<p class="tiny muted mt-2" style="margin-bottom:0">
  <?= e((string) $organisation['name']) ?> will only use your details to reply to you, and to send
  you email if you ticked the box above. You can stop that at any time.
</p>

<?php $__view->endSection(); ?>
