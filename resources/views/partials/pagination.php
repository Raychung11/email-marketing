<?php
/**
 * Pagination. Keyset pagination is used for batch jobs; page numbers are fine
 * for a browsing UI where the depth is bounded by what a human will click.
 *
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var array<string,mixed> $query
 */
$query = $query ?? [];
$pages = max(1, (int) $pages);
$page  = max(1, (int) $page);

$url = static function (int $target) use ($query): string {
    $params = array_filter(array_merge($query, ['page' => $target]), static fn ($v): bool => $v !== '' && $v !== null && $v !== 0);

    return '?' . http_build_query($params);
};

$window = 2;
$from   = max(1, $page - $window);
$to     = min($pages, $page + $window);
?>
<?php if ($pages > 1): ?>
  <nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
      <a href="<?= e($url($page - 1)) ?>" rel="prev">Previous</a>
    <?php else: ?>
      <span class="is-disabled">Previous</span>
    <?php endif; ?>

    <?php if ($from > 1): ?>
      <a href="<?= e($url(1)) ?>">1</a>
      <?php if ($from > 2): ?><span class="is-disabled">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $from; $i <= $to; $i++): ?>
      <?php if ($i === $page): ?>
        <span class="is-current" aria-current="page"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= e($url($i)) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($to < $pages): ?>
      <?php if ($to < $pages - 1): ?><span class="is-disabled">…</span><?php endif; ?>
      <a href="<?= e($url($pages)) ?>"><?= $pages ?></a>
    <?php endif; ?>

    <?php if ($page < $pages): ?>
      <a href="<?= e($url($page + 1)) ?>" rel="next">Next</a>
    <?php else: ?>
      <span class="is-disabled">Next</span>
    <?php endif; ?>

    <span class="is-disabled" style="border:0;background:none">
      <?= number_format((int) ($total ?? 0)) ?> total
    </span>
  </nav>
<?php endif; ?>
