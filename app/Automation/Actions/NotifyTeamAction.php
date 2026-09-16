<?php

declare(strict_types=1);

namespace App\Automation\Actions;

use App\Repositories\MembershipRepository;
use App\Services\TransactionalMailer;
use App\Support\TenantContext;

/**
 * Email somebody on the team, not the customer.
 *
 * Goes through the transactional path, which is correct here and nowhere else
 * in this directory: the recipient is a colleague being told about their own
 * business, not a customer being marketed to. The distinction is enforced by
 * which method is called, and it is called with a staff address that came from
 * the membership table — never from the journey's configuration, so a journey
 * cannot be edited into a way of emailing arbitrary strangers.
 */
final class NotifyTeamAction implements Action
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly TransactionalMailer $mailer,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context
     */
    public function perform(string $actionType, array $config, array $contact, array $context): ActionResult
    {
        $userId = (int) ($config['user_id'] ?? 0);

        $members = $this->memberships->membersOf($this->tenant->organisationId());
        $target   = null;

        foreach ($members as $member) {
            if ((string) $member['status'] !== 'active') {
                continue;
            }

            if ($userId > 0 ? (int) $member['user_id'] === $userId : (string) $member['role_key'] === 'OWNER') {
                $target = $member;
                break;
            }
        }

        if ($target === null) {
            return ActionResult::failed('There is nobody on the team to tell.');
        }

        $organisation = $this->tenant->organisation();

        $name = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
        $name = $name !== '' ? $name : (string) $contact['email'];

        $subject = mb_substr(
            (string) ($config['subject'] ?? '') ?: 'Automation: ' . $name . ' needs a look',
            0,
            180
        );

        $body = trim((string) ($config['message'] ?? ''));

        $html = '<p>' . e($body !== '' ? $body : 'One of your automations flagged this contact.') . '</p>'
            . '<p><strong>' . e($name) . '</strong><br>' . e((string) $contact['email']) . '</p>'
            . '<p><a href="' . e(url('contacts/' . (int) $contact['id'])) . '">Open their record</a></p>';

        $sent = $this->mailer->sendToContact(
            $organisation,
            ['id' => null, 'email' => (string) $target['email'], 'uuid' => ''],
            $subject,
            $html,
            strip_tags($html)
        );

        return $sent
            ? ActionResult::done('Told ' . (string) $target['email'] . '.')
            : ActionResult::skipped('Could not reach ' . (string) $target['email'] . '.');
    }
}
