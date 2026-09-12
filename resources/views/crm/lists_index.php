<?php $__view->extend('layouts.app'); $title = 'Lists'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Lists</h1>
    <p>Static groups you add contacts to by hand. For rules that evaluate themselves, use
      <a href="/segments">segments</a>.</p>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__body card__body--tight">
        <?php if ($lists === []): ?>
          <div class="empty"><h3>No lists yet</h3><p>Newsletter Subscribers, Existing Customers, Perth Customers — whatever suits how you work.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>List</th><th class="num">Contacts</th><th>Description</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($lists as $list): ?>
                  <tr>
                    <td><a href="/lists/<?= (int) $list['id'] ?>"><strong><?= e((string) $list['name']) ?></strong></a></td>
                    <td class="num"><?= number_format((int) $list['contact_count']) ?></td>
                    <td class="small muted"><?= e((string) ($list['description'] ?? '')) ?></td>
                    <td class="right"><a class="btn btn--sm" href="/lists/<?= (int) $list['id'] ?>">Open</a></td>
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
      <div class="card__head"><h2>New list</h2></div>
      <div class="card__body">
        <form method="post" action="/lists">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <div class="field">
            <label for="name">Name</label>
            <input id="name" type="text" name="name" required maxlength="160" placeholder="e.g. Christmas Campaign">
          </div>
          <div class="field">
            <label for="description">Description</label>
            <input id="description" type="text" name="description" maxlength="255">
          </div>
          <button class="btn btn--primary btn--block" type="submit">Create list</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
