<?php
$__view->extend('layouts.app');

$isEdit = $contact !== null;
$title  = $isEdit ? 'Edit contact' : 'Add contact';
$action = $isEdit ? '/contacts/' . (int) $contact['id'] : '/contacts';

$value = static function (string $field, mixed $default = '') use ($contact, $old): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($contact[$field] ?? $default);
};

$contactTags  = $contactTags ?? [];
$contactLists = $contactLists ?? [];
$values       = $values ?? [];
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p>Contacts are unique per email address within your organisation.</p>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="row">
    <div class="col">
      <div class="card">
        <div class="card__head"><h2>Identity</h2></div>
        <div class="card__body">
          <div class="field">
            <label for="email">Email address *</label>
            <input id="email" type="email" name="email" required maxlength="255" value="<?= e($value('email')) ?>">
            <div class="hint">Used to deduplicate and to match against the suppression list.</div>
          </div>

          <div class="grid-2">
            <div class="field">
              <label for="first_name">First name</label>
              <input id="first_name" type="text" name="first_name" maxlength="100" value="<?= e($value('first_name')) ?>">
            </div>
            <div class="field">
              <label for="last_name">Last name</label>
              <input id="last_name" type="text" name="last_name" maxlength="100" value="<?= e($value('last_name')) ?>">
            </div>
          </div>

          <div class="grid-2">
            <div class="field">
              <label for="phone">Phone</label>
              <input id="phone" type="tel" name="phone" maxlength="40" value="<?= e($value('phone')) ?>">
            </div>
            <div class="field">
              <label for="job_title">Job title</label>
              <input id="job_title" type="text" name="job_title" maxlength="120" value="<?= e($value('job_title')) ?>">
            </div>
          </div>

          <div class="field">
            <label for="company">Company</label>
            <input id="company" type="text" name="company" maxlength="200" value="<?= e($value('company')) ?>">
            <div class="hint">A matching company record is created automatically if one does not exist.</div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><h2>Location</h2></div>
        <div class="card__body">
          <div class="grid-2">
            <div class="field">
              <label for="country">Country</label>
              <select id="country" name="country">
                <option value="">Not set</option>
                <?php foreach ($countries as $code => $label): ?>
                  <option value="<?= e($code) ?>" <?= $value('country') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint">
                Determines which marketing rules apply. Left blank, your organisation's country is used.
              </div>
            </div>
            <div class="field">
              <label for="state">State / region</label>
              <input id="state" type="text" name="state" maxlength="120" value="<?= e($value('state')) ?>">
            </div>
          </div>

          <div class="grid-2">
            <div class="field">
              <label for="city">City / suburb</label>
              <input id="city" type="text" name="city" maxlength="120" value="<?= e($value('city')) ?>">
            </div>
            <div class="field">
              <label for="postcode">Postcode</label>
              <input id="postcode" type="text" name="postcode" maxlength="30" value="<?= e($value('postcode')) ?>">
            </div>
          </div>
        </div>
      </div>

      <?php if ($definitions !== []): ?>
        <div class="card">
          <div class="card__head"><h2>Custom fields</h2></div>
          <div class="card__body">
            <?php foreach ($definitions as $definition): ?>
              <?php
              $key     = (string) $definition['key'];
              $current = $values[$key]['value'] ?? '';
              ?>
              <div class="field">
                <label for="cf_<?= e($key) ?>"><?= e((string) $definition['label']) ?></label>

                <?php if (in_array($definition['type'], ['select', 'multi_select'], true)): ?>
                  <select id="cf_<?= e($key) ?>" name="custom_fields[<?= e($key) ?>]<?= $definition['type'] === 'multi_select' ? '[]' : '' ?>"
                          <?= $definition['type'] === 'multi_select' ? 'multiple size="4"' : '' ?>>
                    <option value="">—</option>
                    <?php foreach ((array) $definition['options'] as $option): ?>
                      <option value="<?= e((string) $option) ?>"
                        <?= (is_array($current) ? in_array($option, $current, true) : $current === $option) ? 'selected' : '' ?>>
                        <?= e((string) $option) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($definition['type'] === 'boolean'): ?>
                  <select id="cf_<?= e($key) ?>" name="custom_fields[<?= e($key) ?>]">
                    <option value="">—</option>
                    <option value="1" <?= $current === true ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $current === false ? 'selected' : '' ?>>No</option>
                  </select>
                <?php else: ?>
                  <input id="cf_<?= e($key) ?>"
                         type="<?= $definition['type'] === 'number' ? 'number' : ($definition['type'] === 'date' ? 'date' : 'text') ?>"
                         step="any"
                         name="custom_fields[<?= e($key) ?>]"
                         value="<?= e(is_array($current) ? implode(', ', $current) : (string) $current) ?>">
                <?php endif; ?>

                <?php if (!empty($definition['help_text'])): ?>
                  <div class="hint"><?= e((string) $definition['help_text']) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col col--narrow">
      <div class="card">
        <div class="card__head"><h2>Classification</h2></div>
        <div class="card__body">
          <div class="field">
            <label for="customer_status">Customer status</label>
            <select id="customer_status" name="customer_status">
              <?php foreach ($statuses as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $value('customer_status', 'lead') === $key ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="lifecycle_stage">Lifecycle stage</label>
            <select id="lifecycle_stage" name="lifecycle_stage">
              <?php foreach ($stages as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $value('lifecycle_stage', 'subscriber') === $key ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="source">Source</label>
            <select id="source" name="source">
              <?php foreach ($sources as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $value('source', 'manual') === $key ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" maxlength="5000"><?= e($value('notes')) ?></textarea>
          </div>
        </div>
      </div>

      <?php if (!$isEdit): ?>
        <!-- Consent is captured at creation with its evidence. Unticked means
             "unknown", which blocks marketing wherever a basis is required. -->
        <div class="card">
          <div class="card__head"><h2>Marketing consent</h2></div>
          <div class="card__body">
            <div class="field">
              <label class="check">
                <input type="checkbox" name="consent_granted" value="1">
                <span>
                  This contact has given consent to receive marketing email
                  <div class="tiny muted">
                    Only tick this if you can point to what they agreed to and when. Leaving it
                    unticked records consent as <em>unknown</em>, and marketing email stays blocked
                    in jurisdictions that require a basis.
                  </div>
                </span>
              </label>
            </div>

            <div class="field">
              <label for="consent_type">Basis</label>
              <select id="consent_type" name="consent_type">
                <option value="express">Express — they actively opted in</option>
                <option value="legitimate_existing_relationship">Existing customer relationship</option>
                <option value="inferred">Inferred</option>
              </select>
            </div>

            <div class="field">
              <label for="consent_reference">Evidence reference</label>
              <input id="consent_reference" type="text" name="consent_reference" maxlength="255"
                     placeholder="e.g. website form, 4 Mar 2026">
            </div>

            <div class="field">
              <label for="consent_text">Wording they agreed to</label>
              <textarea id="consent_text" name="consent_text" maxlength="2000"
                        placeholder="Paste the exact wording shown at the point of opt-in."></textarea>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__head"><h2>Tags</h2></div>
        <div class="card__body">
          <?php if ($tags === []): ?>
            <p class="small muted mb-0">No tags yet. <a href="/tags">Create one</a>.</p>
          <?php else: ?>
            <?php foreach ($tags as $tag): ?>
              <label class="check mb-1">
                <input type="checkbox" name="tag_ids[]" value="<?= (int) $tag['id'] ?>"
                  <?= in_array((int) $tag['id'], $contactTags, true) ? 'checked' : '' ?>>
                <span><?= e((string) $tag['name']) ?></span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><h2>Lists</h2></div>
        <div class="card__body">
          <?php if ($lists === []): ?>
            <p class="small muted mb-0">No lists yet. <a href="/lists">Create one</a>.</p>
          <?php else: ?>
            <?php foreach ($lists as $list): ?>
              <label class="check mb-1">
                <input type="checkbox" name="list_ids[]" value="<?= (int) $list['id'] ?>"
                  <?= in_array((int) $list['id'], $contactLists, true) ? 'checked' : '' ?>>
                <span><?= e((string) $list['name']) ?></span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="flex mt-2">
        <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create contact' ?></button>
        <a class="btn btn--ghost" href="<?= $isEdit ? '/contacts/' . (int) $contact['id'] : '/contacts' ?>">Cancel</a>
      </div>
    </div>
  </div>
</form>

<?php $__view->endSection(); ?>
