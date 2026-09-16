<?php
$__view->extend('layouts.app');
$title = 'Write it for me';
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Write it for me</h1>
    <p>Tell us what you want to say. We will write a first draft — you decide what happens to it.</p>
  </div>
</div>

<?php if (!$context['available']): ?>
  <div class="alert alert--warning">
    <strong>This is not switched on yet</strong>
    Nobody has connected an AI service to this installation, so there is nothing to write with.
    Everything else in the product works without it.
  </div>
<?php endif; ?>

<div class="alert alert--info">
  <strong>Whatever comes back is a draft, and only a draft</strong>
  It gets saved the same way as something you typed yourself: read it over, send it for checking,
  and somebody signs it off before it goes anywhere. Nothing here can send an email.
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head"><h2>What is this email for?</h2></div>
      <div class="card__body">
        <form method="post" action="/ai/studio">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

          <div class="field">
            <label for="goal">What do you want it to do?</label>
            <select id="goal" name="goal" required>
              <option value="">Choose one…</option>
              <?php foreach ($context['goals'] as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($brief['goal'] ?? '') === $key ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="grid-2">
            <div class="field">
              <label for="tone">How should it sound?</label>
              <select id="tone" name="tone">
                <?php foreach ($context['tones'] as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= ($brief['tone'] ?? 'friendly') === $key ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="segment_id">Who is it for?</label>
              <select id="segment_id" name="segment_id">
                <option value="">Everyone you are allowed to email</option>
                <?php foreach ($context['segments'] as $segment): ?>
                  <option value="<?= (int) $segment['id'] ?>"
                    <?= (int) ($brief['segment_id'] ?? 0) === (int) $segment['id'] ? 'selected' : '' ?>>
                    <?= e((string) $segment['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field">
            <label for="offer">Is there an offer or a specific thing to mention?</label>
            <input id="offer" type="text" name="offer" maxlength="300"
                   value="<?= e((string) ($brief['offer'] ?? '')) ?>"
                   placeholder="e.g. $50 off a winter boiler service, booked before 31 August">
            <div class="hint">
              Be exact. We will not make up a price, a discount or a date — if you do not put it
              here, it will not appear in the email.
            </div>
          </div>

          <div class="field">
            <label for="link">Where should the button go?</label>
            <input id="link" type="text" name="link" maxlength="255"
                   value="<?= e((string) ($brief['link'] ?? '')) ?>"
                   placeholder="https://yourbusiness.com.au/book">
            <div class="hint">
              This is the only web address the email may link to. Leave it empty and you will get a
              button with no link, for you to fill in.
            </div>
          </div>

          <div class="field">
            <label for="notes">Anything else worth knowing?</label>
            <textarea id="notes" name="notes" rows="4" maxlength="1000"
                      placeholder="e.g. We are a family business, been in Fremantle 22 years. Most of these customers had a boiler fitted two winters ago."><?= e((string) ($brief['notes'] ?? '')) ?></textarea>
          </div>

          <button class="btn btn--primary" type="submit" <?= $context['available'] ? '' : 'disabled' ?>>
            Write me a draft
          </button>
        </form>
      </div>
    </div>

    <?php if ($draft !== null): ?>
      <div class="card">
        <div class="card__head">
          <h2>Here is a draft</h2>
          <div class="card__actions"><span class="badge">Written by AI</span></div>
        </div>
        <div class="card__body">
          <?php if ($draft['needs_extra_care']): ?>
            <div class="alert alert--warning">
              <strong>Read this one especially carefully</strong>
              Your trade is regulated, so anything that sounds like a promise about a treatment or a
              result can get you in trouble even if it was not meant that way. Check every sentence
              before this goes out.
            </div>
          <?php endif; ?>

          <?php if ($draft['warnings'] !== []): ?>
            <div class="alert alert--warning">
              <strong>We changed a few things</strong>
              <ul>
                <?php foreach ($draft['warnings'] as $warning): ?>
                  <li><?= e((string) $warning) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if ($draft['notes_to_user'] !== ''): ?>
            <p class="small muted mt-0"><?= e((string) $draft['notes_to_user']) ?></p>
          <?php endif; ?>

          <form method="post" action="/ai/studio/keep">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="draft" value="<?= e((string) json_encode($draft)) ?>">
            <input type="hidden" name="brief" value="<?= e((string) json_encode($brief)) ?>">

            <div class="field">
              <label>Pick a subject line</label>
              <?php foreach ($draft['subject_options'] as $i => $subject): ?>
                <label class="check">
                  <input type="radio" name="subject" value="<?= e($subject) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                  <span><?= e($subject) ?> <span class="tiny muted">(<?= mb_strlen($subject) ?> characters)</span></span>
                </label>
              <?php endforeach; ?>
            </div>

            <?php if ($draft['preview_text'] !== ''): ?>
              <p class="small muted">
                <strong>Preview line:</strong> <?= e((string) $draft['preview_text']) ?>
                <span class="tiny">— the grey text people see next to the subject in their inbox.</span>
              </p>
            <?php endif; ?>

            <button class="btn btn--primary" type="submit">Save this as a draft</button>
            <a class="btn" href="/ai/studio">Start again</a>
          </form>

          <p class="tiny muted" style="margin-top:12px;margin-bottom:0">
            <?= e((string) $draft['disclaimer']) ?>
          </p>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col col--narrow">
    <?php if ($preview !== null): ?>
      <div class="card">
        <div class="card__head"><h2>What it will look like</h2></div>
        <div class="card__body card__body--tight">
          <iframe title="Draft preview" sandbox srcdoc="<?= e($preview) ?>"
                  style="width:100%;height:520px;border:0;background:#fff"></iframe>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><h2>What it will not do</h2></div>
      <div class="card__body small">
        <p class="mt-0">Worth knowing, because other tools are looser about this:</p>
        <p><strong>It cannot send anything.</strong> Not now, not on a timer, not by accident.</p>
        <p><strong>It cannot make up numbers.</strong> No prices, no dates, no "join 5,000 happy
        customers" unless you told us that is true.</p>
        <p><strong>It cannot write a review.</strong> Those have to come from real people.</p>
        <p class="mb-0"><strong>It cannot email someone who said no.</strong> Your do-not-email list
        and everyone's permissions still apply, exactly as they always do.</p>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>This month's usage</h2></div>
      <div class="card__body small">
        <div class="flex-between">
          <span>Used</span>
          <strong><?= number_format((int) $context['usage']['used']) ?></strong>
        </div>
        <div class="flex-between muted">
          <span>Included</span>
          <span><?= number_format((int) $context['usage']['cap']) ?></span>
        </div>
        <p class="tiny muted mb-0" style="margin-top:8px">Resets on the 1st.</p>
      </div>
    </div>
  </div>
</div>

<?php $__view->endSection(); ?>
