<?php
/**
 * Segment builder.
 *
 * The builder posts a JSON definition. It is validated server-side against the
 * field registry in config/segments.php before any SQL is produced — the payload
 * is data, never a query.
 */
$__view->extend('layouts.app');

$isEdit = $segment !== null;
$title  = $isEdit ? 'Edit segment' : 'New segment';
$action = $isEdit ? '/segments/' . (int) $segment['id'] : '/segments';

// Value choices for fields that have a fixed vocabulary, so the builder offers a
// dropdown rather than a free-text box the user can get wrong.
$choices = [
    'country'         => $countries,
    'customer_status' => $statuses,
    'lifecycle_stage' => $stages,
    'tag'             => array_column($tags, 'name', 'id'),
    'list'            => array_column($lists, 'name', 'id'),
    'marketing_consent' => ['1' => 'Yes', '0' => 'No'],
    'suppressed'        => ['1' => 'Yes', '0' => 'No'],
];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p>Combine conditions to describe an audience. The count updates as you build.</p>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
  <input type="hidden" name="definition" id="definitionInput" value="">

  <div class="row">
    <div class="col">
      <div class="card">
        <div class="card__head"><h2>Details</h2></div>
        <div class="card__body">
          <div class="field">
            <label for="name">Segment name</label>
            <input id="name" type="text" name="name" required maxlength="160"
                   value="<?= e($old['name'] ?? (string) ($segment['name'] ?? '')) ?>"
                   placeholder="e.g. Perth customers inactive 6 months">
          </div>
          <div class="field">
            <label for="description">Description</label>
            <input id="description" type="text" name="description" maxlength="255"
                   value="<?= e($old['description'] ?? (string) ($segment['description'] ?? '')) ?>">
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><h2>Conditions</h2></div>
        <div class="card__body">
          <div id="segmentBuilder"
               data-fields='<?= e(json_encode(array_map(static fn (array $f): array => [
                   'label' => $f['label'] ?? '',
                   'type'  => $f['type'] ?? 'string',
               ], $fields), JSON_UNESCAPED_SLASHES)) ?>'
               data-operators='<?= e(json_encode($operators, JSON_UNESCAPED_SLASHES)) ?>'
               data-choices='<?= e(json_encode($choices, JSON_UNESCAPED_SLASHES)) ?>'
               data-definition='<?= e(json_encode($segment['definition'] ?? null, JSON_UNESCAPED_SLASHES)) ?>'>

            <div class="rule-group">
              <div class="rule-group__head">
                Match
                <select data-match style="width:auto">
                  <option value="all">all conditions</option>
                  <option value="any">any condition</option>
                </select>
              </div>

              <div data-rows></div>

              <button class="btn btn--sm" type="button" data-add-rule>Add condition</button>
            </div>
          </div>

          <noscript>
            <div class="alert alert--warning">
              The visual builder needs JavaScript. You can still create segments through the API, or
              enable JavaScript to use this screen.
            </div>
          </noscript>

          <div class="hint">
            Dates accept relative expressions such as <span class="mono">now-180days</span> or
            <span class="mono">now-1year</span>, which is what keeps a saved segment meaning
            "inactive for six months" rather than "inactive since the day I made this".
          </div>
        </div>
      </div>
    </div>

    <div class="col col--narrow">
      <div class="card">
        <div class="card__head"><h2>Audience</h2></div>
        <div class="card__body">
          <div id="segmentPreview">
            <p class="muted small" style="margin:0">Add a condition to see the audience.</p>
          </div>
        </div>
      </div>

      <div class="flex mt-2">
        <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save segment' : 'Create segment' ?></button>
        <a class="btn btn--ghost" href="/segments">Cancel</a>
      </div>
    </div>
  </div>
</form>

<?php $__view->endSection(); ?>
