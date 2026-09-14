<?php
/**
 * Authenticated application shell: sidebar, sticky header, content area.
 *
 * @var App\Core\View $__view
 * @var array<string,mixed>|null $organisation
 * @var array<int,array<string,mixed>> $organisations
 * @var App\Services\AuthManager|null $auth
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Dashboard') ?> &middot; <?= e($appName ?? 'AI Growth Hub') ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app">
  <?= $__view->include('partials.sidebar', [
      'navigation'  => $navigation ?? [],
      'currentPath' => $currentPath ?? '/',
      'auth'        => $auth ?? null,
      'appName'     => $appName ?? 'AI Growth Hub',
      'organisation' => $organisation ?? null,
      'organisations' => $organisations ?? [],
      'csrfToken'   => $csrfToken ?? '',
  ]) ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar__search">
        <form action="/contacts" method="get" role="search">
          <input type="search" name="search" placeholder="Search contacts by name, email, company or phone…"
                 value="<?= e($filters['search'] ?? '') ?>" aria-label="Search contacts">
        </form>
      </div>

      <div class="topbar__user">
        <?php if (($organisation['sending_paused'] ?? 0)): ?>
          <span class="badge badge--danger badge--dot" title="<?= e((string) ($organisation['sending_paused_reason'] ?? '')) ?>">
            Sending paused
          </span>
        <?php endif; ?>

        <?php if (($organisation['status'] ?? '') === 'trialing'): ?>
          <span class="badge badge--info">Trial</span>
        <?php endif; ?>

        <a class="btn btn--ghost btn--sm" href="/settings/profile">Profile</a>

        <form action="/logout" method="post" style="display:inline">
          <input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
          <button class="btn btn--ghost btn--sm" type="submit">Sign out</button>
        </form>

        <span class="avatar" title="<?= e($currentUser['email'] ?? '') ?>">
          <?= e(App\Support\Str::initials(
              (string) ($currentUser['first_name'] ?? ''),
              (string) ($currentUser['last_name'] ?? ($currentUser['email'] ?? ''))
          )) ?>
        </span>
      </div>
    </header>

    <main class="content">
      <?= $__view->include('partials.flash', [
          'success' => $success ?? null,
          'error'   => $error ?? null,
          'warning' => $warning ?? null,
          'errors'  => $errors ?? [],
      ]) ?>

      <?= $__view->section('content') ?>
    </main>
  </div>
</div>

<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/password-toggle.js" defer></script>
<?= $__view->section('scripts') ?>
</body>
</html>
