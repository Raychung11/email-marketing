<?php $__view->extend('layouts.app'); $title = (string) $company['name']; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e((string) $company['name']) ?></h1>
    <p><?= e((string) ($company['domain'] ?? '')) ?></p>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>Contacts at this company</h2></div>
      <div class="card__body card__body--tight">
        <?php if ($contacts === []): ?>
          <div class="empty" style="padding:24px"><p class="mb-0">No contacts linked yet.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Contact</th><th>Job title</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($contacts as $contact): ?>
                  <tr>
                    <td>
                      <a href="/contacts/<?= (int) $contact['id'] ?>">
                        <?= e(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: (string) $contact['email']) ?>
                      </a>
                      <div class="tiny muted"><?= e((string) $contact['email']) ?></div>
                    </td>
                    <td class="small"><?= e((string) ($contact['job_title'] ?? '')) ?></td>
                    <td><span class="badge"><?= e(str_replace('_', ' ', (string) $contact['customer_status'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Company details</h2></div>
      <div class="card__body">
        <form method="post" action="/companies/<?= (int) $company['id'] ?>">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field"><label for="name">Name</label><input id="name" type="text" name="name" required value="<?= e((string) $company['name']) ?>"></div>
          <div class="field"><label for="domain">Domain</label><input id="domain" type="text" name="domain" value="<?= e((string) ($company['domain'] ?? '')) ?>"></div>
          <div class="field"><label for="phone">Phone</label><input id="phone" type="tel" name="phone" value="<?= e((string) ($company['phone'] ?? '')) ?>"></div>
          <div class="field"><label for="website">Website</label><input id="website" type="text" name="website" value="<?= e((string) ($company['website'] ?? '')) ?>"></div>
          <div class="field"><label for="city">City</label><input id="city" type="text" name="city" value="<?= e((string) ($company['city'] ?? '')) ?>"></div>
          <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= e((string) ($company['notes'] ?? '')) ?></textarea></div>
          <button class="btn btn--primary btn--block" type="submit">Save</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
