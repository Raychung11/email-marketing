<?php $__view->extend('layouts.app'); $title = 'Who reads your email'; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Who reads your email</h1>
    <p>And what they do afterwards. The last <?= (int) $days ?> days.</p>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Sending, day by day</h2></div>
  <div class="card__body">
    <div style="height:240px">
      <canvas id="sendChart"
              data-days='<?= e(json_encode($report['daily'], JSON_UNESCAPED_SLASHES)) ?>'></canvas>
    </div>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>What people do on your website</h2></div>
      <div class="card__body card__body--tight">
        <?php if ($events === []): ?>
          <div class="empty">
            <h3>Your website is not connected yet</h3>
            <p>
              Add one line to your site and you will see which pages your customers look at before
              they get in touch — which is what makes the enquiry scores above worth reading.
            </p>
            <a class="btn btn--primary" href="/settings/api">How to connect it</a>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>What happened</th><th class="num">Times</th><th class="num">By someone we know</th></tr></thead>
              <tbody>
                <?php foreach ($events as $event): ?>
                  <tr>
                    <td><?= e(ucfirst(str_replace('_', ' ', (string) $event['event_name']))) ?></td>
                    <td class="num"><?= number_format((int) $event['total']) ?></td>
                    <td class="num muted"><?= number_format((int) $event['identified']) ?></td>
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
      <div class="card__head"><h2>About the second column</h2></div>
      <div class="card__body small">
        <p class="mt-0">
          Most people who look at your website are strangers to us, and stay that way. Somebody
          becomes a name only when <em>they</em> do something — press a link in an email you sent
          them, or fill in a form.
        </p>
        <p class="mb-0">
          We do not try to work out who anonymous visitors are. No fingerprinting, no buying data
          about them. If that means the second number is small, that is the honest number.
        </p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
