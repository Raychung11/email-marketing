<?php
/**
 * User-facing error page.
 *
 * A stack trace is only ever rendered when APP_DEBUG is true. In production the
 * user gets a correlation id, which is what makes their support request
 * actionable without telling an attacker anything.
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="auth-shell">
  <div class="auth-card <?= !empty($debug) ? 'auth-card--wide' : '' ?>">
    <div class="card">
      <div class="card__body">
        <div class="tiny muted"><?= (int) $status ?></div>
        <h1 style="font-size:19px;margin:2px 0 8px"><?= e($title) ?></h1>
        <p class="small" style="margin:0 0 14px"><?= e($message) ?></p>

        <?php if ((int) $status === 419): ?>
          <p class="small muted">
            This usually means the page was left open for a long time. Go back, reload, and try again —
            your work is not lost.
          </p>
        <?php endif; ?>

        <div class="flex">
          <a class="btn btn--primary btn--sm" href="/dashboard">Back to dashboard</a>
          <a class="btn btn--sm" href="/login">Sign in</a>
        </div>

        <p class="tiny muted mt-2 mb-0">
          Reference: <span class="mono"><?= e($traceId) ?></span>
          <br>Quote this if you contact support — it points straight at the log entry.
        </p>

        <?php if (!empty($debug) && isset($exception)): ?>
          <hr class="sep">
          <div class="tiny muted">Shown because APP_DEBUG is enabled. Never enable this in production.</div>
          <pre class="mono" style="white-space:pre-wrap;overflow-x:auto;background:#f8fafc;padding:10px;border-radius:6px"><?=
            e($exception::class . ': ' . $exception->getMessage() . "\n"
              . $exception->getFile() . ':' . $exception->getLine() . "\n\n"
              . $exception->getTraceAsString())
          ?></pre>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
