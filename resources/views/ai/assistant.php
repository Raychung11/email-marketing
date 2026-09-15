<?php $__view->extend('layouts.app'); $title = 'Ask a question'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Ask a question</h1>
    <p>About your own figures. Plain answers, no spreadsheets.</p>
  </div>
</div>

<?php if (!$available): ?>
  <div class="alert alert--warning">
    <strong>This is not switched on yet</strong>
    Nobody has connected an AI service to this installation. Everything on the reporting screens
    still works without it.
  </div>
<?php endif; ?>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__body">
        <div class="field">
          <label for="question">What do you want to know?</label>
          <input id="question" type="text" maxlength="500"
                 placeholder="e.g. How did last month go? Is my email getting through?"
                 <?= $available ? '' : 'disabled' ?>>
        </div>
        <button class="btn btn--primary" type="button" id="askGo" <?= $available ? '' : 'disabled' ?>>
          Ask
        </button>

        <div class="tiny muted" style="margin-top:10px">
          Try: <a href="#" data-ask="How did last month go?">How did last month go?</a> ·
          <a href="#" data-ask="Is my email getting through?">Is my email getting through?</a> ·
          <a href="#" data-ask="How many enquiries have not been answered?">Unanswered enquiries?</a>
        </div>

        <div id="askAnswer" hidden style="margin-top:16px"></div>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>What it can see</h2></div>
      <div class="card__body small">
        <p class="mt-0 muted">
          Only these, and only for your business. It cannot go looking anywhere else.
        </p>
        <?php foreach ($tools as $name => $tool): ?>
          <div style="margin-bottom:8px">
            <strong><?= e(ucfirst(str_replace('_', ' ', (string) $name))) ?></strong>
            <div class="tiny muted"><?= e((string) $tool['description']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>What it cannot do</h2></div>
      <div class="card__body small">
        <p class="mt-0">
          <strong>It cannot do anything at all.</strong> It cannot send, schedule, change or delete.
          If it thinks something is worth doing, it tells you and points at the screen where you do
          it yourself.
        </p>
        <p>
          <strong>It cannot make up numbers.</strong> Anything it says that quotes a figure we did
          not give it gets removed before you see it, and we tell you when that happens.
        </p>
        <p class="mb-0">
          <strong>It cannot see your customers' details.</strong> It works from counts and rates, not
          from names, addresses or what anybody wrote to you.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
