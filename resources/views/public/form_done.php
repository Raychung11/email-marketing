<?php $__view->extend('layouts.public'); $title = 'Thank you'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:20px;margin:0 0 10px">Thank you</h1>

<p class="small"><?= e((string) $message) ?></p>

<p class="tiny muted" style="margin-bottom:0">
  From <?= e((string) $organisation['name']) ?>.
</p>

<?php $__view->endSection(); ?>
