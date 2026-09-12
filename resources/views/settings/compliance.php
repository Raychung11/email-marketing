<?php $__view->extend('layouts.app'); $title = 'Compliance'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Compliance centre</h1>
    <p>The rules currently in force, your consent posture, and the audit trail.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn" href="/compliance/audit-log">Full audit log</a>
    <a class="btn" href="/suppressions">Suppression list</a>
  </div>
</div>

<div class="alert alert--info">
  <strong>Compliance-supporting controls, not legal advice</strong>
  This platform helps you record consent, honour opt-outs and demonstrate what happened. It does
  not tell you what the law requires of your business. Rules are versioned and configurable
  precisely because they change.
</div>

<div class="stats mb-2">
  <div class="stat">
    <div class="stat__label">Consent granted</div>
    <div class="stat__value"><?= number_format($consent['granted'] ?? 0) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Consent unknown</div>
    <div class="stat__value"><?= number_format($consent['unknown'] ?? 0) ?></div>
    <div class="stat__meta">blocked where a basis is required</div>
  </div>
  <div class="stat">
    <div class="stat__label">Withdrawn</div>
    <div class="stat__value"><?= number_format($consent['withdrawn'] ?? 0) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Suppressed addresses</div>
    <div class="stat__value"><?= number_format($suppressionTotal) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Unsubscribes recorded</div>
    <div class="stat__value"><?= number_format($unsubscribes) ?></div>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head">
        <h2>Rule set in force</h2>
        <?php if ($activeRule !== null): ?>
          <div class="card__actions">
            <span class="badge"><?= e((string) $activeRule['rule_code']) ?> v<?= (int) $activeRule['version'] ?></span>
          </div>
        <?php endif; ?>
      </div>
      <div class="card__body">
        <?php if ($activeRule === null): ?>
          <p class="mb-0">No rule set has been seeded. Run <span class="mono">php cron/console.php db:seed</span>.</p>
        <?php else: ?>
          <p class="small muted mt-0">
            Applied to contacts in <strong><?= e((string) ($activeRule['country'] ?? '*')) ?></strong>,
            effective from <?= e((string) $activeRule['effective_from']) ?>. A contact's own country
            wins over your organisation's, so an Australian contact on a US account is still
            evaluated against the Australian rules.
          </p>

          <table class="data">
            <tbody>
              <tr>
                <th style="background:none;border:0;text-transform:none">Consent basis required</th>
                <td><?= !empty($ruleConfig['require_consent_basis']) ? 'Yes' : 'No (opt-out jurisdiction)' ?></td>
              </tr>
              <tr>
                <th style="background:none;border:0;text-transform:none">Acceptable bases</th>
                <td><?= e(implode(', ', array_map(
                    static fn (string $t): string => str_replace('_', ' ', $t),
                    (array) ($ruleConfig['acceptable_consent_types'] ?? [])
                ))) ?></td>
              </tr>
              <tr>
                <th style="background:none;border:0;text-transform:none">Inferred consent allowed</th>
                <td><?= !empty($ruleConfig['allow_inferred']) ? 'Yes' : 'No' ?></td>
              </tr>
              <?php if (!empty($ruleConfig['relationship_max_age_days'])): ?>
                <tr>
                  <th style="background:none;border:0;text-transform:none">Existing relationship expires after</th>
                  <td><?= number_format((int) $ruleConfig['relationship_max_age_days']) ?> days</td>
                </tr>
              <?php endif; ?>
              <tr>
                <th style="background:none;border:0;text-transform:none">Physical postal address required</th>
                <td>
                  <?= !empty($ruleConfig['require_postal_address']) ? 'Yes' : 'Recommended' ?>
                  <?php if (empty($organisation['address_line1'])): ?>
                    <span class="badge badge--danger">Not configured</span>
                  <?php else: ?>
                    <span class="badge badge--success">Configured</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th style="background:none;border:0;text-transform:none">Unsubscribe mechanism required</th>
                <td>Yes <span class="badge badge--success">Always added</span></td>
              </tr>
              <tr>
                <th style="background:none;border:0;text-transform:none">Unknown consent</th>
                <td>
                  <?php if (!empty($ruleConfig['require_consent_basis'])): ?>
                    <span class="badge badge--danger">Blocked</span>
                    <span class="mono tiny"><?= e((string) ($ruleConfig['block_reason'] ?? '')) ?></span>
                  <?php else: ?>
                    Permitted, subject to opt-out records
                  <?php endif; ?>
                </td>
              </tr>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Where your contacts are</h2></div>
      <div class="card__body card__body--tight">
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Country</th><th class="num">Contacts</th><th>Rule set applied</th></tr></thead>
            <tbody>
              <?php foreach ($byCountry as $row): ?>
                <?php $code = (string) ($row['country'] ?? ''); ?>
                <tr>
                  <td><?= e($code !== '' ? ($countries[$code] ?? $code) : 'Not recorded') ?></td>
                  <td class="num"><?= number_format((int) $row['total']) ?></td>
                  <td class="small muted">
                    <?= $code === 'AU'
                        ? 'Australia — consent basis required'
                        : ($code === 'US'
                            ? 'United States — opt-out, sender identity and postal address required'
                            : ($code === '' ? 'Falls back to your organisation country' : 'Default — consent basis required')) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <h2>Recent audited actions</h2>
        <div class="card__actions"><a class="btn btn--sm" href="/compliance/audit-log">View all</a></div>
      </div>
      <div class="card__body card__body--tight">
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>When</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($auditLogs, 0, 15) as $log): ?>
                <tr>
                  <td class="small muted nowrap"><?= e(substr((string) $log['created_at'], 0, 16)) ?></td>
                  <td><span class="badge"><?= e(str_replace('_', ' ', (string) $log['action'])) ?></span></td>
                  <td class="small muted">
                    <?= e((string) ($log['entity_type'] ?? '')) ?>
                    <?= $log['entity_id'] !== null ? '#' . (int) $log['entity_id'] : '' ?>
                  </td>
                  <td class="mono tiny muted"><?= e((string) ($log['ip_address'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Approval policy</h2></div>
      <div class="card__body">
        <form method="post" action="/compliance">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <label class="check">
            <input type="checkbox" name="require_campaign_approval" value="1"
              <?= (int) ($organisation['require_campaign_approval'] ?? 1) === 1 ? 'checked' : '' ?>>
            <span>
              Require a second person to approve campaigns before sending
              <div class="tiny muted">
                The author cannot approve their own campaign. Worth keeping on for any team
                larger than one.
              </div>
            </span>
          </label>
          <button class="btn btn--primary btn--sm btn--block mt-2" type="submit">Save</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Suppression by reason</h2></div>
      <div class="card__body">
        <?php if ($suppression === []): ?>
          <p class="small muted mb-0">Nothing suppressed yet.</p>
        <?php else: ?>
          <?php foreach ($suppression as $reason => $count): ?>
            <div class="flex-between small">
              <span><?= e(str_replace('_', ' ', (string) $reason)) ?></span>
              <strong><?= number_format($count) ?></strong>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Import sources</h2></div>
      <div class="card__body small">
        <p class="mt-0 muted">What the platform will and will not accept when importing contacts.</p>
        <?php foreach ($importSources as $key => $definition): ?>
          <div class="flex-between" style="padding:3px 0">
            <span><?= e((string) $definition['label']) ?></span>
            <?php if (!empty($definition['blocked'])): ?>
              <span class="badge badge--danger tiny">refused</span>
            <?php elseif (($definition['status'] ?? '') === 'granted'): ?>
              <span class="badge badge--success tiny">consent</span>
            <?php else: ?>
              <span class="badge badge--warning tiny">unknown</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
