<?php $__view->extend('layouts.app'); $title = 'Team'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Team</h1><p><?= count($members) ?> <?= count($members) === 1 ? 'member' : 'members' ?></p></div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__body card__body--tight">
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Person</th><th>Role</th><th>Status</th><th>Last active</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($members as $member): ?>
                <tr>
                  <td>
                    <strong><?= e(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: (string) $member['email']) ?></strong>
                    <div class="tiny muted"><?= e((string) $member['email']) ?></div>
                  </td>
                  <td>
                    <form method="post" action="/team/<?= (int) $member['id'] ?>/role" class="flex">
                      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                      <select name="role" data-auto-submit style="width:auto">
                        <?php foreach ($roles as $role): ?>
                          <option value="<?= e((string) $role['key']) ?>"
                            <?= (string) $member['role_key'] === (string) $role['key'] ? 'selected' : '' ?>>
                            <?= e((string) $role['name']) ?>
                          </option>
                        <?php endforeach; ?>
                        <?php if (!in_array((string) $member['role_key'], array_column($roles, 'key'), true)): ?>
                          <option value="<?= e((string) $member['role_key']) ?>" selected>
                            <?= e((string) $member['role_name']) ?>
                          </option>
                        <?php endif; ?>
                      </select>
                    </form>
                  </td>
                  <td>
                    <span class="badge <?= $member['status'] === 'active' ? 'badge--success' : ($member['status'] === 'invited' ? 'badge--warning' : '') ?>">
                      <?= e((string) $member['status']) ?>
                    </span>
                  </td>
                  <td class="small muted nowrap"><?= e(substr((string) ($member['last_active_at'] ?? ''), 0, 16) ?: '—') ?></td>
                  <td class="right">
                    <div class="flex wrap" style="justify-content:flex-end">
                      <?php if ((string) $member['status'] === 'invited'): ?>
                        <form method="post" action="/team/<?= (int) $member['id'] ?>/resend">
                          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                          <button class="btn btn--sm" type="submit">Send again</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" action="/team/<?= (int) $member['id'] ?>/remove"
                            data-confirm="Remove this person's access to this organisation?">
                        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                        <button class="btn btn--sm btn--danger" type="submit">Remove</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>What each role can do</h2></div>
      <div class="card__body small">
        <p class="mt-0">
          Permissions are checked individually, never by role name, so these boundaries hold
          everywhere — including in the API.
        </p>
        <ul style="padding-left:18px;margin:0">
          <li><strong>Owner</strong> — everything, including billing and ownership.</li>
          <li><strong>Administrator</strong> — everything except transferring ownership.</li>
          <li><strong>Marketing manager</strong> — can create, approve and send campaigns.</li>
          <li><strong>Marketer</strong> — can create and edit campaigns, but <em>cannot</em> approve or send them.</li>
          <li><strong>Approver</strong> — can approve campaigns but not author them, which keeps review meaningful.</li>
          <li><strong>Sales</strong> — contacts and leads, no sending.</li>
          <li><strong>Analyst</strong> — read and export, no changes.</li>
          <li><strong>Viewer</strong> — read only.</li>
        </ul>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Invite someone</h2></div>
      <div class="card__body">
        <form method="post" action="/team/invite">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" required maxlength="255">
          </div>
          <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role" required>
              <?php foreach ($roles as $role): ?>
                <option value="<?= e((string) $role['key']) ?>" <?= (string) $role['key'] === 'MARKETER' ? 'selected' : '' ?>>
                  <?= e((string) $role['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Send invitation</button>
          <p class="tiny muted mt-1 mb-0">
            The invitation link expires in 7 days. If the email does not arrive, check
            <a href="/outbox?class=transactional">Sent email</a> — a refusal is recorded there with
            the reason.
          </p>
        </form>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
