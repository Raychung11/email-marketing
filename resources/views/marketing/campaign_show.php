<?php
/**
 * Campaign detail: the workflow surface.
 *
 * The actions offered depend on the status AND on who is looking — an author is
 * shown why they cannot approve their own campaign rather than being given a
 * button that fails.
 */
$__view->extend('layouts.app');

$title  = (string) $campaign['name'];
$status = (string) $campaign['status'];

$blocking = $findings['blocking'] ?? [];
$warnings = $findings['warnings'] ?? [];
$validated = ($campaign['validated_at'] ?? null) !== null;
?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1><?= e((string) $campaign['name']) ?></h1>
    <p>
      <span class="badge <?= match ($status) {
          'completed' => 'badge--success',
          'sending', 'scheduled' => 'badge--info',
          'pending_review', 'paused' => 'badge--warning',
          'failed', 'cancelled' => 'badge--danger',
          default => '',
      } ?>"><?= e($statuses[$status] ?? $status) ?></span>
      <span class="muted"><?= e($types[$campaign['campaign_type']] ?? '') ?></span>
      <?php if (!empty($campaign['scheduled_at'])): ?>
        &middot; <span class="muted small">
          scheduled <?= e(substr((string) $campaign['scheduled_at'], 0, 16)) ?> UTC
        </span>
      <?php endif; ?>
    </p>
  </div>
  <div class="page-head__actions">
    <?php if (in_array($status, ['draft', 'pending_review'], true)): ?>
      <a class="btn" href="/campaigns/<?= (int) $campaign['id'] ?>/edit">Edit</a>
    <?php endif; ?>
    <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/duplicate">
      <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
      <button class="btn" type="submit">Duplicate</button>
    </form>
  </div>
</div>

<!-- The workflow strip: what happens next, and what is standing in the way. -->
<div class="card mb-2">
  <div class="card__body">
    <?php if ($status === 'draft'): ?>
      <?php if ($blocking !== []): ?>
        <div class="alert alert--danger" style="margin-bottom:12px">
          <strong>Not ready to send yet</strong>
          <ul>
            <?php foreach ($blocking as $finding): ?>
              <li><?= e((string) $finding['message']) ?> <span class="mono tiny">(<?= e((string) $finding['code']) ?>)</span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php elseif ($validated): ?>
        <div class="alert alert--success" style="margin-bottom:12px">
          <strong>All checks passed</strong>
          Who it is from, your email set-up, the wording, the unsubscribe link, your postal address
          and who it goes to — all fine.
        </div>
      <?php endif; ?>

      <?php foreach ($warnings as $warning): ?>
        <div class="alert alert--warning" style="margin-bottom:12px">
          <?= e((string) $warning['message']) ?>
        </div>
      <?php endforeach; ?>

      <div class="flex wrap">
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/validate">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn" type="submit">Check it over</button>
        </form>
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/submit">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn btn--primary" type="submit">Send for review</button>
        </form>
      </div>

    <?php elseif ($status === 'pending_review'): ?>
      <?php if ($isAuthor): ?>
        <div class="alert alert--info" style="margin-bottom:12px">
          <strong>Waiting for someone else to check it</strong>
          You wrote this one, so you cannot sign it off yourself — a second pair of eyes is the
          whole point. Ask a colleague who is allowed to approve to have a look.
        </div>
      <?php elseif ($canApprove): ?>
        <div class="alert alert--info" style="margin-bottom:12px">
          <strong>Someone has asked you to check this</strong>
          Have a read of who it goes to, the subject line and the wording before you sign it off.
        </div>
        <div class="flex wrap">
          <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/approve">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <button class="btn btn--primary" type="submit">Approve</button>
          </form>
          <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/request-changes" class="flex">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <input type="text" name="reason" required maxlength="1000" placeholder="What needs changing?" style="min-width:260px">
            <button class="btn" type="submit">Request changes</button>
          </form>
        </div>
      <?php else: ?>
        <p class="mb-0 muted">Waiting for someone who is allowed to sign it off.</p>
      <?php endif; ?>

    <?php elseif ($status === 'approved'): ?>
      <div class="alert alert--success" style="margin-bottom:12px">
        <strong>Signed off<?= !empty($campaign['approved_at']) ? ' on ' . e(substr((string) $campaign['approved_at'], 0, 16)) . ' UTC' : '' ?></strong>
        Pick a time, or send it straight away. If you change the wording now it has to be checked
        again — otherwise the sign-off would not mean anything.
      </div>
      <div class="flex wrap">
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/schedule" class="flex">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <input type="datetime-local" name="scheduled_at" required>
          <button class="btn" type="submit">Schedule</button>
          <span class="tiny muted">times in <?= e($timezone) ?></span>
        </form>
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/send"
              data-confirm="Send this to <?= number_format($audience['eligible']) ?> people? You cannot take it back once it goes.">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn btn--primary" type="submit">Send now</button>
        </form>
      </div>

    <?php elseif ($status === 'scheduled'): ?>
      <div class="flex wrap">
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/unschedule">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn" type="submit">Unschedule</button>
        </form>
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/cancel"
              data-confirm="Cancel this campaign?">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn btn--danger" type="submit">Cancel</button>
        </form>
      </div>

    <?php elseif ($status === 'sending'): ?>
      <div class="alert alert--info" style="margin-bottom:12px">
        <strong>Sending now</strong>
        <?php // Read live from the snapshot: the campaign counters are a rollup
              // refreshed when the send finishes, so mid-flight they lag. ?>
        <?= number_format((int) ($delivery['send_status']['sent'] ?? $campaign['sent_count'])) ?> of
        <?= number_format((int) ($delivery['eligible'] ?? $campaign['eligible_count'])) ?>
        sent so far.
      </div>
      <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/pause"
            data-confirm="Stop this send? Emails that have already gone out cannot be pulled back, but nothing more will be sent.">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <button class="btn btn--danger" type="submit">Stop sending</button>
      </form>

    <?php elseif ($status === 'paused'): ?>
      <div class="alert alert--warning" style="margin-bottom:12px">
        <strong>Paused</strong>
        <?= e((string) ($campaign['pause_reason'] ?? '')) ?>
      </div>
      <div class="flex wrap">
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/resume">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn btn--primary" type="submit">Resume</button>
        </form>
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/cancel" data-confirm="Cancel this campaign?">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn btn--danger" type="submit">Cancel</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col">
    <div class="card">
      <div class="card__head">
        <h2>Content</h2>
        <div class="card__actions">
          <span class="badge"><?= e((string) ($campaign['subject'] ?? 'No subject')) ?></span>
        </div>
      </div>
      <div class="card__body" style="background:#f4f5f7">
        <?php if (trim(strip_tags((string) $campaign['html_content'])) === ''): ?>
          <div class="empty" style="padding:28px">
            <h3>Nothing written yet</h3>
            <p>Start from a template, or write it yourself.</p>
            <a class="btn btn--primary" href="/campaigns/<?= (int) $campaign['id'] ?>/edit">Write the email</a>
          </div>
        <?php else: ?>
          <iframe src="/campaigns/<?= (int) $campaign['id'] ?>/preview" title="Campaign preview" sandbox=""
                  style="width:100%;height:600px;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($report !== null && in_array($status, ['sending', 'completed', 'paused'], true)): ?>
      <?php $funnel = $report['funnel']; ?>
      <div class="card">
        <div class="card__head">
          <h2>What happened</h2>
          <div class="card__actions">
            <a class="btn btn--sm" href="/outbox?campaign=<?= (int) $campaign['id'] ?>&amp;days=0">Every message</a>
          </div>
        </div>
        <div class="card__body">
          <?php // Read live from email_messages, not the rollup counters, which
                // are only recomputed when the send finishes. ?>
          <div class="stats">
            <div class="stat">
              <div class="stat__label">Sent</div>
              <div class="stat__value"><?= number_format((int) $funnel['sent']) ?></div>
            </div>
            <div class="stat">
              <div class="stat__label">Arrived</div>
              <div class="stat__value"><?= $funnel['delivery_rate'] ?>%</div>
              <div class="stat__meta"><?= number_format((int) $funnel['delivered']) ?> emails</div>
            </div>
            <div class="stat stat--accent">
              <div class="stat__label">Clicked something</div>
              <div class="stat__value"><?= $funnel['click_rate'] ?>%</div>
              <div class="stat__meta"><?= number_format((int) $funnel['clicked']) ?> people</div>
            </div>
            <div class="stat">
              <div class="stat__label">Opened</div>
              <div class="stat__value muted"><?= $funnel['open_rate'] ?>%</div>
              <div class="stat__meta">rough guide only</div>
            </div>
            <div class="stat">
              <div class="stat__label">Address did not exist</div>
              <div class="stat__value"><?= number_format((int) $funnel['bounced']) ?></div>
            </div>
            <div class="stat">
              <div class="stat__label">Marked as spam</div>
              <div class="stat__value"><?= number_format((int) $funnel['complained']) ?></div>
            </div>
            <div class="stat">
              <div class="stat__label">Unsubscribed</div>
              <div class="stat__value"><?= number_format((int) $funnel['unsubscribed']) ?></div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($aiAvailable): ?>
        <div class="card">
          <div class="card__head">
            <h2>What this tells you</h2>
            <?php if ($review !== null): ?>
              <div class="card__actions"><span class="badge">Written by AI</span></div>
            <?php endif; ?>
          </div>
          <div class="card__body">
            <div id="campaignReview" data-review-url="/campaigns/<?= (int) $campaign['id'] ?>/ai/review">
              <?php if ($review === null): ?>
                <p class="small muted mt-0">
                  Want it in plain English? We can read the numbers above and tell you what stands
                  out and what to try next time.
                </p>
                <button class="btn" type="button" data-review-go>Explain this campaign</button>
              <?php else: ?>
                <p class="mt-0"><strong><?= e((string) $review['title']) ?></strong></p>
                <p class="small" style="white-space:pre-line"><?= e((string) ($review['body'] ?? '')) ?></p>
                <p class="tiny muted mb-0">
                  Written by AI from the numbers above on
                  <?= e(substr((string) $review['created_at'], 0, 10)) ?>. The figures are measured;
                  the interpretation is a suggestion. Check anything you plan to repeat out loud.
                </p>
                <button class="btn btn--sm mt-1" type="button" data-review-go>Have another look</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($report['links'] !== []): ?>
        <div class="card">
          <div class="card__head"><h2>What people pressed</h2></div>
          <div class="card__body card__body--tight">
            <p class="small muted" style="padding:12px 14px 0;margin:0">
              The most useful thing on this page. If nobody pressed your main button and everybody
              pressed the phone number, that tells you what to change next time.
            </p>
            <div class="table-wrap">
              <table class="data">
                <thead><tr><th>Link</th><th class="num">People</th><th class="num">Share</th></tr></thead>
                <tbody>
                  <?php foreach ($report['links'] as $link): ?>
                    <tr>
                      <td>
                        <strong><?= e((string) $link['label']) ?></strong>
                        <div class="tiny muted" style="word-break:break-all"><?= e((string) $link['url']) ?></div>
                      </td>
                      <td class="num"><strong><?= number_format((int) $link['people']) ?></strong></td>
                      <td class="num muted"><?= $link['share'] ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2><?= $delivery === null ? 'Audience' : 'Who this went to' ?></h2></div>
      <div class="card__body">
        <p class="small mt-0"><strong><?= e($audience['description']) ?></strong></p>

        <?php if ($delivery !== null): ?>
          <p class="tiny muted mt-0">
            This is the list we saved the moment this campaign started sending — the real answer to
            "who got it", not who would match your smart list today.
          </p>
        <?php endif; ?>

        <div class="stat" style="border:0;box-shadow:none;padding:0">
          <div class="stat__label"><?= $delivery === null ? 'Will get this' : 'Could be emailed at the time' ?></div>
          <div class="stat__value"><?= number_format($audience['eligible']) ?></div>
          <div class="stat__meta">of <?= number_format($audience['total']) ?> matching contacts</div>
        </div>

        <?php if ($audience['total'] > $audience['eligible']): ?>
          <hr class="sep">
          <p class="tiny muted mt-0">Who we left out, and why:</p>
          <?php if ($audience['suppressed'] > 0): ?>
            <div class="flex-between small"><span>On your do-not-email list</span><strong><?= number_format($audience['suppressed']) ?></strong></div>
          <?php endif; ?>
          <?php if ($audience['no_consent'] > 0): ?>
            <div class="flex-between small"><span>Never agreed to hear from you</span><strong><?= number_format($audience['no_consent']) ?></strong></div>
          <?php endif; ?>
          <?php if ($audience['invalid'] > 0): ?>
            <div class="flex-between small"><span>Address is not valid</span><strong><?= number_format($audience['invalid']) ?></strong></div>
          <?php endif; ?>
          <?php if ($audience['blocked'] > 0): ?>
            <div class="flex-between small"><span>Blocked for another reason</span><strong><?= number_format($audience['blocked']) ?></strong></div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($delivery !== null): ?>
          <hr class="sep">
          <p class="tiny muted mt-0">How the send is going:</p>
          <?php foreach ([
              'sent'    => 'Sent',
              'queued'  => 'Being sent now',
              'pending' => 'Still to go',
              'skipped' => 'Skipped — something changed',
              'failed'  => 'Could not be delivered',
          ] as $key => $label): ?>
            <?php if (($delivery['send_status'][$key] ?? 0) > 0): ?>
              <div class="flex-between small">
                <span><?= e($label) ?></span>
                <strong><?= number_format((int) $delivery['send_status'][$key]) ?></strong>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($delivery['skip_reasons'] !== []): ?>
            <hr class="sep">
            <p class="tiny muted mt-0">Why we did not email some people:</p>
            <?php foreach ($delivery['skip_reasons'] as $code => $count): ?>
              <div class="flex-between tiny">
                <span title="<?= e($code) ?>"><?= e(App\Compliance\ReasonCode::describe((string) $code)) ?></span>
                <strong><?= number_format((int) $count) ?></strong>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Who it comes from</h2></div>
      <div class="card__body small">
        <div class="flex-between"><span>From</span><strong><?= e((string) ($campaign['from_name'] ?? '—')) ?></strong></div>
        <div class="tiny muted" style="word-break:break-all"><?= e((string) ($campaign['from_email'] ?? '')) ?></div>
        <?php if (!empty($campaign['reply_to'])): ?>
          <hr class="sep">
          <div class="flex-between"><span>Reply-to</span><span class="tiny"><?= e((string) $campaign['reply_to']) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!in_array($status, ['completed', 'cancelled'], true)): ?>
      <div class="card">
        <div class="card__head"><h2>Delete</h2></div>
        <div class="card__body">
          <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/delete"
                data-confirm="Delete this campaign for good?">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <button class="btn btn--danger btn--sm btn--block" type="submit">Delete campaign</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $__view->endSection(); ?>
