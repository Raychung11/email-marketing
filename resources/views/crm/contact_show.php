<?php
/**
 * Customer 360.
 *
 * The eligibility panel shows the LIVE compliance decision with its reason code,
 * not a cached flag — it is the same decision the send path will make.
 */
$__view->extend('layouts.app');

$contact     = $profile['contact'];
$eligibility = $profile['eligibility'];
$consent     = $profile['consent'];
$suppression = $profile['suppression'];
$title       = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: (string) $contact['email'];
$currency    = (string) ($contact['currency'] ?: ($organisation['currency'] ?? 'USD'));
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p>
      <?= e((string) $contact['email']) ?>
      <?php if (!empty($contact['company'])): ?> &middot; <?= e((string) $contact['company']) ?><?php endif; ?>
    </p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/contacts/<?= (int) $contact['id'] ?>/edit">Edit</a>
    <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/delete"
          data-confirm="Delete this contact? Their suppression record and consent history are kept.">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <button class="btn btn--danger" type="submit">Delete</button>
    </form>
  </div>
</div>

<!-- Eligibility first: it is the question a marketer actually has. -->
<div class="alert <?= $eligibility['allowed'] ? 'alert--success' : 'alert--warning' ?>">
  <strong>
    <?= $eligibility['allowed']
        ? 'Eligible for marketing email'
        : 'Marketing email is blocked for this contact' ?>
  </strong>
  <?= e($eligibility['message']) ?>
  <?php if (!$eligibility['allowed']): ?>
    <div class="tiny mono mt-1">Reason code: <?= e($eligibility['reason']) ?></div>
  <?php endif; ?>
  <div class="tiny muted mt-1">
    Rules applied: <?= e(implode(' → ', $eligibility['rules_applied'])) ?>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>Details</h2></div>
      <div class="card__body">
        <table class="data">
          <tbody>
            <tr><th style="background:none;border:0;text-transform:none">Email</th><td><?= e((string) $contact['email']) ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Phone</th><td><?= e((string) ($contact['phone'] ?? '—')) ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Job title</th><td><?= e((string) ($contact['job_title'] ?? '—')) ?></td></tr>
            <tr>
              <th style="background:none;border:0;text-transform:none">Location</th>
              <td><?= e(trim(implode(', ', array_filter([
                  (string) ($contact['city'] ?? ''),
                  (string) ($contact['state'] ?? ''),
                  (string) ($contact['postcode'] ?? ''),
                  (string) ($contact['country'] ?? ''),
              ]))) ?: '—') ?></td>
            </tr>
            <tr><th style="background:none;border:0;text-transform:none">Status</th><td><?= e($statuses[$contact['customer_status']] ?? '—') ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Lifecycle</th><td><?= e(str_replace('_', ' ', (string) $contact['lifecycle_stage'])) ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Source</th><td><?= e((string) ($contact['source'] ?? '—')) ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Lead score</th><td><?= (int) $contact['lead_score'] ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Lifetime revenue</th><td><?= e(money((float) $contact['total_revenue'], $currency)) ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Purchases</th><td><?= (int) $contact['purchase_count'] ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Last purchase</th><td><?= e((string) ($contact['last_purchase_at'] ?? '—')) ?></td></tr>
            <tr><th style="background:none;border:0;text-transform:none">Last engagement</th><td><?= e((string) ($contact['last_engagement_at'] ?? '—')) ?></td></tr>
          </tbody>
        </table>

        <?php if ($profile['custom_fields'] !== []): ?>
          <hr class="sep">
          <h3 style="font-size:13px;margin:0 0 8px">Custom fields</h3>
          <table class="data">
            <tbody>
              <?php foreach ($profile['custom_fields'] as $field): ?>
                <tr>
                  <th style="background:none;border:0;text-transform:none"><?= e((string) $field['label']) ?></th>
                  <td><?= e(is_array($field['value']) ? implode(', ', $field['value']) : (string) $field['value']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <h2>Consent history</h2>
        <div class="card__actions"><span class="badge">Append-only</span></div>
      </div>
      <div class="card__body">
        <p class="tiny muted mt-0">
          Every entry is kept. A change of mind adds a row; nothing here is ever edited
          or deleted, because the history is the evidence.
        </p>

        <?php if ($profile['consent_history'] === []): ?>
          <p class="small muted mb-0">No consent has been recorded, so marketing email is blocked
          wherever a consent basis is required.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>When</th><th>Channel</th><th>Status</th><th>Basis</th><th>Source</th><th>Evidence</th></tr>
              </thead>
              <tbody>
                <?php foreach ($profile['consent_history'] as $row): ?>
                  <tr>
                    <td class="small nowrap"><?= e((string) $row['created_at']) ?></td>
                    <td class="small"><?= e((string) $row['channel']) ?></td>
                    <td>
                      <span class="badge <?= $row['status'] === 'granted' ? 'badge--success' : ($row['status'] === 'unknown' ? 'badge--warning' : 'badge--danger') ?>">
                        <?= e((string) $row['status']) ?>
                      </span>
                      <?php if (!empty($row['topic'])): ?>
                        <div class="tiny muted"><?= e((string) $row['topic']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="small"><?= e(str_replace('_', ' ', (string) $row['consent_type'])) ?></td>
                    <td class="small"><?= e(str_replace('_', ' ', (string) $row['source'])) ?></td>
                    <td class="tiny muted">
                      <?php if (!empty($row['source_reference'])): ?>
                        <div><?= e((string) $row['source_reference']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($row['ip_address'])): ?>
                        <div class="mono"><?= e((string) $row['ip_address']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($row['privacy_policy_version'])): ?>
                        <div>policy v<?= e((string) $row['privacy_policy_version']) ?></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <hr class="sep">

        <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/consent">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <h3 style="font-size:13px;margin:0 0 8px">Record a consent decision</h3>
          <div class="grid-3">
            <div class="field">
              <label for="status">Status</label>
              <select id="status" name="status" required>
                <option value="granted">Granted</option>
                <option value="withdrawn">Withdrawn</option>
                <option value="denied">Denied</option>
                <option value="unknown">Unknown</option>
              </select>
            </div>
            <div class="field">
              <label for="consent_type">Basis</label>
              <select id="consent_type" name="consent_type">
                <option value="express">Express (they opted in)</option>
                <option value="legitimate_existing_relationship">Existing customer relationship</option>
                <option value="inferred">Inferred</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="field">
              <label for="reference">Evidence reference</label>
              <input id="reference" type="text" name="reference" maxlength="255"
                     placeholder="e.g. web form 12 Mar, call log #4821">
            </div>
          </div>
          <div class="field">
            <label for="consent_text">Wording shown to the contact</label>
            <textarea id="consent_text" name="consent_text" maxlength="2000"
                      placeholder="Paste the exact consent wording they agreed to. This is the evidence."></textarea>
          </div>
          <button class="btn btn--primary btn--sm" type="submit">Record consent</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Timeline</h2></div>
      <div class="card__body">
        <?php if ($profile['timeline'] === []): ?>
          <p class="small muted mb-0">No activity recorded yet.</p>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($profile['timeline'] as $item): ?>
              <?php
              $type  = (string) $item['activity_type'];
              $class = str_contains($type, 'consent') || str_contains($type, 'unsubscrib')
                  ? 'timeline__item--consent'
                  : (str_contains($type, 'email') || str_contains($type, 'click') || str_contains($type, 'open')
                      ? 'timeline__item--engage'
                      : '');
              ?>
              <div class="timeline__item <?= $class ?>">
                <div class="timeline__time"><?= e((string) $item['occurred_at']) ?> UTC</div>
                <div class="timeline__text">
                  <?= e((string) ($item['description'] ?? str_replace('_', ' ', $type))) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <?php if ($suppression !== null): ?>
      <div class="card">
        <div class="card__head"><h2>Suppressed</h2></div>
        <div class="card__body">
          <p class="small mt-0">
            This address is on the suppression list and will be skipped by every marketing send,
            including after a re-import.
          </p>
          <table class="data">
            <tbody>
              <tr><th style="background:none;border:0;text-transform:none">Reason</th><td><?= e((string) $suppression['reason']) ?></td></tr>
              <tr><th style="background:none;border:0;text-transform:none">Source</th><td><?= e((string) ($suppression['source'] ?? '—')) ?></td></tr>
              <tr><th style="background:none;border:0;text-transform:none">Since</th><td class="small"><?= e((string) $suppression['created_at']) ?></td></tr>
            </tbody>
          </table>
          <a class="btn btn--sm btn--block mt-2" href="/suppressions?search=<?= e(rawurlencode((string) $contact['email'])) ?>">
            Manage in suppression list
          </a>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><h2>Tags</h2></div>
      <div class="card__body">
        <div class="flex wrap mb-2">
          <?php if ($profile['tags'] === []): ?>
            <span class="muted small">No tags</span>
          <?php else: ?>
            <?php foreach ($profile['tags'] as $tag): ?>
              <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/tags/<?= (int) $tag['id'] ?>/remove">
                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                <button class="tag" type="submit" title="Remove tag" style="cursor:pointer">
                  <?= e((string) $tag['name']) ?> ×
                </button>
              </form>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/tags" class="flex">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <select name="tag_id" required>
            <option value="">Add a tag…</option>
            <?php foreach ($allTags as $tag): ?>
              <option value="<?= (int) $tag['id'] ?>"><?= e((string) $tag['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn--sm" type="submit">Add</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Lists</h2></div>
      <div class="card__body">
        <?php if ($profile['lists'] === []): ?>
          <p class="small muted mb-0">Not on any list.</p>
        <?php else: ?>
          <?php foreach ($profile['lists'] as $list): ?>
            <div class="flex-between small">
              <a href="/lists/<?= (int) $list['id'] ?>"><?= e((string) $list['name']) ?></a>
              <span class="muted tiny">via <?= e((string) $list['added_via']) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($profile['company'] !== null): ?>
      <div class="card">
        <div class="card__head"><h2>Company</h2></div>
        <div class="card__body">
          <a href="/companies/<?= (int) $profile['company']['id'] ?>">
            <strong><?= e((string) $profile['company']['name']) ?></strong>
          </a>
          <?php if (!empty($profile['company']['domain'])): ?>
            <div class="tiny muted"><?= e((string) $profile['company']['domain']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><h2>Privacy</h2></div>
      <div class="card__body">
        <p class="tiny muted mt-0">
          Anonymising clears personal fields in place and keeps the address suppressed, so the
          erasure itself can be honoured. Consent history, suppression and audit records are
          retained.
        </p>
        <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/anonymise"
              data-confirm="Anonymise this contact? Personal details are cleared permanently and the address stays suppressed.">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn btn--danger btn--sm btn--block" type="submit">Anonymise contact</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
