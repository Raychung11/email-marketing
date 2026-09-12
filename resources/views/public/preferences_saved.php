<?php $__view->extend('layouts.public'); $title = 'Preferences saved'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:19px;margin:0 0 10px">Your preferences have been saved.</h1>

<p class="small">
  <strong><?= e($email) ?></strong> will only receive the types of email you selected from
  <strong><?= e((string) $organisation['name']) ?></strong>.
</p>

<p class="small muted" style="margin-bottom:0">You can change this at any time from the link in any of their emails.</p>

<?php $__view->endSection(); ?>
