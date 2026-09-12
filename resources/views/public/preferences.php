<?php $__view->extend('layouts.public'); $title = 'Email preferences'; ?>
<?php $__view->startSection('content'); ?>

<h1 style="font-size:19px;margin:0 0 8px">Email preferences</h1>
<p class="small" style="margin:0 0 4px"><strong><?= e($email) ?></strong></p>
<p class="small muted">Choose what you would like to hear about from <?= e((string) $organisation['name']) ?>.</p>

<form method="post" action="/preferences/<?= e($token) ?>">
  <?php foreach ($topics as $key => $label): ?>
    <div class="field" style="margin-bottom:10px">
      <label class="check">
        <input type="checkbox" name="topics[]" value="<?= e($key) ?>" <?= !empty($current[$key]) ? 'checked' : '' ?>>
        <span>
          <?= e($label) ?>
          <?php if ($key === 'all'): ?>
            <div class="tiny muted">Unticking this stops all marketing email, whatever else is selected.</div>
          <?php endif; ?>
        </span>
      </label>
    </div>
  <?php endforeach; ?>

  <button class="btn btn--primary btn--block mt-1" type="submit">Save my preferences</button>
</form>

<p class="small muted mt-2" style="margin-bottom:0">
  Or <a href="/unsubscribe/<?= e($token) ?>">unsubscribe from everything</a>.
</p>

<?php $__view->endSection(); ?>
