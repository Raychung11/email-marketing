<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * Journeys worth having on day one, ready to switch on.
 *
 * Written for the trades and services this product is for. A blank canvas is
 * useless to somebody who has never built an automation; three working examples
 * they can read and edit is how they learn what the thing does.
 *
 * Each one is deliberately short. A fourteen-step journey nobody understands
 * sends email nobody meant to send.
 */
final class JourneyTemplates
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            'welcome' => [
                'name'        => 'Welcome a new customer',
                'description' => 'Says hello the day after somebody is added, then checks in a week later.',
                'trigger'     => 'contact_created',
                'why'         => 'The first email somebody gets from you sets what they expect from the rest. '
                    . 'Sending it automatically means it actually happens.',
                'steps' => [
                    ['wait', ['wait_minutes' => 1440], 'Wait a day'],
                    ['action', ['action_type' => 'send_email', 'subject' => 'Thanks for choosing us'], 'Say hello'],
                    ['wait', ['wait_minutes' => 10080], 'Wait a week'],
                    ['action', ['action_type' => 'create_task', 'config' => [
                        'title'        => 'Ring the new customer and check they are happy',
                        'task_type'    => 'call',
                        'due_in_hours' => 48,
                    ]], 'Put a call on somebody\'s list'],
                ],
            ],

            'lead_recovery' => [
                'name'        => 'Chase an enquiry nobody answered',
                'description' => 'If an enquiry has had no reply in a day, tells the team and follows up.',
                'trigger'     => 'lead_created',
                'why'         => 'Enquiries that sit for a day rarely turn into work. This is the highest-value '
                    . 'automation most small businesses can switch on.',
                'steps' => [
                    ['wait', ['wait_minutes' => 1440], 'Wait a day'],
                    ['action', ['action_type' => 'notify_team', 'config' => [
                        'subject' => 'An enquiry has not been answered',
                        'message' => 'This enquiry came in yesterday and nobody has replied yet.',
                    ]], 'Tell the team'],
                    ['action', ['action_type' => 'send_email', 'subject' => 'Did you still want that quote?'],
                        'Follow up with them'],
                ],
            ],

            'win_back' => [
                'name'        => 'Win back a customer who has gone quiet',
                'description' => 'Reaches out to customers who have not bought or opened anything in six months.',
                'trigger'     => 'customer_inactive',
                'why'         => 'It costs far less to bring back somebody who already knows you than to find '
                    . 'somebody new.',
                'steps' => [
                    ['action', ['action_type' => 'send_email', 'subject' => 'It has been a while'],
                        'Get back in touch'],
                    ['wait', ['wait_minutes' => 20160], 'Wait a fortnight'],
                    ['action', ['action_type' => 'add_tag', 'config' => []], 'Tag them so you can see who came back'],
                ],
            ],
        ];
    }
}
