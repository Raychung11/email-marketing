<?php $__view->extend('layouts.app'); $title = 'Companies'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Companies</h1><p><?= number_format($total) ?> companies</p></div>
</div>

<div class="row">
  <div class="col">
    <div class="card mb-2">
      <div class="card__body">
        <form method="get" action="/companies" class="flex wrap">
          <input type="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Search by name or domain"
                 style="max-width:280px">
          <select name="country" data-auto-submit style="max-width:200px">
            <option value="">Any country</option>
            <?php foreach ($countries as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= $filters['country'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn--sm btn--primary" type="submit">Search</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__body card__body--tight">
        <?php if ($companies === []): ?>
          <div class="empty"><h3>No companies</h3><p>Companies are created automatically when you set a company on a contact.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Company</th><th>Domain</th><th>Location</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($companies as $company): ?>
                  <tr>
                    <td><a href="/companies/<?= (int) $company['id'] ?>"><strong><?= e((string) $company['name']) ?></strong></a></td>
                    <td class="small muted"><?= e((string) ($company['domain'] ?? '')) ?></td>
                    <td class="small muted">
                      <?= e(trim(implode(', ', array_filter([
                          (string) ($company['city'] ?? ''), (string) ($company['country'] ?? ''),
                      ])))) ?>
                    </td>
                    <td class="right"><a class="btn btn--sm" href="/companies/<?= (int) $company['id'] ?>">Open</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($pages > 1): ?>
        <div class="card__foot">
          <?= $__view->include('partials.pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'query' => $filters]) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>New company</h2></div>
      <div class="card__body">
        <form method="post" action="/companies">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field"><label for="name">Name</label><input id="name" type="text" name="name" required maxlength="200"></div>
          <div class="field"><label for="domain">Domain</label><input id="domain" type="text" name="domain" maxlength="190" placeholder="example.com"></div>
          <div class="field"><label for="phone">Phone</label><input id="phone" type="tel" name="phone" maxlength="40"></div>
          <div class="field">
            <label for="country">Country</label>
            <select id="country" name="country">
              <option value="">Not set</option>
              <?php foreach ($countries as $code => $label): ?>
                <option value="<?= e($code) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Create company</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
