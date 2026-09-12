<?php
/** @var array<int,string> $steps */
/** @var int $current */
?>
<div class="steps">
  <?php foreach ($steps as $index => $label): ?>
    <?php $number = $index + 1; ?>
    <div class="step <?= $number < $current ? 'is-done' : ($number === $current ? 'is-current' : '') ?>">
      <small>Step <?= $number ?></small>
      <?= e($label) ?>
    </div>
  <?php endforeach; ?>
</div>
