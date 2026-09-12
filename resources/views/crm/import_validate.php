<?php
/**
 * Steps 4 and 5: the validation report, then the consent declaration.
 *
 * The declaration is the gate. Purchased and scraped selections are refused and
 * the import ends there — no contact row is written.
 */
$__view->extend('layouts.app');
$title = 'Validate and declare consent';
$steps = ['Upload', 'Preview', 'Map', 'Validate', 'Consent', 'Duplicates', 'Import', 'Summary'];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Validation</h1><p><?= e((string) $batch['original_filename']) ?></p></div>
</div>

<?= $__view->include('partials.import_steps', ['steps' => $steps, 'current' => 4]) ?>

<div class="stats mb-2">
  <div class="stat"><div class="stat__label">Rows</div><div class="stat__value"><?= number_format($report['total']) ?></div></div>
  <div class="stat stat--accent"><div class="stat__label">Valid</div><div class="stat__value"><?= number_format($report['valid']) ?></div></div>
  <div class="stat"><div class="stat__label">Invalid emails</div><div class="stat__value"><?= number_format($report['invalid']) ?></div></div>
  <div class="stat"><div class="stat__label">Duplicates in file</div><div class="stat__value"><?= number_format($report['duplicates_in_file']) ?></div></div>
  <div class="stat"><div class="stat__label">Already in your CRM</div><div class="stat__value"><?= number_format($report['existing']) ?></div></div>
  <div class="stat"><div class="stat__label">Already suppressed</div><div class="stat__value"><?= number_format($report['suppressed']) ?></div></div>
</div>

<?php if ($report['suppressed'] > 0): ?>
  <div class="alert alert--warning">
    <strong><?= number_format($report['suppressed']) ?> of these addresses are on your suppression list</strong>
    They will still be imported so your CRM record is complete, but they remain suppressed and will
    not receive marketing email. Importing never clears a suppression.
  </div>
<?php endif; ?>

<?php if ($report['requires_au_declaration']): ?>
  <div class="alert alert--info">
    <strong>This import includes Australian contacts</strong>
    Australian marketing email requires an acceptable consent basis. Contacts whose consent
    cannot be established will be imported but blocked from marketing sends until consent is recorded.
  </div>
<?php endif; ?>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>How were these contacts obtained?</h2></div>
      <div class="card__body">
        <p class="small muted mt-0">
          This is required, and it is not a formality: your answer is recorded against every contact
          in this file as the consent evidence, and it decides what the platform will allow you to send.
        </p>

        <form method="post" action="/contacts/import/<?= (int) $batch['id'] ?>/consent">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

          <?php foreach ($sources as $key => $definition): ?>
            <div class="field" style="margin-bottom:10px">
              <label class="check">
                <input type="radio" name="consent_source" value="<?= e($key) ?>" required>
                <span>
                  <strong><?= e((string) $definition['label']) ?></strong>
                  <?php if (!empty($definition['blocked'])): ?>
                    <span class="badge badge--danger">Not permitted</span>
                    <div class="tiny" style="color:#b91c1c"><?= e((string) ($definition['message'] ?? '')) ?></div>
                  <?php else: ?>
                    <div class="tiny muted">
                      Recorded as
                      <span class="mono"><?= e((string) ($definition['status'] ?? 'unknown')) ?></span>
                      /
                      <span class="mono"><?= e((string) ($definition['consent_type'] ?? 'other')) ?></span>
                      <?php if (!empty($definition['require_reference'])): ?>
                        &middot; evidence reference required
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($definition['warning'])): ?>
                      <div class="tiny" style="color:#b45309"><?= e((string) $definition['warning']) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </span>
              </label>
            </div>
          <?php endforeach; ?>

          <hr class="sep">

          <div class="field">
            <label for="consent_reference">Evidence reference</label>
            <input id="consent_reference" type="text" name="consent_reference" maxlength="255"
                   placeholder="e.g. 'Signup form on perthplumbing.com.au since Jan 2024', or where the signed forms are kept">
            <div class="hint">Required for phone and offline consent. Recommended for everything else.</div>
          </div>

          <div class="field">
            <label for="consent_text">Consent wording shown to these contacts</label>
            <textarea id="consent_text" name="consent_text" maxlength="2000"
                      placeholder="Paste the exact wording they agreed to, if you have it."></textarea>
          </div>

          <button class="btn btn--primary" type="submit">Declare and continue</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <?php foreach (['invalid' => 'Invalid addresses', 'existing' => 'Already in your CRM', 'suppressed' => 'Suppressed'] as $key => $label): ?>
      <?php if (!empty($report['samples'][$key])): ?>
        <div class="card">
          <div class="card__head"><h2><?= e($label) ?></h2></div>
          <div class="card__body">
            <ul class="small mono" style="padding-left:16px;margin:0">
              <?php foreach ($report['samples'][$key] as $sample): ?>
                <li><?= e((string) $sample) ?></li>
              <?php endforeach; ?>
            </ul>
            <p class="tiny muted mb-0 mt-1">Showing up to 5 examples.</p>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($report['countries'] !== []): ?>
      <div class="card">
        <div class="card__head"><h2>Countries in this file</h2></div>
        <div class="card__body">
          <?php foreach ($report['countries'] as $country => $count): ?>
            <div class="flex-between small">
              <span><?= e($country) ?></span><strong><?= number_format($count) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $__view->endSection(); ?>
