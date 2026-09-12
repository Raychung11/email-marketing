<?php $__view->extend('layouts.app'); $title = 'Create an organisation'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Create an organisation</h1>
    <p>Your account is not attached to an organisation yet.</p>
  </div>
</div>

<div class="card" style="max-width:640px">
  <div class="card__body">
    <p class="mt-0 small muted">
      An organisation is the boundary for contacts, campaigns, billing and compliance. Agencies can
      run several; most businesses need one.
    </p>
    <p class="mb-0">
      Ask whoever administers your organisation to invite you, or
      <a href="/register">register a new organisation</a>.
    </p>
  </div>
</div>

<?php $__view->endSection(); ?>
