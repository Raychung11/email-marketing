<?php $__view->extend('layouts.public'); $title = 'Unsubscribed'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:19px;margin:0 0 10px">This email address has been unsubscribed.</h1>

<p class="small">
  <strong><?= e($email) ?></strong> will no longer receive marketing email from
  <strong><?= e((string) $organisation['name']) ?></strong>.
</p>

<p class="small muted">
  We have added the address to their do-not-email list, so it stays unsubscribed even if it is
  imported again later.
</p>

<?php if (!empty($preferencesUrl)): ?>
  <hr class="sep">
  <p class="small muted" style="margin-bottom:0">
    Changed your mind, or only want certain emails?
    <a href="<?= e($preferencesUrl) ?>">Manage your preferences</a>.
  </p>
<?php endif; ?>

<?php $__view->endSection(); ?>
