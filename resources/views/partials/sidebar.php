<?php
/**
 * Sidebar navigation.
 *
 * Items are declared in config/navigation.php with the permission each one
 * requires, and filtered here through the same AuthManager the router uses — so a
 * user never sees a link that would 403, and the menu cannot drift from the route
 * table.
 *
 * @var array<int,array<string,mixed>> $navigation
 * @var App\Services\AuthManager|null $auth
 */

$can = static function (?string $permission) use ($auth): bool {
    if ($permission === null || $permission === '') {
        return true;
    }

    return $auth !== null && $auth->can($permission);
};

$isActive = static function (string $route) use ($currentPath): bool {
    if ($route === '/dashboard') {
        return $currentPath === '/dashboard';
    }

    return $currentPath === $route || str_starts_with($currentPath, $route . '/');
};
?>
<aside class="sidebar">
  <div class="sidebar__brand">
    <?= e($appName) ?>
    <small>Customer growth &amp; retention</small>
  </div>

  <?php if (count($organisations) > 1): ?>
    <div class="sidebar__org">
      <form action="/organisations/switch" method="post">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <select name="organisation_id" onchange="this.form.submit()" aria-label="Switch organisation">
          <?php foreach ($organisations as $org): ?>
            <option value="<?= (int) $org['id'] ?>"
              <?= (int) $org['id'] === (int) ($organisation['id'] ?? 0) ? 'selected' : '' ?>>
              <?= e((string) $org['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  <?php elseif ($organisation !== null): ?>
    <div class="sidebar__org">
      <div class="tiny" style="color:#94a3b8">Organisation</div>
      <div style="color:#e2e8f0;font-weight:600"><?= e((string) ($organisation['name'] ?? '')) ?></div>
    </div>
  <?php endif; ?>

  <nav class="sidebar__nav">
    <?php foreach ($navigation as $item): ?>
      <?php if (!$can($item['permission'] ?? null)) { continue; } ?>

      <?php if (isset($item['children'])): ?>
        <?php
        $visibleChildren = array_values(array_filter(
            $item['children'],
            static fn (array $child): bool => $can($child['permission'] ?? null)
        ));
        ?>
        <?php if ($visibleChildren === []) { continue; } ?>

        <div class="sidebar__group">
          <div class="sidebar__label"><?= e((string) $item['label']) ?></div>
          <div class="sidebar__sub">
            <?php foreach ($visibleChildren as $child): ?>
              <?php $phase = $child['phase'] ?? null; ?>
              <?php if ($phase !== null): ?>
                <span class="sidebar__link is-disabled" title="Arrives in phase <?= (int) $phase ?>">
                  <?= e((string) $child['label']) ?>
                  <span class="phase">P<?= (int) $phase ?></span>
                </span>
              <?php else: ?>
                <a class="sidebar__link <?= $isActive((string) $child['route']) ? 'is-active' : '' ?>"
                   href="<?= e((string) $child['route']) ?>"><?= e((string) $child['label']) ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <?php $phase = $item['phase'] ?? null; ?>
        <?php if ($phase !== null): ?>
          <span class="sidebar__link is-disabled" title="Arrives in phase <?= (int) $phase ?>">
            <?= e((string) $item['label']) ?>
            <span class="phase">P<?= (int) $phase ?></span>
          </span>
        <?php else: ?>
          <a class="sidebar__link <?= $isActive((string) $item['route']) ? 'is-active' : '' ?>"
             href="<?= e((string) $item['route']) ?>"><?= e((string) $item['label']) ?></a>
        <?php endif; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
</aside>
