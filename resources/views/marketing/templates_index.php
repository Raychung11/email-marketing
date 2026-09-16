<?php $__view->extend('layouts.app'); $title = 'Templates'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Templates</h1>
    <p>Reusable email designs. Build once, use for every campaign.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--primary" href="/templates/create">New template</a>
  </div>
</div>

<div class="card mb-2">
  <div class="card__body">
    <form method="get" action="/templates" class="flex wrap">
      <select name="category" data-auto-submit style="max-width:240px">
        <option value="">All categories</option>
        <?php foreach ($categories as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <a class="btn btn--sm btn--ghost" href="/templates">Clear</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__body card__body--tight">
    <?php if ($templates === []): ?>
      <div class="empty">
        <h3>No templates yet</h3>
        <p>Start from a layout that matches what you are sending — the blocks are already arranged.</p>
        <div class="flex wrap" style="justify-content:center">
          <a class="btn" href="/templates/create?category=promotion">Promotion</a>
          <a class="btn" href="/templates/create?category=reactivation">Reactivation</a>
          <a class="btn" href="/templates/create?category=review_request">Review request</a>
          <a class="btn btn--primary" href="/templates/create">Blank</a>
        </div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Template</th><th>Category</th><th class="num">Blocks</th><th>Updated</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($templates as $template): ?>
              <tr>
                <td>
                  <a href="/templates/<?= (int) $template['id'] ?>/edit">
                    <strong><?= e((string) $template['name']) ?></strong>
                  </a>
                  <?php if (!empty($template['description'])): ?>
                    <div class="tiny muted"><?= e((string) $template['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge"><?= e($categories[$template['category']] ?? (string) $template['category']) ?></span></td>
                <td class="num"><?= count((array) $template['blocks']) ?></td>
                <td class="small muted nowrap"><?= e(substr((string) $template['updated_at'], 0, 16)) ?></td>
                <td class="right">
                  <div class="flex" style="justify-content:flex-end">
                    <a class="btn btn--sm" href="/templates/<?= (int) $template['id'] ?>/edit">Edit</a>
                    <form method="post" action="/templates/<?= (int) $template['id'] ?>/duplicate">
                      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                      <button class="btn btn--sm" type="submit">Duplicate</button>
                    </form>
                    <form method="post" action="/templates/<?= (int) $template['id'] ?>/delete"
                          data-confirm="Delete this template?">
                      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                      <button class="btn btn--sm btn--danger" type="submit">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $__view->endSection(); ?>
