<?php $__view->extend('layouts.app'); $title = 'Tags'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div><h1>Tags</h1><p>Free-form labels you can segment and automate on.</p></div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__body card__body--tight">
        <?php if ($tags === []): ?>
          <div class="empty"><h3>No tags yet</h3><p>Tags such as VIP, Hot Lead or Previous Customer make segmentation fast.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Tag</th><th class="num">Contacts</th><th>Description</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($tags as $tag): ?>
                  <tr>
                    <td>
                      <span class="tag" style="<?= !empty($tag['colour']) ? 'border-color:' . e((string) $tag['colour']) : '' ?>">
                        <?= e((string) $tag['name']) ?>
                      </span>
                      <?php if ((int) $tag['is_system'] === 1): ?><span class="badge tiny">default</span><?php endif; ?>
                    </td>
                    <td class="num"><?= number_format((int) $tag['contact_count']) ?></td>
                    <td class="small muted"><?= e((string) ($tag['description'] ?? '')) ?></td>
                    <td class="right">
                      <div class="flex" style="justify-content:flex-end">
                        <a class="btn btn--sm" href="/contacts?tag_id=<?= (int) $tag['id'] ?>">View contacts</a>
                        <form method="post" action="/tags/<?= (int) $tag['id'] ?>/delete"
                              data-confirm="Delete this tag? It is removed from every contact.">
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
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>New tag</h2></div>
      <div class="card__body">
        <form method="post" action="/tags">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="name">Name</label>
            <input id="name" type="text" name="name" required maxlength="80" placeholder="e.g. Emergency Service">
          </div>
          <div class="field">
            <label for="colour">Colour</label>
            <input id="colour" type="text" name="colour" maxlength="7" placeholder="#7c3aed" pattern="^#[0-9a-fA-F]{6}$">
          </div>
          <div class="field">
            <label for="description">Description</label>
            <input id="description" type="text" name="description" maxlength="255">
          </div>
          <button class="btn btn--primary btn--block" type="submit">Create tag</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
