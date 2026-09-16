<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\EmailProviderInterface;
use App\Mail\SendResult;
use Tests\Support\ScriptedEmailProvider;
use Tests\Support\TestCase;

/**
 * Inviting someone to the team.
 *
 * The membership row and the invitation email are two separate things, and the
 * second one fails far more often than the first — a new account in its
 * provider's sandbox can only email verified addresses. Telling the inviter
 * "invitation sent" regardless means the first they hear of it is a colleague
 * who never turned up.
 */
final class TeamInvitationTest extends TestCase
{
    public function testARefusedInvitationIsReportedRatherThanShownAsSent(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $provider = new ScriptedEmailProvider();
        $provider->script(SendResult::rejected(
            'Email address is not verified. The following identities failed the check in '
            . 'region AP-SOUTHEAST-1: colleague@example.com',
            'MessageRejected'
        ));
        $this->container->instance(EmailProviderInterface::class, $provider);

        $response = $this->post('/team/invite', ['email' => 'colleague@example.com', 'role' => 'MARKETER']);

        $this->assertStatus(302, $response);

        $page = $this->get('/team');

        // The sandbox is named, because it is the actual cause and the reader
        // would otherwise go and re-check DNS records that are fine.
        $this->assertContainsString('could not', $page->body());
        $this->assertContainsString('sandbox', $page->body());
        $this->assertNotContainsString('Invitation sent to', $page->body());
    }

    /**
     * The invitation itself is still valid — only its delivery failed — so the
     * inviter is given the link rather than left with a dead end.
     */
    public function testTheInviterIsGivenTheLinkWhenTheEmailCannotBeSent(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $provider = new ScriptedEmailProvider();
        $provider->script(SendResult::rejected('Email address is not verified.', 'MessageRejected'));
        $this->container->instance(EmailProviderInterface::class, $provider);

        $this->post('/team/invite', ['email' => 'colleague@example.com', 'role' => 'MARKETER']);

        $this->assertContainsString('/invitations/', $this->get('/team')->body());
    }

    public function testAFailedInvitationIsRecordedInTheOutbox(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $provider = new ScriptedEmailProvider();
        $provider->script(SendResult::rejected('Email address is not verified.', 'MessageRejected'));
        $this->container->instance(EmailProviderInterface::class, $provider);

        $this->post('/team/invite', ['email' => 'colleague@example.com', 'role' => 'MARKETER']);

        $outbox = $this->get('/outbox');

        $this->assertContainsString('colleague@example.com', $outbox->body());
        $this->assertContainsString('Failed to send', $outbox->body());
        $this->assertContainsString('not verified', $outbox->body());
    }

    public function testASuccessfulInvitationIsRecordedAndReportedAsSent(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->container->instance(EmailProviderInterface::class, new ScriptedEmailProvider());

        $this->post('/team/invite', ['email' => 'colleague@example.com', 'role' => 'MARKETER']);

        $this->assertContainsString('Invitation sent to', $this->get('/team')->body());
        $this->assertContainsString('colleague@example.com', $this->get('/outbox')->body());
    }

    public function testAnInvitationCanBeSentAgainWithAFreshLink(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $provider = new ScriptedEmailProvider();
        $this->container->instance(EmailProviderInterface::class, $provider);

        $this->post('/team/invite', ['email' => 'colleague@example.com', 'role' => 'MARKETER']);

        $membershipId = $this->pendingMembershipId('colleague@example.com');

        $response = $this->post('/team/' . $membershipId . '/resend');

        $this->assertStatus(302, $response);
        $this->assertContainsString('fresh invitation', $this->get('/team')->body());
        $this->assertSame(2, count($provider->sentMessages()), 'the invitation went out a second time');

        // Re-issuing must invalidate the first token, or a lost invitation stays
        // redeemable by whoever ends up with it.
        $first  = $this->tokenIn($provider->sentMessages()[0]->textBody);
        $second = $this->tokenIn($provider->sentMessages()[1]->textBody);

        $this->assertNotSame($first, $second, 'a new token is issued');

        $this->signOut();

        $stale = $this->post('/invitations/accept', [
            'token'                 => $first,
            'password'              => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ]);

        $this->assertStatus(404, $stale, 'the superseded token cannot be redeemed');

        $accepted = $this->post('/invitations/accept', [
            'token'                 => $second,
            'password'              => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ]);

        $this->assertStatus(302, $accepted, 'the current token still works');
    }

    public function testSomeoneWhoHasAlreadyJoinedCannotBeSentAnotherInvitation(): void
    {
        $org  = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $ownerMembershipId = (int) $this->container->make(\App\Database\Connection::class)
            ->table('organisation_users')
            ->where('organisation_id', '=', $org['organisation_id'])
            ->where('user_id', '=', $org['user_id'])
            ->first()['id'];

        $this->post('/team/' . $ownerMembershipId . '/resend');

        $this->assertContainsString('already accepted', $this->get('/team')->body());
    }

    private function pendingMembershipId(string $email): int
    {
        /** @var \App\Database\Connection $connection */
        $connection = $this->container->make(\App\Database\Connection::class);

        $user = $connection->table('users')->where('email', '=', $email)->first();

        return (int) $connection->table('organisation_users')
            ->where('user_id', '=', (int) $user['id'])
            ->first()['id'];
    }

    private function tokenIn(string $text): string
    {
        $this->assertTrue(
            preg_match('#/invitations/([a-f0-9]{64})#', $text, $matches) === 1,
            'the email carries an invitation link'
        );

        return $matches[1];
    }
}
