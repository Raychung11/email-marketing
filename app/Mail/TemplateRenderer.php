<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;

/**
 * Renders a block document into email HTML and a plain-text alternative.
 *
 * Email HTML is its own dialect. Outlook renders with Word's engine, Gmail strips
 * <style>, and nothing supports flexbox reliably, so everything here is tables
 * with inline styles at a 600px content width. That constraint is why the block
 * set is small and each block owns its markup rather than composing CSS classes.
 *
 * THE SAFETY PROPERTY: a block document is data, and this renderer treats it as
 * data. Unknown block types are skipped, unknown settings are ignored, every
 * value is escaped for the context it lands in, URLs are scheme-checked, and the
 * one setting that accepts markup goes through an allowlist. A template author
 * cannot inject script, and neither can anything that writes a template — which
 * matters because the AI campaign studio will.
 */
final class TemplateRenderer
{
    /** Inline tags an author may use in a rich-text block. */
    private const ALLOWED_INLINE_TAGS = '<p><br><strong><b><em><i><u><a><ul><ol><li><span><h1><h2><h3><blockquote>';

    /** Schemes a link or image may use. No javascript:, no data:, no vbscript:. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private int $width;

    public function __construct(private readonly Config $config)
    {
        $this->width = (int) $this->config->get('blocks.content_width', 600);
    }

    /**
     * @param array<int,array{type?:string,settings?:array<string,mixed>}> $blocks
     */
    public function renderHtml(array $blocks, string $backgroundColour = '#f4f5f7'): string
    {
        $max      = (int) $this->config->get('blocks.max_blocks', 60);
        $rendered = [];

        foreach (array_slice($blocks, 0, $max) as $block) {
            if (!is_array($block)) {
                continue;
            }

            $html = $this->renderBlock($block);

            if ($html !== '') {
                $rendered[] = $html;
            }
        }

        return $this->document(implode("\n", $rendered), $backgroundColour);
    }

    /**
     * The plain-text alternative.
     *
     * Not an afterthought: some recipients and many filters read it, and a
     * missing or auto-mangled text part hurts deliverability. Links are rendered
     * inline so the text version is actually usable.
     *
     * @param array<int,array{type?:string,settings?:array<string,mixed>}> $blocks
     */
    public function renderText(array $blocks): string
    {
        $lines = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $text = $this->blockToText($block);

            if (trim($text) !== '') {
                $lines[] = trim($text);
            }
        }

        return implode("\n\n", $lines);
    }

    /** @param array{type?:string,settings?:array<string,mixed>} $block */
    private function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? '');

        /** @var array<string,mixed>|null $definition */
        $definition = $this->config->get('blocks.blocks.' . $type);

        // An unknown block type is dropped rather than guessed at.
        if ($definition === null) {
            return '';
        }

        $settings = $this->resolveSettings($type, $block['settings'] ?? []);

        return match ($type) {
            'heading'  => $this->heading($settings),
            'text'     => $this->text($settings),
            'image'    => $this->image($settings),
            'button'   => $this->button($settings),
            'divider'  => $this->divider($settings),
            'spacer'   => $this->spacer($settings),
            'columns'  => $this->columns($settings),
            'logo'     => $this->logo($settings),
            'product'  => $this->product($settings),
            'coupon'   => $this->coupon($settings),
            'social'   => $this->social($settings),
            'fields'   => $this->fields($settings),
            'footer'   => $this->footer($settings),
            default    => '',
        };
    }

    /**
     * Merge the author's settings over the declared defaults, coercing each to
     * its declared type. Anything not declared is discarded.
     *
     * @param mixed $provided
     * @return array<string,mixed>
     */
    private function resolveSettings(string $type, mixed $provided): array
    {
        /** @var array<string,array{type:string,default:mixed,options?:array<int,string>}> $declared */
        $declared = $this->config->get('blocks.blocks.' . $type . '.settings', []);
        $provided = is_array($provided) ? $provided : [];

        $settings = [];

        foreach ($declared as $key => $definition) {
            $value = $provided[$key] ?? $definition['default'];

            $settings[$key] = match ($definition['type']) {
                'enum'   => in_array((string) $value, $definition['options'] ?? [], true)
                    ? (string) $value
                    : (string) $definition['default'],
                'colour' => $this->colour((string) $value, (string) $definition['default']),
                'url'    => $this->url((string) $value),
                'rich'   => $this->sanitiseRichText((string) $value),
                'list'   => is_array($value) ? array_values(array_map('strval', $value)) : [],
                default  => is_scalar($value) ? (string) $value : '',
            };
        }

        return $settings;
    }

    // ------------------------------------------------------------- the blocks

    /** @param array<string,mixed> $s */
    private function heading(array $s): string
    {
        $sizes = ['h1' => '28px', 'h2' => '22px', 'h3' => '18px'];
        $size  = $sizes[(string) $s['level']] ?? '22px';

        return $this->row(sprintf(
            '<div style="font-size:%s;line-height:1.3;font-weight:700;color:%s;text-align:%s;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">%s</div>',
            $size,
            $s['colour'],
            $s['align'],
            $this->escape((string) $s['text'])
        ));
    }

    /** @param array<string,mixed> $s */
    private function text(array $s): string
    {
        $sizes = ['small' => '13px', 'normal' => '15px', 'large' => '17px'];
        $size  = $sizes[(string) $s['size']] ?? '15px';

        return $this->row(sprintf(
            '<div style="font-size:%s;line-height:1.6;color:%s;text-align:%s;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">%s</div>',
            $size,
            $s['colour'],
            $s['align'],
            $s['html']
        ));
    }

    /** @param array<string,mixed> $s */
    private function image(array $s): string
    {
        if ((string) $s['src'] === '') {
            return '';
        }

        $widths = ['full' => $this->width - 48, 'half' => (int) (($this->width - 48) / 2), 'third' => (int) (($this->width - 48) / 3)];
        $width  = $widths[(string) $s['width']] ?? $this->width - 48;

        // width/height attributes as well as CSS: Outlook ignores the CSS.
        $img = sprintf(
            '<img src="%s" alt="%s" width="%d" style="display:block;border:0;outline:none;'
            . 'text-decoration:none;max-width:100%%;height:auto;margin:%s">',
            $this->escapeAttribute((string) $s['src']),
            $this->escapeAttribute((string) $s['alt']),
            $width,
            (string) $s['align'] === 'center' ? '0 auto' : '0'
        );

        if ((string) $s['href'] !== '') {
            $img = sprintf('<a href="%s" target="_blank">%s</a>', $this->escapeAttribute((string) $s['href']), $img);
        }

        return $this->row('<div style="text-align:' . $s['align'] . '">' . $img . '</div>');
    }

    /** @param array<string,mixed> $s */
    private function button(array $s): string
    {
        if ((string) $s['href'] === '' || (string) $s['text'] === '') {
            return '';
        }

        $radii  = ['square' => '0', 'rounded' => '6px', 'pill' => '999px'];
        $radius = $radii[(string) $s['radius']] ?? '6px';

        // A table-wrapped anchor: Outlook will not honour padding on an inline
        // element, so the table cell provides it.
        return $this->row(sprintf(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="%s" style="margin:%s">'
            . '<tr><td align="center" bgcolor="%s" style="border-radius:%s">'
            . '<a href="%s" target="_blank" style="display:inline-block;padding:12px 26px;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;'
            . 'font-weight:600;color:%s;text-decoration:none;border-radius:%s">%s</a>'
            . '</td></tr></table>',
            $this->escapeAttribute((string) $s['align']),
            (string) $s['align'] === 'center' ? '0 auto' : '0',
            $this->escapeAttribute((string) $s['background']),
            $radius,
            $this->escapeAttribute((string) $s['href']),
            $this->escapeAttribute((string) $s['colour']),
            $radius,
            $this->escape((string) $s['text'])
        ));
    }

    /** @param array<string,mixed> $s */
    private function divider(array $s): string
    {
        return $this->row(sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="border-top:1px solid %s;font-size:0;line-height:0">&nbsp;</td></tr></table>',
            $this->escapeAttribute((string) $s['colour'])
        ), '8px 24px');
    }

    /** @param array<string,mixed> $s */
    private function spacer(array $s): string
    {
        $heights = ['small' => 12, 'medium' => 24, 'large' => 44];
        $height  = $heights[(string) $s['height']] ?? 24;

        return sprintf(
            '<tr><td style="height:%dpx;font-size:0;line-height:0">&nbsp;</td></tr>',
            $height
        );
    }

    /** @param array<string,mixed> $s */
    private function columns(array $s): string
    {
        // Stacking on narrow screens needs a media query, which Gmail strips.
        // Two 50% cells in a table degrade to side-by-side rather than breaking,
        // which is the safe failure mode.
        return $this->row(sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr>'
            . '<td width="50%%" valign="top" style="padding-right:10px;font-size:15px;line-height:1.6;color:%s;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">%s</td>'
            . '<td width="50%%" valign="top" style="padding-left:10px;font-size:15px;line-height:1.6;color:%s;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">%s</td>'
            . '</tr></table>',
            $this->escapeAttribute((string) $s['colour']),
            $s['left_html'],
            $this->escapeAttribute((string) $s['colour']),
            $s['right_html']
        ));
    }

    /** @param array<string,mixed> $s */
    private function logo(array $s): string
    {
        return $this->image($s + ['width' => $s['width'] ?? 'third']);
    }

    /** @param array<string,mixed> $s */
    private function product(array $s): string
    {
        $parts = [];

        if ((string) $s['image'] !== '') {
            $parts[] = sprintf(
                '<img src="%s" alt="%s" width="%d" style="display:block;border:0;max-width:100%%;height:auto;'
                . 'border-radius:6px;margin-bottom:12px">',
                $this->escapeAttribute((string) $s['image']),
                $this->escapeAttribute((string) $s['name']),
                $this->width - 48
            );
        }

        $parts[] = sprintf(
            '<div style="font-size:17px;font-weight:650;color:#111827;margin-bottom:4px">%s</div>',
            $this->escape((string) $s['name'])
        );

        if ((string) $s['price'] !== '') {
            $parts[] = sprintf(
                '<div style="font-size:15px;font-weight:600;color:#1d4ed8;margin-bottom:6px">%s</div>',
                $this->escape((string) $s['price'])
            );
        }

        if ((string) $s['description'] !== '') {
            $parts[] = sprintf(
                '<div style="font-size:14px;line-height:1.6;color:#475569;margin-bottom:12px">%s</div>',
                $this->escape((string) $s['description'])
            );
        }

        if ((string) $s['cta_href'] !== '' && (string) $s['cta_text'] !== '') {
            $parts[] = sprintf(
                '<a href="%s" target="_blank" style="font-size:14px;font-weight:600;color:#1d4ed8;'
                . 'text-decoration:underline">%s</a>',
                $this->escapeAttribute((string) $s['cta_href']),
                $this->escape((string) $s['cta_text'])
            );
        }

        return $this->row(
            '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'border:1px solid #e2e8f0;border-radius:8px;padding:16px">'
            . implode('', $parts)
            . '</div>'
        );
    }

    /** @param array<string,mixed> $s */
    private function coupon(array $s): string
    {
        $expiry = (string) $s['expiry'] !== ''
            ? sprintf('<div style="font-size:12px;margin-top:8px;opacity:.8">%s</div>', $this->escape((string) $s['expiry']))
            : '';

        return $this->row(sprintf(
            '<div style="background:%s;border-radius:8px;padding:20px;text-align:center;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:%s">'
            . '<div style="font-size:13px;margin-bottom:8px">%s</div>'
            . '<div style="font-size:26px;font-weight:700;letter-spacing:.08em;'
            . 'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace">%s</div>%s</div>',
            $this->escapeAttribute((string) $s['background']),
            $this->escapeAttribute((string) $s['colour']),
            $this->escape((string) $s['caption']),
            $this->escape((string) $s['code']),
            $expiry
        ));
    }

    /** @param array<string,mixed> $s */
    private function social(array $s): string
    {
        $links = [];

        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'website' => 'Website'] as $key => $label) {
            if ((string) $s[$key] !== '') {
                $links[] = sprintf(
                    '<a href="%s" target="_blank" style="color:#475569;text-decoration:underline;margin:0 8px;'
                    . 'font-size:13px">%s</a>',
                    $this->escapeAttribute((string) $s[$key]),
                    $label
                );
            }
        }

        if ($links === []) {
            return '';
        }

        return $this->row(
            '<div style="text-align:center;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
            . implode('', $links) . '</div>'
        );
    }

    /** @param array<string,mixed> $s */
    private function fields(array $s): string
    {
        /** @var array<string,string> $available */
        $available = $this->config->get('blocks.merge_fields', []);

        $rows = [];

        /** @var array<int,string> $selected */
        $selected = $s['fields'];

        foreach ($selected as $field) {
            if (!isset($available[$field])) {
                continue;
            }

            $rows[] = sprintf(
                '<tr><td style="padding:4px 12px 4px 0;font-size:13px;color:#64748b;white-space:nowrap">%s</td>'
                . '<td style="padding:4px 0;font-size:14px;color:#111827">{{%s}}</td></tr>',
                $this->escape($available[$field]),
                $field
            );
        }

        if ($rows === []) {
            return '';
        }

        $title = (string) $s['title'] !== ''
            ? sprintf(
                '<div style="font-size:13px;font-weight:650;color:#111827;margin-bottom:6px">%s</div>',
                $this->escape((string) $s['title'])
            )
            : '';

        return $this->row(
            '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'background:#f8fafc;border-radius:8px;padding:14px">'
            . $title
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">' . implode('', $rows) . '</table>'
            . '</div>'
        );
    }

    /**
     * An author-placed footer.
     *
     * The compliance footer — business identity, postal address, unsubscribe — is
     * added by EmailRenderer whether or not this block is used. This block only
     * lets an author position and style the extra wording.
     *
     * @param array<string,mixed> $s
     */
    private function footer(array $s): string
    {
        $extra = (string) $s['text'] !== ''
            ? '<div style="margin-bottom:8px">' . $this->escape((string) $s['text']) . '</div>'
            : '';

        return $this->row(sprintf(
            '<div style="font-size:12px;line-height:1.6;color:%s;text-align:center;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
            . '%s'
            . '<div><strong>{{business_name}}</strong></div>'
            . '<div>{{business_address}}</div>'
            . '<div style="margin-top:8px">'
            . '<a href="{{unsubscribe_url}}" style="color:%s;text-decoration:underline">Unsubscribe</a>'
            . ' &middot; '
            . '<a href="{{preferences_url}}" style="color:%s;text-decoration:underline">Email preferences</a>'
            . '</div></div>',
            $this->escapeAttribute((string) $s['colour']),
            $extra,
            $this->escapeAttribute((string) $s['colour']),
            $this->escapeAttribute((string) $s['colour'])
        ));
    }

    // ------------------------------------------------------------ plain text

    /** @param array{type?:string,settings?:array<string,mixed>} $block */
    private function blockToText(array $block): string
    {
        $type = (string) ($block['type'] ?? '');

        if ($this->config->get('blocks.blocks.' . $type) === null) {
            return '';
        }

        $s = $this->resolveSettings($type, $block['settings'] ?? []);

        return match ($type) {
            'heading' => strtoupper((string) $s['text']),
            'text'    => $this->htmlToText((string) $s['html']),
            'button'  => (string) $s['text'] . ': ' . (string) $s['href'],
            'image', 'logo' => (string) $s['href'] !== ''
                ? ((string) $s['alt'] !== '' ? (string) $s['alt'] . ': ' . (string) $s['href'] : (string) $s['href'])
                : '',
            'divider' => '---',
            'columns' => trim($this->htmlToText((string) $s['left_html']) . "\n\n" . $this->htmlToText((string) $s['right_html'])),
            'product' => trim(implode("\n", array_filter([
                (string) $s['name'],
                (string) $s['price'],
                (string) $s['description'],
                (string) $s['cta_href'] !== '' ? (string) $s['cta_text'] . ': ' . (string) $s['cta_href'] : '',
            ]))),
            'coupon'  => trim((string) $s['caption'] . "\n" . (string) $s['code'] . "\n" . (string) $s['expiry']),
            'social'  => trim(implode("\n", array_filter([
                (string) $s['facebook'], (string) $s['instagram'],
                (string) $s['linkedin'], (string) $s['website'],
            ]))),
            'fields'  => $this->fieldsToText($s),
            'footer'  => trim(((string) $s['text'] !== '' ? (string) $s['text'] . "\n" : '')
                . "{{business_name}}\n{{business_address}}\n\nUnsubscribe: {{unsubscribe_url}}"),
            default   => '',
        };
    }

    /** @param array<string,mixed> $s */
    private function fieldsToText(array $s): string
    {
        /** @var array<string,string> $available */
        $available = $this->config->get('blocks.merge_fields', []);
        $lines     = [];

        /** @var array<int,string> $selected */
        $selected = $s['fields'];

        foreach ($selected as $field) {
            if (isset($available[$field])) {
                $lines[] = $available[$field] . ': {{' . $field . '}}';
            }
        }

        return $lines === [] ? '' : trim((string) $s['title'] . "\n" . implode("\n", $lines));
    }

    private function htmlToText(string $html): string
    {
        $text = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])[^>]*>#i', "\n", $html);
        $text = (string) preg_replace('#<li[^>]*>#i', '- ', $text);
        $text = (string) preg_replace('#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    // --------------------------------------------------------------- plumbing

    private function row(string $content, string $padding = '12px 24px'): string
    {
        return sprintf('<tr><td style="padding:%s">%s</td></tr>', $padding, $content);
    }

    private function document(string $rows, string $backgroundColour): string
    {
        $background = $this->colour($backgroundColour, '#f4f5f7');

        return '<!doctype html>'
            . '<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="x-apple-disable-message-reformatting">'
            . '<meta name="color-scheme" content="light">'
            . '<title></title>'
            // Outlook needs this to honour widths at all.
            . '<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch>'
            . '</o:OfficeDocumentSettings></xml><![endif]-->'
            . '</head>'
            . '<body style="margin:0;padding:0;background:' . $background . ';">'
            // Preheader: the grey text a client shows after the subject line. Left
            // empty and hidden so a stray first line of body copy does not become it.
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">&#8199;&#65279;&#847;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background:' . $background . ';">'
            . '<tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="' . $this->width . '" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:' . $this->width . 'px;max-width:100%;background:#ffffff;border-radius:10px;'
            . 'overflow:hidden">'
            . $rows
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';
    }

    /**
     * Allowlist sanitiser for the one setting that accepts markup.
     *
     * strip_tags handles the tag allowlist; the passes after it remove the
     * attributes that turn an allowed tag into a payload — event handlers, and
     * href/src values with a scheme we do not permit.
     */
    public function sanitiseRichText(string $html): string
    {
        $clean = strip_tags($html, self::ALLOWED_INLINE_TAGS);

        // Drop every on* handler, however it is quoted.
        $clean = (string) preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);

        // style="" can carry expression() and url(javascript:) in old clients.
        $clean = (string) preg_replace('/\s+style\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $clean);

        // Rewrite href/src through the URL check so a disallowed scheme is dropped.
        $clean = (string) preg_replace_callback(
            '/\s(href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i',
            function (array $m): string {
                $url = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
                $safe = $this->url($url);

                return $safe === '' ? '' : ' ' . strtolower($m[1]) . '="' . $this->escapeAttribute($safe) . '"';
            },
            $clean
        );

        return trim($clean);
    }

    /**
     * Permit only safe schemes, and allow merge variables through untouched so
     * {{unsubscribe_url}} survives to substitution time.
     */
    public function url(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^\{\{\s*[a-z_][a-z0-9_]*\s*\}\}$/i', $value) === 1) {
            return $value;
        }

        // A relative or protocol-relative URL in an email is meaningless: there is
        // no base document. Require an absolute one.
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if ($scheme === '' || !in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return '';
        }

        return $value;
    }

    private function colour(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', trim($value)) === 1 ? trim($value) : $fallback;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Blocks for a new template, so nobody starts at a blank canvas.
     *
     * @return array<int,array{type:string,settings:array<string,mixed>}>
     */
    public function starterBlocks(string $category = 'blank'): array
    {
        $footer = ['type' => 'footer', 'settings' => ['text' => '']];

        return match ($category) {
            'promotion' => [
                ['type' => 'logo',    'settings' => []],
                ['type' => 'heading', 'settings' => ['text' => 'A limited offer for you', 'align' => 'center']],
                ['type' => 'text',    'settings' => [
                    'html'  => '<p>Hello {{first_name}}, here is something we thought you would want to know about.</p>',
                    'align' => 'center',
                ]],
                ['type' => 'coupon',  'settings' => []],
                ['type' => 'button',  'settings' => ['text' => 'Claim the offer']],
                ['type' => 'divider', 'settings' => []],
                $footer,
            ],
            'reactivation' => [
                ['type' => 'heading', 'settings' => ['text' => 'We have not seen you in a while']],
                ['type' => 'text',    'settings' => [
                    'html' => '<p>Hello {{first_name}}, it has been a while since your last visit. '
                        . 'Here is what has changed since then.</p>',
                ]],
                ['type' => 'button',  'settings' => ['text' => 'Book again']],
                ['type' => 'divider', 'settings' => []],
                $footer,
            ],
            'review_request' => [
                ['type' => 'heading', 'settings' => ['text' => 'How did we do?']],
                ['type' => 'text',    'settings' => [
                    'html' => '<p>Hello {{first_name}}, thank you for choosing {{business_name}}. '
                        . 'If you have a moment, a short review helps other people find us.</p>',
                ]],
                ['type' => 'button',  'settings' => ['text' => 'Leave a review']],
                $footer,
            ],
            'newsletter' => [
                ['type' => 'logo',    'settings' => []],
                ['type' => 'heading', 'settings' => ['text' => 'What is new this month']],
                ['type' => 'text',    'settings' => ['html' => '<p>Hello {{first_name}},</p><p>Here is this month\'s update.</p>']],
                ['type' => 'divider', 'settings' => []],
                ['type' => 'columns', 'settings' => []],
                ['type' => 'divider', 'settings' => []],
                $footer,
            ],
            default => [
                ['type' => 'heading', 'settings' => ['text' => 'Your heading']],
                ['type' => 'text',    'settings' => []],
                ['type' => 'button',  'settings' => []],
                $footer,
            ],
        };
    }
}
