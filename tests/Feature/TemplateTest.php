<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\ValidationException;
use App\Mail\TemplateRenderer;
use App\Services\TemplateService;
use Tests\Support\TestCase;

/**
 * §19 — the block template builder.
 *
 * A block document is data written by a person in a browser, and later by the AI
 * campaign studio. The renderer treats it as data: unknown blocks are refused,
 * every value is escaped for where it lands, and the one setting that accepts
 * markup goes through an allowlist.
 */
final class TemplateTest extends TestCase
{
    public function testBlocksRenderToTableBasedEmailHtml(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $html = $this->renderer()->renderHtml([
            ['type' => 'heading', 'settings' => ['text' => 'Your service is due']],
            ['type' => 'text',    'settings' => ['html' => '<p>Hello there.</p>']],
            ['type' => 'button',  'settings' => ['text' => 'Book now', 'href' => 'https://example.com/book']],
        ]);

        // Email HTML is tables with inline styles: Outlook renders with Word's
        // engine and Gmail strips <style>.
        $this->assertContainsString('<table', $html);
        $this->assertContainsString('role="presentation"', $html);
        $this->assertContainsString('Your service is due', $html);
        $this->assertContainsString('https://example.com/book', $html);
        $this->assertNotContainsString('display:flex', $html, 'No flexbox in email');
        $this->assertContainsString('width="600"', $html, 'Fixed 600px content width');
    }

    public function testAnUnknownBlockTypeIsDroppedByTheRenderer(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $html = $this->renderer()->renderHtml([
            ['type' => 'heading',       'settings' => ['text' => 'Real block']],
            ['type' => 'script_block',  'settings' => ['html' => '<script>alert(1)</script>']],
        ]);

        $this->assertContainsString('Real block', $html);
        $this->assertNotContainsString('alert(1)', $html, 'An unknown block renders nothing at all');
    }

    public function testAnUnknownBlockTypeIsRefusedOnSaveRatherThanSilentlyLost(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $templates = $this->service();

        // Losing a section quietly on save is worse than refusing to save.
        $exception = $this->assertThrows(
            ValidationException::class,
            static fn () => $templates->create('Broken', [
                ['type' => 'heading',  'settings' => ['text' => 'Fine']],
                ['type' => 'not_real', 'settings' => []],
            ])
        );

        $this->assertContainsString('unknown type', implode(' ', $exception->firstErrors()));
    }

    public function testTextContentIsEscapedAgainstInjection(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $html = $this->renderer()->renderHtml([
            ['type' => 'heading', 'settings' => ['text' => '<script>alert(1)</script>']],
            ['type' => 'coupon',  'settings' => ['code' => '"><script>alert(2)</script>']],
        ]);

        $this->assertNotContainsString('<script>', $html);
        $this->assertContainsString('&lt;script&gt;', $html);
    }

    public function testRichTextKeepsFormattingButStripsEverythingElse(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $renderer = $this->renderer();

        $clean = $renderer->sanitiseRichText(
            '<p>Keep <strong>this</strong> and <a href="https://example.com">this link</a>.</p>'
            . '<script>alert(1)</script>'
            . '<iframe src="https://evil.test"></iframe>'
            . '<p onclick="steal()">Handler removed</p>'
            . '<p style="background:url(javascript:alert(3))">Style removed</p>'
        );

        $this->assertContainsString('<strong>this</strong>', $clean, 'Formatting survives');
        $this->assertContainsString('https://example.com', $clean, 'Links survive');
        $this->assertNotContainsString('<script', $clean);
        $this->assertNotContainsString('<iframe', $clean);
        $this->assertNotContainsString('onclick', $clean, 'Event handlers are removed');
        $this->assertNotContainsString('javascript:', $clean);
        $this->assertNotContainsString('style=', $clean, 'Inline styles are removed from author markup');
    }

    public function testDangerousUrlSchemesAreRefused(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $renderer = $this->renderer();

        foreach ([
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
            '//evil.test/path',
            '/relative/path',
        ] as $url) {
            $this->assertSame('', $renderer->url($url), 'Refuses: ' . $url);
        }

        foreach ([
            'https://example.com/book',
            'http://example.com',
            'mailto:hello@example.com',
            'tel:+61855501000',
            '{{unsubscribe_url}}',
        ] as $url) {
            $this->assertSame($url, $renderer->url($url), 'Allows: ' . $url);
        }
    }

    public function testAButtonWithADangerousHrefRendersNothing(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $html = $this->renderer()->renderHtml([
            ['type' => 'button', 'settings' => ['text' => 'Click me', 'href' => 'javascript:alert(1)']],
        ]);

        $this->assertNotContainsString('javascript:', $html);
        $this->assertNotContainsString('Click me', $html, 'A button with no usable link is not rendered at all');
    }

    public function testEnumAndColourSettingsFallBackRatherThanPassingThrough(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $html = $this->renderer()->renderHtml([
            ['type' => 'heading', 'settings' => [
                'text'   => 'Heading',
                'level'  => 'h9; background:url(x)',
                'align'  => 'justify"><script>alert(1)</script>',
                'colour' => 'red; background:url(javascript:alert(1))',
            ]],
        ]);

        $this->assertNotContainsString('script', $html);
        $this->assertNotContainsString('javascript:', $html);
        $this->assertContainsString('#111827', $html, 'The colour falls back to its declared default');
        $this->assertContainsString('text-align:left', $html, 'And so does the alignment');
    }

    public function testThePlainTextAlternativeIsUsable(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $text = $this->renderer()->renderText([
            ['type' => 'heading', 'settings' => ['text' => 'Your service is due']],
            ['type' => 'text',    'settings' => ['html' => '<p>Hello {{first_name}}.</p><ul><li>Point one</li></ul>']],
            ['type' => 'button',  'settings' => ['text' => 'Book now', 'href' => 'https://example.com/book']],
        ]);

        $this->assertContainsString('YOUR SERVICE IS DUE', $text);
        $this->assertContainsString('{{first_name}}', $text, 'Merge fields survive into the text part');
        $this->assertContainsString('- Point one', $text, 'Lists become readable');
        // A text part with a bare "Book now" and no link is useless to whoever
        // reads it, and filters notice.
        $this->assertContainsString('Book now: https://example.com/book', $text);
        $this->assertNotContainsString('<', $text, 'No markup leaks into the text part');
    }

    public function testSavingATemplateCachesBothRenderings(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->create('Service reminder', [
            ['type' => 'heading', 'settings' => ['text' => 'Time for a service']],
            ['type' => 'text',    'settings' => ['html' => '<p>Hello {{first_name}}.</p>']],
        ], ['category' => 'service']);

        $row = $this->connection->selectOne('SELECT * FROM templates WHERE id = ?', [$id]);

        $this->assertContainsString('Time for a service', (string) $row['html_cache']);
        $this->assertContainsString('TIME FOR A SERVICE', (string) $row['text_cache']);

        // The blocks stay authoritative, so the template can be re-rendered later
        // when the renderer improves.
        $blocks = json_decode((string) $row['blocks'], true);
        $this->assertCount(2, $blocks);
        $this->assertSame('heading', $blocks[0]['type']);
    }

    public function testThePreviewFillsMergeFieldsWithRealContext(): void
    {
        $org = $this->createOrganisation(['name' => 'Perth Plumbing Co']);
        $this->bindTenant($org['organisation_id']);

        $html = $this->service()->preview([
            ['type' => 'text',   'settings' => ['html' => '<p>Hello {{first_name}}, from {{business_name}}.</p>']],
            ['type' => 'footer', 'settings' => []],
        ]);

        // Shipping an email that greets everyone as "{{first_name}}" is a classic;
        // the preview shows what a recipient actually sees.
        $this->assertNotContainsString('{{first_name}}', $html);
        $this->assertNotContainsString('{{business_name}}', $html);
        $this->assertContainsString('Alex', $html, 'A sample contact fills the gaps');
        $this->assertContainsString('Perth Plumbing Co', $html);
    }

    public function testStarterLayoutsExistForEachCampaignShape(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $service = $this->service();

        foreach (['promotion', 'reactivation', 'review_request', 'newsletter', 'blank'] as $category) {
            $blocks = $service->starterBlocks($category);

            $this->assertTrue(count($blocks) >= 3, $category . ' starts with a usable layout');

            // Every starter must render without error, or the "start from a
            // layout" button ships a broken template.
            $html = $this->renderer()->renderHtml($blocks);
            $this->assertContainsString('<table', $html);
        }
    }

    public function testAnEmptyTemplateIsRefused(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $service = $this->service();

        $this->assertThrows(ValidationException::class, static fn () => $service->create('Empty', []));
    }

    public function testATemplateInUseCannotBeDeleted(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->create('In use', [['type' => 'heading', 'settings' => []]]);

        $this->connection->table('campaigns')->insert([
            'organisation_id' => $org['organisation_id'],
            'uuid'            => uuid4(),
            'name'            => 'Uses the template',
            'template_id'     => $id,
            'status'          => 'draft',
            'created_at'      => $this->clock->nowString(),
            'updated_at'      => $this->clock->nowString(),
        ]);

        $service = $this->service();

        $this->assertThrows(
            ValidationException::class,
            static fn () => $service->delete($id),
            'Deleting a template out from under a campaign would break it'
        );
    }

    public function testTemplatesAreScopedToTheirOrganisation(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgA['organisation_id']);
        $id = $this->service()->create('Private', [['type' => 'heading', 'settings' => []]]);

        $this->bindTenant($orgB['organisation_id']);

        $service = $this->service();

        $this->assertCount(0, $service->all());
        $this->assertThrows(\App\Core\HttpException::class, static fn () => $service->find($id));
    }

    public function testTheFooterBlockCarriesTheComplianceTokens(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $html = $this->renderer()->renderHtml([['type' => 'footer', 'settings' => []]]);

        // The footer block lets an author place and style the identity block; it
        // does not let them omit it, and EmailRenderer adds one anyway if missing.
        $this->assertContainsString('{{business_name}}', $html);
        $this->assertContainsString('{{business_address}}', $html);
        $this->assertContainsString('{{unsubscribe_url}}', $html);
    }

    public function testABlockDocumentCannotGrowUnbounded(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $blocks = array_fill(0, 200, ['type' => 'divider', 'settings' => []]);

        $service = $this->service();

        $this->assertThrows(ValidationException::class, static fn () => $service->create('Huge', $blocks));
    }

    private function renderer(): TemplateRenderer
    {
        return $this->container->make(TemplateRenderer::class);
    }

    private function service(): TemplateService
    {
        return $this->container->make(TemplateService::class);
    }
}
