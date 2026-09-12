<?php

declare(strict_types=1);

/*
 * Email block registry.
 *
 * A template is a list of blocks, each a plain array of declared settings. The
 * renderer will only emit blocks listed here, with settings listed here — an
 * unknown block type or an unknown setting is dropped rather than interpolated.
 * That is what makes it safe to store a document built in a browser and render it
 * into an email later.
 *
 * Email HTML is not web HTML: Outlook still uses Word's rendering engine, Gmail
 * strips <style>, and flexbox is not an option. Everything here renders as
 * tables with inline styles, which is why the block set is deliberately small
 * and each block owns its own markup.
 */

return [
    'blocks' => [
        'heading' => [
            'label'    => 'Heading',
            'settings' => [
                'text'      => ['type' => 'text',   'default' => 'Your heading'],
                'level'     => ['type' => 'enum',   'default' => 'h2', 'options' => ['h1', 'h2', 'h3']],
                'align'     => ['type' => 'enum',   'default' => 'left', 'options' => ['left', 'center', 'right']],
                'colour'    => ['type' => 'colour', 'default' => '#111827'],
            ],
        ],

        'text' => [
            'label'    => 'Text',
            'settings' => [
                // Limited inline markup only; see TemplateRenderer::sanitiseRichText().
                'html'   => ['type' => 'rich',   'default' => '<p>Write something worth reading.</p>'],
                'align'  => ['type' => 'enum',   'default' => 'left', 'options' => ['left', 'center', 'right']],
                'colour' => ['type' => 'colour', 'default' => '#334155'],
                'size'   => ['type' => 'enum',   'default' => 'normal', 'options' => ['small', 'normal', 'large']],
            ],
        ],

        'image' => [
            'label'    => 'Image',
            'settings' => [
                'src'   => ['type' => 'url',  'default' => ''],
                'alt'   => ['type' => 'text', 'default' => ''],
                'href'  => ['type' => 'url',  'default' => ''],
                'width' => ['type' => 'enum', 'default' => 'full', 'options' => ['full', 'half', 'third']],
                'align' => ['type' => 'enum', 'default' => 'center', 'options' => ['left', 'center', 'right']],
            ],
        ],

        'button' => [
            'label'    => 'Button',
            'settings' => [
                'text'       => ['type' => 'text',   'default' => 'Book now'],
                'href'       => ['type' => 'url',    'default' => ''],
                'align'      => ['type' => 'enum',   'default' => 'center', 'options' => ['left', 'center', 'right']],
                'background' => ['type' => 'colour', 'default' => '#1d4ed8'],
                'colour'     => ['type' => 'colour', 'default' => '#ffffff'],
                'radius'     => ['type' => 'enum',   'default' => 'rounded', 'options' => ['square', 'rounded', 'pill']],
            ],
        ],

        'divider' => [
            'label'    => 'Divider',
            'settings' => [
                'colour' => ['type' => 'colour', 'default' => '#e2e8f0'],
            ],
        ],

        'spacer' => [
            'label'    => 'Spacer',
            'settings' => [
                'height' => ['type' => 'enum', 'default' => 'medium', 'options' => ['small', 'medium', 'large']],
            ],
        ],

        'columns' => [
            'label'    => 'Two columns',
            'settings' => [
                'left_html'  => ['type' => 'rich', 'default' => '<p>Left column</p>'],
                'right_html' => ['type' => 'rich', 'default' => '<p>Right column</p>'],
                'colour'     => ['type' => 'colour', 'default' => '#334155'],
            ],
        ],

        'logo' => [
            'label'    => 'Logo',
            'settings' => [
                'src'   => ['type' => 'url',  'default' => ''],
                'alt'   => ['type' => 'text', 'default' => '{{business_name}}'],
                'href'  => ['type' => 'url',  'default' => ''],
                'width' => ['type' => 'enum', 'default' => 'third', 'options' => ['third', 'half']],
                'align' => ['type' => 'enum', 'default' => 'center', 'options' => ['left', 'center']],
            ],
        ],

        'product' => [
            'label'    => 'Product or service',
            'settings' => [
                'image'       => ['type' => 'url',  'default' => ''],
                'name'        => ['type' => 'text', 'default' => 'Service name'],
                'description' => ['type' => 'text', 'default' => ''],
                'price'       => ['type' => 'text', 'default' => ''],
                'cta_text'    => ['type' => 'text', 'default' => 'Find out more'],
                'cta_href'    => ['type' => 'url',  'default' => ''],
            ],
        ],

        'coupon' => [
            'label'    => 'Offer code',
            'settings' => [
                'code'       => ['type' => 'text',   'default' => 'SAVE20'],
                'caption'    => ['type' => 'text',   'default' => 'Use this code at checkout'],
                'expiry'     => ['type' => 'text',   'default' => ''],
                'background' => ['type' => 'colour', 'default' => '#eef2ff'],
                'colour'     => ['type' => 'colour', 'default' => '#1e3a8a'],
            ],
        ],

        'social' => [
            'label'    => 'Social links',
            'settings' => [
                'facebook'  => ['type' => 'url', 'default' => ''],
                'instagram' => ['type' => 'url', 'default' => ''],
                'linkedin'  => ['type' => 'url', 'default' => ''],
                'website'   => ['type' => 'url', 'default' => ''],
            ],
        ],

        'fields' => [
            'label'    => 'Customer details',
            'settings' => [
                // Renders selected merge fields as a small definition list, which
                // is what a booking or service reminder usually needs.
                'title'  => ['type' => 'text', 'default' => 'Your details'],
                'fields' => ['type' => 'list', 'default' => ['first_name', 'email']],
            ],
        ],

        'footer' => [
            'label'    => 'Footer',
            'settings' => [
                // The compliance footer is added automatically to every marketing
                // message. This block exists so an author can place it
                // deliberately and style it, not so they can omit it.
                'text'   => ['type' => 'text',   'default' => ''],
                'colour' => ['type' => 'colour', 'default' => '#6b7280'],
            ],
        ],
    ],

    /*
     * Merge fields offered in the editor. The renderer substitutes any variable
     * EmailRenderer knows about; this list is what the UI advertises.
     */
    'merge_fields' => [
        'first_name'       => 'First name',
        'last_name'        => 'Last name',
        'full_name'        => 'Full name',
        'email'            => 'Email address',
        'company'          => 'Company',
        'job_title'        => 'Job title',
        'city'             => 'City',
        'state'            => 'State / region',
        'country'          => 'Country',
        'customer_value'   => 'Customer value',
        'total_revenue'    => 'Total revenue',
        'business_name'    => 'Your business name',
        'business_address' => 'Your postal address',
        'business_phone'   => 'Your phone number',
        'business_email'   => 'Your contact email',
        'unsubscribe_url'  => 'Unsubscribe link',
        'preferences_url'  => 'Preference centre link',
        'current_year'     => 'Current year',
    ],

    'max_blocks' => 60,

    /* Email clients cap reliable width at around 600px. */
    'content_width' => 600,
];
