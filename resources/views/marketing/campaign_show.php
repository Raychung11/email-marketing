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
          <strong>This campaign cannot be sent yet</strong>
          <ul>
            <?php foreach ($blocking as $finding): ?>
              <li><?= e((string) $finding['message']) ?> <span class="mono tiny">(<?= e((string) $finding['code']) ?>)</span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php elseif ($validated): ?>
        <div class="alert alert--success" style="margin-bottom:12px">
          <strong>Validation passed</strong>
          Sender, domain, content, unsubscribe, postal address and audience all check out.
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
          <button class="btn" type="submit">Run validation</button>
        </form>
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/submit">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <button class="btn btn--primary" type="submit">Send for review</button>
        </form>
      </div>

    <?php elseif ($status === 'pending_review'): ?>
      <?php if ($isAuthor): ?>
        <div class="alert alert--info" style="margin-bottom:12px">
          <strong>Waiting for a colleague to review this</strong>
          You wrote this campaign, so you cannot approve it yourself. That separation is the whole
          point of the review step — ask someone with approval permission to take a look.
        </div>
      <?php elseif ($canApprove): ?>
        <div class="alert alert--info" style="margin-bottom:12px">
          <strong>This campaign is waiting for your review</strong>
          Check the audience, the subject line and the content below before approving.
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
        <p class="mb-0 muted">Awaiting review by someone with approval permission.</p>
      <?php endif; ?>

    <?php elseif ($status === 'approved'): ?>
      <div class="alert alert--success" style="margin-bottom:12px">
        <strong>Approved<?= !empty($campaign['approved_at']) ? ' on ' . e(substr((string) $campaign['approved_at'], 0, 16)) . ' UTC' : '' ?></strong>
        Schedule it, or send now. Editing it from here would invalidate the approval.
      </div>
      <div class="flex wrap">
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/schedule" class="flex">
          <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
          <input type="datetime-local" name="scheduled_at" required>
          <button class="btn" type="submit">Schedule</button>
          <span class="tiny muted">times in <?= e($timezone) ?></span>
        </form>
        <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/send"
              data-confirm="Send this campaign to <?= number_format($audience['eligible']) ?> recipients?">
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
        <strong>Sending</strong>
        <?= number_format((int) $campaign['sent_count']) ?> of
        <?= number_format((int) $campaign['eligible_count']) ?> handed to the provider so far.
      </div>
      <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/pause"
            data-confirm="Pause this send? Messages already with the provider cannot be recalled.">
        <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
        <button class="btn btn--danger" type="submit">Pause sending</button>
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
            <h3>No content yet</h3>
            <p>Apply a template or write the body.</p>
            <a class="btn btn--primary" href="/campaigns/<?= (int) $campaign['id'] ?>/edit">Add content</a>
          </div>
        <?php else: ?>
          <iframe src="/campaigns/<?= (int) $campaign['id'] ?>/preview" title="Campaign preview" sandbox=""
                  style="width:100%;height:600px;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>
        <?php endif; ?>
      </div>
    </div>

    <?php if (in_array($status, ['sending', 'completed', 'paused'], true)): ?>
      <div class="card">
        <div class="card__head"><h2>Results</h2></div>
        <div class="card__body">
          <div class="stats">
            <div class="stat">
              <div class="stat__label">Sent</div>
              <div class="stat__value"><?= number_format((int) $campaign['sent_count']) ?></div>
            </div>
            <div class="stat">
              <div class="stat__label">Delivered</div>
              <div class="stat__value">
                <?= (int) $campaign['sent_count'] > 0
                    ? round((int) $campaign['delivered_count'] / (int) $campaign['sent_count'] * 100, 1) . '%'
                    : '—' ?>
              </div>
            </div>
            <div class="stat stat--accent">
              <div class="stat__label">Click rate</div>
              <div class="stat__value">
                <?= (int) $campaign['delivered_count'] > 0
                    ? round((int) $campaign['unique_click_count'] / (int) $campaign['delivered_count'] * 100, 2) . '%'
                    : '—' ?>
              </div>
              <div class="stat__meta"><?= number_format((int) $campaign['unique_click_count']) ?> people</div>
            </div>
            <div class="stat">
              <div class="stat__label">Open rate</div>
              <div class="stat__value muted">
                <?= (int) $campaign['delivered_count'] > 0
                    ? round((int) $campaign['unique_open_count'] / (int) $campaign['delivered_count'] * 100, 1) . '%'
                    : '—' ?>
              </div>
              <div class="stat__meta">indicative only</div>
            </div>
            <div class="stat">
              <div class="stat__label">Bounced</div>
              <div class="stat__value"><?= number_format((int) $campaign['bounce_count']) ?></div>
            </div>
            <div class="stat">
              <div class="stat__label">Complaints</div>
              <div class="stat__value"><?= number_format((int) $campaign['complaint_count']) ?></div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col col--narrow">
    <div class="card">
      <div class="card__head"><h2>Audience</h2></div>
      <div class="card__body">
        <p class="small mt-0"><strong><?= e($audience['description']) ?></strong></p>

        <div class="stat" style="border:0;box-shadow:none;padding:0">
          <div class="stat__label">Will receive this</div>
          <div class="stat__value"><?= number_format($audience['eligible']) ?></div>
          <div class="stat__meta">of <?= number_format($audience['total']) ?> matching contacts</div>
        </div>

        <?php if ($audience['total'] > $audience['eligible']): ?>
          <hr class="sep">
          <p class="tiny muted mt-0">Excluded, and why:</p>
          <?php if ($audience['suppressed'] > 0): ?>
            <div class="flex-between small"><span>Suppressed</span><strong><?= number_format($audience['suppressed']) ?></strong></div>
          <?php endif; ?>
          <?php if ($audience['no_consent'] > 0): ?>
            <div class="flex-between small"><span>No consent basis</span><strong><?= number_format($audience['no_consent']) ?></strong></div>
          <?php endif; ?>
          <?php if ($audience['invalid'] > 0): ?>
            <div class="flex-between small"><span>Invalid address</span><strong><?= number_format($audience['invalid']) ?></strong></div>
          <?php endif; ?>
          <?php if ($audience['blocked'] > 0): ?>
            <div class="flex-between small"><span>Blocked</span><strong><?= number_format($audience['blocked']) ?></strong></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h2>Sender</h2></div>
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
        <div class="card__head"><h2>Danger zone</h2></div>
        <div class="card__body">
          <form method="post" action="/campaigns/<?= (int) $campaign['id'] ?>/delete"
                data-confirm="Delete this campaign?">
            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
            <button class="btn btn--danger btn--sm btn--block" type="submit">Delete campaign</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php $__view->endSection(); ?>
