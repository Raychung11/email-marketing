<?php
/**
 * Flash messages and validation errors.
 *
 * @var array<string,array<int,string>> $errors
 */
?>
<?php if (!empty($success)): ?>
  <div class="alert alert--success" role="status"><?= e((string) $success) ?></div>
<?php endif; ?>

<?php if (!empty($warning)): ?>
  <div class="alert alert--warning" role="status"><?= e((string) $warning) ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
  <div class="alert alert--danger" role="alert"><?= e((string) $error) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert--danger" role="alert">
    <strong>Please correct the following:</strong>
    <ul>
      <?php foreach ($errors as $messages): ?>
        <?php foreach ((array) $messages as $message): ?>
          <li><?= e((string) $message) ?></li>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
