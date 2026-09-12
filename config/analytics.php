<?php

declare(strict_types=1);

return [
    /*
     * How many delivered messages a campaign needs before we are willing to call
     * it a winner or a loser.
     *
     * Without a floor, "your best campaign had a 100% click rate" is a
     * three-recipient test send sitting above a real campaign's 3.1%. A
     * confident, useless answer is worse than no answer.
     */
    'minimum_meaningful_send' => (int) env('ANALYTICS_MINIMUM_SEND', 50),

    // Default reporting windows, in days.
    'windows' => [
        'campaigns'  => 90,
        'inbox'      => 30,
    ],

    /*
     * Opens are recorded and displayed, and never drive a decision.
     *
     * Apple Mail Privacy Protection and similar features fetch tracking pixels on
     * the recipient's behalf whether or not anybody read the message, so an open
     * rate can be inflated by a large fraction with no change in behaviour. The
     * UI carries this warning wherever an open rate appears.
     */
    'open_rate_caveat' => 'Opens are a rough guide only. Many mail apps now load images '
        . 'automatically, which counts as an open even when nobody read the message. '
        . 'Clicks are the number to trust.',
];
