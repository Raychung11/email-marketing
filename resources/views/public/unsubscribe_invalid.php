<?php $__view->extend('layouts.public'); $title = 'Link not valid'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:19px;margin:0 0 10px">This link is not valid</h1>

<p class="small">
  The unsubscribe link could not be verified. That usually means it was truncated by an email
  client, or it has been altered.
</p>

<p class="small muted" style="margin-bottom:0">
  Try opening the link directly from the original email. If it still does not work, reply to the
  email and ask to be removed — the sender is obliged to act on that.
</p>

<?php $__view->endSection(); ?>
