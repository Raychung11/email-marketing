<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\ValidationException;
use App\Mail\EmailRenderer;
use App\Mail\TemplateRenderer;
use App\Repositories\TemplateRepository;
use App\Support\TenantContext;

/**
 * Reusable email templates.
 *
 * The block document is the source of truth; the rendered HTML and text stored
 * alongside it are a cache, rebuilt on every save. Keeping the blocks
 * authoritative is what lets a template be re-rendered later — when the brand
 * colour changes, or when the renderer itself improves — without anyone editing
 * markup by hand.
 */
final class TemplateService
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly EmailRenderer $emailRenderer,
        private readonly TransactionalMailer $mailer,
        private readonly Config $config,
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(?string $category = null): array
    {
        return $this->templates->all($category);
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        return $this->templates->findOrFailWithBlocks($id);
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<string,mixed>            $attributes
     */
    public function create(string $name, array $blocks, array $attributes = []): int
    {
        $blocks = $this->validateBlocks($blocks);

        $id = $this->templates->create($name, $blocks, array_merge($attributes, [
            'html_cache' => $this->renderer->renderHtml($blocks, $this->backgroundColour()),
            'text_cache' => $this->renderer->renderText($blocks),
        ]));

        $this->audit->log('template_created', 'template', $id, null, [
            'name'   => $name,
            'blocks' => count($blocks),
        ]);

        return $id;
    }

    /**
     * @param array<string,mixed>                 $attributes
     * @param array<int,array<string,mixed>>|null $blocks
     */
    public function update(int $id, array $attributes, ?array $blocks = null): void
    {
        $this->templates->findOrFail($id);

        if ($blocks !== null) {
            $blocks = $this->validateBlocks($blocks);

            $attributes['html_cache'] = $this->renderer->renderHtml($blocks, $this->backgroundColour());
            $attributes['text_cache'] = $this->renderer->renderText($blocks);
        }

        $this->templates->update($id, $attributes, $blocks);

        $this->audit->log('template_updated', 'template', $id, null, [
            'name'   => $attributes['name'] ?? null,
            'blocks' => $blocks === null ? null : count($blocks),
        ]);
    }

    public function duplicate(int $id): int
    {
        $template = $this->templates->findOrFailWithBlocks($id);

        return $this->create(
            (string) $template['name'] . ' (copy)',
            $template['blocks'],
            [
                'description' => $template['description'],
                'category'    => $template['category'],
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->templates->findOrFail($id);

        if ($this->templates->isInUse($id)) {
            throw new ValidationException([
                'template' => ['This template is used by a campaign. Delete or detach the campaign first.'],
            ]);
        }

        $this->templates->softDelete($id);
        $this->audit->log('template_deleted', 'template', $id);
    }

    /**
     * Render for the preview pane.
     *
     * Merge variables are filled with the organisation's real details and a
     * sample contact, so the preview shows what a recipient sees rather than a
     * page full of {{tokens}} — which is how people ship an email that greets
     * everyone as "{{first_name}}".
     *
     * @param array<int,array<string,mixed>> $blocks
     * @param array<string,mixed>|null       $contact
     */
    public function preview(array $blocks, ?array $contact = null): string
    {
        $html         = $this->renderer->renderHtml($this->validateBlocks($blocks), $this->backgroundColour());
        $organisation = $this->tenant->organisation();

        $contact ??= [
            'uuid'           => '00000000-0000-4000-8000-000000000000',
            'first_name'     => 'Alex',
            'last_name'      => 'Taylor',
            'email'          => 'alex.taylor@example.com',
            'company'        => 'Example Pty Ltd',
            'city'           => (string) ($organisation['address_city'] ?? 'Perth'),
            'country'        => (string) ($organisation['country'] ?? 'AU'),
            'customer_value' => 850,
            'total_revenue'  => 2400,
        ];

        $variables = $this->emailRenderer->variablesFor(
            $organisation,
            $contact,
            '#preview-unsubscribe',
            '#preview-preferences'
        );

        return $this->emailRenderer->substitute($html, $variables, true);
    }

    /**
     * Send a test message to a chosen address.
     *
     * Deliberately routed through the transactional path: a test is not a
     * marketing send, and it must not consume the daily marketing allowance or
     * require the recipient to have marketing consent.
     */
    public function sendTest(int $id, string $recipient): bool
    {
        if (!is_valid_email($recipient)) {
            throw new ValidationException(['recipient' => ['Enter a valid email address.']]);
        }

        $template     = $this->templates->findOrFailWithBlocks($id);
        $organisation = $this->tenant->organisation();

        $html = $this->preview($template['blocks']);
        $text = $this->renderer->renderText($template['blocks']);

        $sent = $this->mailer->sendToContact(
            $organisation,
            ['id' => null, 'email' => $recipient],
            '[Test] ' . (string) $template['name'],
            $html,
            $text
        );

        $this->audit->log('template_test_sent', 'template', $id, null, [
            'recipient' => \App\Support\Str::maskEmail($recipient),
            'accepted'  => $sent,
        ]);

        return $sent;
    }

    /**
     * Validate and normalise a block document.
     *
     * Unknown block types are rejected rather than silently dropped: a template
     * that quietly loses a section on save is worse than one that refuses to.
     *
     * @param array<int,mixed> $blocks
     * @return array<int,array{type:string,settings:array<string,mixed>}>
     */
    public function validateBlocks(array $blocks): array
    {
        /** @var array<string,mixed> $registry */
        $registry = $this->config->get('blocks.blocks', []);
        $max      = (int) $this->config->get('blocks.max_blocks', 60);

        if (count($blocks) > $max) {
            throw new ValidationException([
                'blocks' => ['A template may contain at most ' . $max . ' blocks.'],
            ]);
        }

        $normalised = [];

        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');

            if (!isset($registry[$type])) {
                throw new ValidationException([
                    'blocks' => ['Block ' . ($index + 1) . ' has an unknown type "' . $type . '".'],
                ]);
            }

            $normalised[] = [
                'type'     => $type,
                'settings' => is_array($block['settings'] ?? null) ? $block['settings'] : [],
            ];
        }

        if ($normalised === []) {
            throw new ValidationException(['blocks' => ['A template needs at least one block.']]);
        }

        return $normalised;
    }

    /** @return array<int,array{type:string,settings:array<string,mixed>}> */
    public function starterBlocks(string $category): array
    {
        return $this->renderer->starterBlocks($category);
    }

    /** @return array<string,mixed> */
    public function registry(): array
    {
        return [
            'blocks'       => $this->config->get('blocks.blocks', []),
            'merge_fields' => $this->config->get('blocks.merge_fields', []),
        ];
    }

    private function backgroundColour(): string
    {
        // Not the brand colour: a saturated page background behind an email makes
        // it look like a banner ad. The brand colour belongs on buttons.
        return '#f4f5f7';
    }
}
