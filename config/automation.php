<?php

declare(strict_types=1);

/*
 * The automation registry.
 *
 * Triggers and actions are declared here rather than discovered, for the same
 * reason the block registry and the segment field registry are: what a journey
 * can do is a decision the product makes, not something a stored JSON document
 * gets to widen. An unknown action type is refused, never guessed at.
 */

return [
    /*
     * What can start a journey.
     *
     * 'fires' is what the dispatcher is called with. A trigger nobody dispatches
     * is a trigger that silently never runs, so every entry here is wired to a
     * real call site.
     */
    'triggers' => [
        'contact_created' => [
            'label'       => 'Somebody new is added',
            'description' => 'Runs when a contact is created — by hand, by import, from a form, or through the API.',
        ],
        'tag_added' => [
            'label'       => 'A tag is added',
            'description' => 'Runs when somebody gets a particular tag.',
            'needs'       => 'tag_id',
        ],
        'list_joined' => [
            'label'       => 'Somebody joins a list',
            'description' => 'Runs when a contact is added to a list.',
            'needs'       => 'list_id',
        ],
        'email_clicked' => [
            'label'       => 'Somebody presses a link',
            'description' => 'Runs when a contact clicks a link in one of your emails.',
        ],
        'lead_created' => [
            'label'       => 'A new enquiry comes in',
            'description' => 'Runs when a lead is created.',
        ],
        'customer_inactive' => [
            'label'       => 'A customer goes quiet',
            'description' => 'Runs when a customer has not bought or engaged for a set number of days.',
            'needs'       => 'days',
            'scheduled'   => true,
        ],
        'birthday' => [
            'label'       => 'It is somebody\'s birthday',
            'description' => 'Runs on the day, if you hold a date of birth.',
            'scheduled'   => true,
        ],
        'api_event' => [
            'label'       => 'Something happens on your website',
            'description' => 'Runs when your site or another system sends us a named event.',
            'needs'       => 'event_name',
        ],
    ],

    /*
     * What a journey may do.
     *
     * Deliberately short. Everything here is reversible or visible; nothing
     * deletes a contact, clears a suppression or changes consent, because an
     * automation running unattended at 3am is the worst possible place for an
     * irreversible action.
     */
    'actions' => [
        'send_email'       => ['label' => 'Send an email',            'class' => App\Automation\Actions\SendEmailAction::class],
        'add_tag'          => ['label' => 'Add a tag',                'class' => App\Automation\Actions\TagAction::class],
        'remove_tag'       => ['label' => 'Remove a tag',             'class' => App\Automation\Actions\TagAction::class],
        'add_to_list'      => ['label' => 'Add to a list',            'class' => App\Automation\Actions\ListAction::class],
        'remove_from_list' => ['label' => 'Remove from a list',       'class' => App\Automation\Actions\ListAction::class],
        'update_contact'   => ['label' => 'Update a detail',          'class' => App\Automation\Actions\UpdateContactAction::class],
        'create_task'      => ['label' => 'Give someone a job to do', 'class' => App\Automation\Actions\CreateTaskAction::class],
        'notify_team'      => ['label' => 'Tell the team',            'class' => App\Automation\Actions\NotifyTeamAction::class],
        'webhook'          => ['label' => 'Tell another system',      'class' => App\Automation\Actions\WebhookAction::class],
    ],

    /*
     * Fields a contact record may be updated with by an automation.
     *
     * Not a blanket "any column": lifecycle and status are the ones a journey
     * legitimately moves, and everything else — email address, consent, the
     * suppression cache — is left to the paths that know how to do it properly.
     */
    'updatable_fields' => ['customer_status', 'lifecycle_stage', 'lead_status', 'owner_user_id'],

    /*
     * A run that keeps stepping is a run that is looping. The cap is per run,
     * and hitting it fails the run loudly rather than burning a worker.
     */
    'max_steps_per_run' => 50,

    // How many runs one scheduler tick will advance. Keeps a tick bounded.
    'runs_per_tick' => 200,

    // How long a waiting run may sit before we give up on it.
    'max_wait_days' => 365,
];
