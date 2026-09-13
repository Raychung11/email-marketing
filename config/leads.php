<?php

declare(strict_types=1);

/*
 * Lead scoring.
 *
 * A score is a sorting aid, not a verdict. Its only job is to put the enquiry
 * most worth ringing at the top of somebody's morning list. So the rules are
 * few, readable, and every one of them is shown to the user with its points —
 * a score nobody can explain is a score nobody trusts, and they go back to
 * working the list top to bottom by date.
 */

return [
    'scoring' => [
        'rules' => [
            'has_phone' => [
                'points' => 10,
                'label'  => 'Gave you a phone number',
                'why'    => 'Somebody who leaves a number expects a call.',
            ],
            'enquiry_detail' => [
                'points' => 10,
                'label'  => 'Wrote more than a line about what they need',
                'why'    => 'Effort in the enquiry usually means a real job.',
            ],
            'estimated_value' => [
                'points' => 15,
                'label'  => 'Worth more than your average job',
                'why'    => 'Bigger jobs deserve the first phone call.',
            ],
            'existing_customer' => [
                'points' => 20,
                'label'  => 'Has bought from you before',
                'why'    => 'They already know you, so they are the easiest to win.',
            ],
            'clicked_recent_email' => [
                'points' => 15,
                'label'  => 'Pressed a link in one of your emails recently',
                'why'    => 'They were thinking about you this week.',
            ],
            'visited_pricing' => [
                'points' => 20,
                'label'  => 'Looked at your prices',
                'why'    => 'Somebody checking prices is close to deciding.',
            ],
            'repeat_enquiry' => [
                'points' => 10,
                'label'  => 'Has asked before',
                'why'    => 'A second enquiry means the first one never got answered properly.',
            ],
        ],

        // Above this, the lead is worth interrupting somebody's day for.
        'hot_threshold'  => 50,
        'warm_threshold' => 25,
    ],

    // How long an unanswered enquiry may sit before it is flagged.
    'stale_after_hours' => 24,

    'sources' => [
        'website_form' => 'Website form',
        'phone'        => 'Phone call',
        'email'        => 'Email',
        'walk_in'      => 'Walked in',
        'referral'     => 'Referral',
        'campaign'     => 'From a campaign',
        'automation'   => 'From a journey',
        'api'          => 'Another system',
        'manual'       => 'Added by hand',
    ],
];
