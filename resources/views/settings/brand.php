<?php $__view->extend('layouts.app'); $title = 'Brand profile'; $org = $organisation; ?>
<?php $__view->startSection('content'); ?>

<div class="page-head">
  <div>
    <h1>Brand profile</h1>
    <p>Context the AI uses when it drafts campaigns. The more specific this is, the less generic the output.</p>
  </div>
</div>

<div class="alert alert--info">
  <strong>This is the AI's factual grounding</strong>
  The AI is only allowed to draw on what you put here and in the content library. It is instructed
  never to invent products, prices, claims or testimonials — so anything it should be able to say
  needs to be written down here.
</div>

<form method="post" action="/settings/brand">
  <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">

  <div class="card">
    <div class="card__body">
      <div class="field">
        <label for="primary_colour">Primary brand colour</label>
        <input id="primary_colour" type="text" name="primary_colour" maxlength="7" pattern="^#[0-9a-fA-F]{6}$"
               placeholder="#1d4ed8" value="<?= e((string) ($org['primary_colour'] ?? '')) ?>">
      </div>

      <div class="field">
        <label for="brand_voice">Brand voice</label>
        <textarea id="brand_voice" name="brand_voice" maxlength="2000"
                  placeholder="e.g. Straightforward and practical. No jargon. We are the local plumber people call in an emergency, so warmth and speed matter more than polish."><?= e((string) ($org['brand_voice'] ?? '')) ?></textarea>
      </div>

      <div class="field">
        <label for="target_customer">Target customer</label>
        <textarea id="target_customer" name="target_customer" maxlength="2000"
                  placeholder="e.g. Homeowners in the Perth metro area, 35-65, who own rather than rent."><?= e((string) ($org['target_customer'] ?? '')) ?></textarea>
      </div>

      <div class="field">
        <label for="services">Services</label>
        <textarea id="services" name="services" maxlength="4000"
                  placeholder="One per line: Blocked drains, Hot water systems, Leak detection, Gas fitting…"><?= e((string) ($org['services'] ?? '')) ?></textarea>
      </div>

      <div class="field">
        <label for="products">Products</label>
        <textarea id="products" name="products" maxlength="4000"><?= e((string) ($org['products'] ?? '')) ?></textarea>
      </div>

      <div class="field">
        <label for="unique_selling_proposition">What makes you different</label>
        <textarea id="unique_selling_proposition" name="unique_selling_proposition" maxlength="2000"
                  placeholder="e.g. Same-day callout, fixed pricing quoted before we start, 24-month workmanship guarantee."><?= e((string) ($org['unique_selling_proposition'] ?? '')) ?></textarea>
      </div>
    </div>
    <div class="card__foot"><button class="btn btn--primary" type="submit">Save brand profile</button></div>
  </div>
</form>

<?php $__view->endSection(); ?>
