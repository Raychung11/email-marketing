<?php

declare(strict_types=1);

return [
    'provider' => env('SMS_PROVIDER', 'log'),

    'providers' => [
        'twilio' => [
            'account_sid'     => env('TWILIO_ACCOUNT_SID', ''),
            'auth_token'      => env('TWILIO_AUTH_TOKEN', ''),
            'from_number'     => env('TWILIO_FROM_NUMBER', ''),
            'status_callback' => env('TWILIO_STATUS_CALLBACK', ''),
            'timeout'         => 20,
        ],
        'log' => [
            'path' => base_path('storage/logs/sms.log'),
        ],
    ],

    /*
     * Quiet hours, in the recipient's own timezone where we know it, otherwise
     * the organisation's.
     *
     * Email arriving at 2am is ignored until morning. A text arriving at 2am
     * wakes somebody up, and they will not be pleased with the business that
     * sent it. Marketing texts outside these hours are held until the window
     * opens rather than dropped — the message is still worth sending, just not
     * now.
     */
    'quiet_hours' => [
        'enabled' => true,
        'from'    => 21,   // 9pm
        'until'   => 8,    // 8am
    ],

    /*
     * A text costs real money, so the product says what a send will cost before
     * anybody presses the button. Above this, we make them confirm.
     */
    'confirm_spend_above' => (float) env('SMS_CONFIRM_ABOVE', 25.0),

    // One segment is 160 GSM-7 characters; beyond that the customer is paying
    // for two. Worth telling them while they can still edit it.
    'segment_length' => 160,

    'max_length' => 1600,
];
