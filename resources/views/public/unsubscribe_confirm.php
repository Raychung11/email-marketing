<?php
/**
 * Confirmation step.
 *
 * Deliberately a POST behind a button rather than an unsubscribe-on-GET: mail
 * clients and security scanners pre-fetch links, and a state-changing GET would
 * unsubscribe people who never clicked.
 */
$__view->extend('layouts.public');
$title = 'Unsubscribe';
?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:19px;margin:0 0 8px">Unsubscribe</h1>
<p class="small" style="margin:0 0 6px">
  <strong><?= e($email) ?></strong>
</p>
<p class="small muted">
  You are about to stop receiving marketing email from
  <strong><?= e((string) $organisation['name']) ?></strong>.
</p>

<form method="post" action="/unsubscribe/<?= e($token) ?>">
  <button class="btn btn--primary btn--block" type="submit">Unsubscribe me</button>
</form>

<p class="small muted mt-2" style="margin-bottom:0">
  Prefer to hear less rather than nothing?
  <a href="/preferences/<?= e($token) ?>">Choose which emails you receive</a>.
</p>

<p class="tiny muted mt-2" style="margin-bottom:0">
  Operational messages such as receipts, booking confirmations and password resets are not marketing
  and may still be sent to you.
</p>

<?php $__view->endSection(); ?>
