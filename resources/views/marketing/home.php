<?php
$__view->extend('layouts.marketing');
$title = ($appName ?? 'AI Growth Hub') . ' — keep your customers coming back';
$metaDescription = 'Email marketing, customer records and automatic follow-ups for small businesses. '
    . 'Consent, unsubscribes and deliverability handled properly, in plain English.';
?>
<?php $__view->startSection('content'); ?>

<section class="m-hero">
  <div class="m-wrap m-hero__inner">
    <div>
      <span class="m-eyebrow">For small businesses in the US &amp; Australia</span>

      <h1>Keep your customers<br>coming back.</h1>

      <p class="m-lede">
        Your customer list, your email campaigns and your follow-ups in one place —
        set up in an afternoon, in words you already use. Built for the people who
        run the business, not for a marketing department.
      </p>

      <div class="m-cta-row">
        <a class="m-btn m-btn--primary m-btn--lg" href="/register">Start free</a>
        <a class="m-btn m-btn--ghost m-btn--lg" href="#limits">See what it won't do</a>
      </div>

      <p class="m-cta-note">No card needed. Nothing sends until you say so.</p>
    </div>

    <figure class="m-figure">
      <div class="m-figure__title">Before every send, you see exactly who gets it</div>
      <p class="m-figure__sub">And who doesn't, and why.</p>

      <div class="m-bar" role="img" aria-label="Illustration of an audience split into those who will receive a campaign and those who are excluded">
        <span style="width:78%;background:var(--success)"></span>
        <span style="width:9%;background:var(--warning)"></span>
        <span style="width:8%;background:var(--danger)"></span>
        <span style="width:5%;background:var(--line-strong)"></span>
      </div>

      <div class="m-rows">
        <div class="m-row">
          <span class="m-dot" style="background:var(--success)"></span>
          <span class="m-row__label">Will receive it</span>
          <span class="m-row__value">1,842</span>
        </div>
        <div class="m-row">
          <span class="m-dot" style="background:var(--warning)"></span>
          <span class="m-row__label">No permission recorded</span>
          <span class="m-row__value">213</span>
        </div>
        <div class="m-row">
          <span class="m-dot" style="background:var(--danger)"></span>
          <span class="m-row__label">Unsubscribed or complained</span>
          <span class="m-row__value">184</span>
        </div>
        <div class="m-row">
          <span class="m-dot" style="background:var(--line-strong)"></span>
          <span class="m-row__label">Address doesn't exist</span>
          <span class="m-row__value">119</span>
        </div>
      </div>

      <figcaption class="m-figure__foot">
        Illustration. Every campaign gets this breakdown from your own contacts before it goes anywhere.
      </figcaption>
    </figure>
  </div>
</section>

<section class="m-section m-section--tint">
  <div class="m-wrap">
    <div class="m-section__head">
      <h2>Made for businesses with customers, not campaigns</h2>
      <p>
        If you have a list of people who have bought from you, enquired, or booked
        once and never came back, this is for you.
      </p>
    </div>

    <div class="m-trades">
      <?php foreach ([
          'Plumbing', 'Electrical', 'HVAC', 'Dental', 'Healthcare', 'Renovation',
          'Interior design', 'Property services', 'Automotive', 'Tourism',
          'Hospitality', 'Restaurants & cafés', 'Professional services',
          'Retail', 'E-commerce', 'Agencies',
      ] as $trade): ?>
        <span class="m-trade"><?= e($trade) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="m-section" id="what">
  <div class="m-wrap">
    <div class="m-section__head">
      <h2>What it does</h2>
      <p>Everything you need to stay in touch with the people who already know you.</p>
    </div>

    <div class="m-grid m-grid--3">
      <div class="m-card">
        <h3>One tidy customer list</h3>
        <p>
          Contacts, companies, tags and notes in one place. Import a spreadsheet and
          it tells you what it found, what it fixed and what it skipped — before
          anything is saved.
        </p>
      </div>
      <div class="m-card">
        <h3>Emails that look right everywhere</h3>
        <p>
          Build with blocks, not code. Every message is rendered for the email apps
          people actually use, including the old ones that break modern layouts.
        </p>
      </div>
      <div class="m-card">
        <h3>A writer that knows your trade</h3>
        <p>
          Describe what you want to say and get a draft back in your industry's
          language. You edit it, you approve it, you send it.
        </p>
      </div>
      <div class="m-card">
        <h3>Follow-ups that happen on their own</h3>
        <p>
          A thank-you after a job. A reminder at six months. A nudge when someone
          clicks but doesn't call. Set it once and it keeps running.
        </p>
      </div>
      <div class="m-card">
        <h3>Lists that build themselves</h3>
        <p>
          "Customers in Melbourne who bought last year and haven't opened anything
          since." Type that, get the list. It updates itself as people change.
        </p>
      </div>
      <div class="m-card">
        <h3>Which emails actually made money</h3>
        <p>
          Track enquiries and sales back to the campaign that caused them. Every
          figure shows how many people it was measured across.
        </p>
      </div>
    </div>
  </div>
</section>

<section class="m-section m-section--tint" id="limits">
  <div class="m-wrap">
    <div class="m-section__head">
      <h2>What it won't do</h2>
      <p>
        Most of what separates this from cheaper tools is what it refuses. Each of
        these is enforced in the software, not written in a help page.
      </p>
    </div>

    <div>
      <?php foreach ([
          [
              'It will not send to someone who unsubscribed.',
              'Not even if you re-import them from a spreadsheet. An unsubscribe outranks
               every list, every segment and every import, permanently.',
          ],
          [
              'It will not let you import a purchased list.',
              'You declare where contacts came from. Say "purchased" or "scraped" and the
               import stops — no rows written. That declaration is kept as your record.',
          ],
          [
              'The AI will not send anything.',
              'It writes drafts, suggests subject lines and explains your results. It
               cannot send a campaign, change permissions or delete anything.',
          ],
          [
              'It will not invent a number.',
              'Every figure in an AI summary is checked against your real data. Anything
               that does not match is removed rather than softened.',
          ],
          [
              'It will not pre-tick a consent box.',
              'Not as a default, not as a setting. The exact wording someone agreed to is
               saved with their signup, so you can prove it years later.',
          ],
          [
              'It will not call a coin-flip a winner.',
              'An A/B test that is too close to call says so, instead of dressing up noise
               as a result.',
          ],
          [
              'It will not treat an email address as permission to text.',
              'Permission is recorded per channel. Email consent is email consent.',
          ],
      ] as [$heading, $body]): ?>
        <div class="m-refusal">
          <span class="m-refusal__mark" aria-hidden="true">✓</span>
          <div>
            <h3><?= e($heading) ?></h3>
            <p><?= e($body) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="m-section">
  <div class="m-wrap">
    <div class="m-section__head">
      <h2>Getting started</h2>
      <p>An afternoon, most of which is waiting for your domain settings to update.</p>
    </div>

    <div class="m-steps">
      <div class="m-step">
        <h3>Bring your customers in</h3>
        <p>
          Upload a spreadsheet or add people as you go. Download a template if you
          are not sure what the file should look like.
        </p>
      </div>
      <div class="m-step">
        <h3>Prove the email is from you</h3>
        <p>
          The setup gives you the exact records to paste into your domain settings.
          It checks them for you and tells you plainly when they are right.
        </p>
      </div>
      <div class="m-step">
        <h3>Write it, check it, send it</h3>
        <p>
          Draft the message, see exactly who will receive it, approve it. It goes out
          at a pace mailbox providers are comfortable with.
        </p>
      </div>
    </div>
  </div>
</section>

<section class="m-section m-section--tint" id="pricing">
  <div class="m-wrap">
    <div class="m-section__head" style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px;flex-wrap:wrap;max-width:none">
      <div style="max-width:42em">
        <h2>Pricing</h2>
        <p>Per month. Change or leave whenever you like.</p>
      </div>

      <div class="m-currency" role="group" aria-label="Currency">
        <?php foreach ($currencies as $code => $symbol): ?>
          <button type="button" data-currency="<?= e($code) ?>"
                  aria-pressed="<?= $code === 'USD' ? 'true' : 'false' ?>"><?= e($code) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="m-plans">
      <?php foreach ($plans as $plan): ?>
        <div class="m-plan <?= $plan['highlight'] ? 'm-plan--featured' : '' ?>">
          <?php if ($plan['highlight']): ?>
            <span class="m-plan__tag">Most chosen</span>
          <?php endif; ?>

          <h3><?= e($plan['name']) ?></h3>
          <p class="m-plan__desc"><?= e($plan['description']) ?></p>

          <div class="m-plan__price">
            <?php foreach ($plan['prices'] as $code => $amount): ?>
              <span class="m-plan__amount" data-price="<?= e($code) ?>"
                    <?= $code === 'USD' ? '' : 'hidden' ?>><?= e($currencies[$code] ?? '') ?><?= e($amount) ?></span>
            <?php endforeach; ?>
            <span class="m-plan__per">/ month</span>
          </div>

          <ul class="m-plan__limits">
            <li><?= number_format((int) ($plan['limits']['contacts'] ?? 0)) ?> contacts</li>
            <li><?= number_format((int) ($plan['limits']['emails_per_month'] ?? 0)) ?> emails a month</li>
            <li><?= number_format((int) ($plan['limits']['users'] ?? 0)) ?> team members</li>
            <?php if ((int) ($plan['limits']['automations'] ?? 0) > 0): ?>
              <li><?= number_format((int) $plan['limits']['automations']) ?> automatic follow-ups</li>
            <?php endif; ?>
            <li><?= number_format((int) ($plan['limits']['sending_domains'] ?? 0)) ?> sending <?= ((int) ($plan['limits']['sending_domains'] ?? 0)) === 1 ? 'domain' : 'domains' ?></li>
          </ul>

          <a class="m-btn <?= $plan['highlight'] ? 'm-btn--primary' : 'm-btn--ghost' ?>" href="/register">Start free</a>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="m-pricing-note">
      You can create an account and set everything up without entering card details.
      Sending costs are included in the plan; you do not pay a separate email provider.
    </p>
  </div>
</section>

<section class="m-section">
  <div class="m-wrap">
    <div class="m-section__head">
      <h2>Questions worth asking</h2>
    </div>

    <div class="m-faq">
      <?php foreach ([
          [
              'Do I need to be technical?',
              'For one step, briefly. To send email as your own business you have to add a
               few records to your domain settings — the setup gives you exactly what to
               paste and checks it for you. Everything after that is filling in forms. If
               your domain is with a host like Hostinger, GoDaddy or Cloudflare, it is
               copy and paste.',
          ],
          [
              'Will my emails actually reach the inbox?',
              'Nobody can promise that, and you should not trust anyone who does. What this
               does is everything that makes it likely: proves the mail is genuinely from
               you, stops sending to addresses that bounce, removes people who complain,
               and paces sends so you do not look like a spammer. The rest is whether
               people want your email.',
          ],
          [
              'Can I bring my existing customer list?',
              'Yes — upload a spreadsheet. You will be asked where those contacts came
               from, and that answer decides what you can send them. Customers you have
               served, people who filled in your form, guests who signed up at an event:
               all fine. Lists you bought: refused, and not negotiable.',
          ],
          [
              'What happens if someone unsubscribes?',
              'They stop receiving marketing immediately, everywhere, for good. Re-importing
               them does not undo it. That is deliberate — the single fastest way to
               destroy your ability to reach anyone is to email people who asked you to
               stop.',
          ],
          [
              'How much does the AI actually do?',
              'It drafts and it explains. It writes a first version of a campaign in your
               trade\'s language, suggests subject lines, turns a plain-English description
               into a list, and tells you what a campaign\'s results mean. It cannot send,
               cannot change anyone\'s permissions, and cannot delete anything.',
          ],
          [
              'Which countries\' rules does it follow?',
              'United States and Australia are built in, with their real differences
               handled — Australia requires permission before you send, the US requires a
               postal address in every message. Anywhere else falls back to the stricter
               of the two.',
          ],
          [
              'Can I get my data out?',
              'Yes. Your contacts, their permission history and your results are yours,
               exportable as spreadsheets whenever you want, including on the way out.',
          ],
      ] as [$question, $answer]): ?>
        <details>
          <summary><?= e($question) ?></summary>
          <p><?= e($answer) ?></p>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="m-final">
  <div class="m-wrap">
    <h2>Your quietest customers are your cheapest sales</h2>
    <p>
      Set it up this afternoon. Nothing leaves your account until you have seen
      exactly who it is going to.
    </p>
    <a class="m-btn m-btn--primary m-btn--lg" href="/register">Start free</a>
  </div>
</section>

<?php $__view->endSection(); ?>
