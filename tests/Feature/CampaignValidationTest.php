<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Compliance\CampaignValidator;
use App\Compliance\ReasonCode;
use Tests\Support\TestCase;

/**
 * §11 / §18 — campaign preconditions, including the US requirements.
 */
final class CampaignValidationTest extends TestCase
{
    public function testAFullyConfiguredUsCampaignPasses(): void
    {
        $organisation = $this->configuredOrganisation('US');

        $result = $this->validator()->validate($this->campaign(), $organisation, ['eligible' => 500]);

        $this->assertTrue($result['valid'], 'Expected no blocking findings, got: ' . $this->codes($result['blocking']));
        $this->assertCount(0, $result['blocking']);
    }

    public function testUsCampaignIsBlockedWithoutAPhysicalPostalAddress(): void
    {
        $organisation = $this->configuredOrganisation('US');

        // Remove the address a US commercial marketing email must carry.
        unset($organisation['address_line1'], $organisation['address_city'], $organisation['address_postcode']);
        $organisation['address_line1'] = '';

        $result = $this->validator()->validate($this->campaign(), $organisation, ['eligible' => 10]);

        $this->assertFalse($result['valid']);
        $this->assertContainsString(ReasonCode::NO_POSTAL_ADDRESS, $this->codes($result['blocking']));
    }

    public function testAustralianCampaignWarnsRatherThanBlocksOnPostalAddress(): void
    {
        $organisation                  = $this->configuredOrganisation('AU');
        $organisation['address_line1'] = '';

        $result = $this->validator()->validate($this->campaign(), $organisation, ['eligible' => 10]);

        // The AU rule set does not make a postal address a hard requirement, but
        // it is still surfaced, because it is good practice and it is required the
        // moment a US recipient is in the audience.
        $this->assertTrue($result['valid']);
        $this->assertContainsString(ReasonCode::NO_POSTAL_ADDRESS, $this->codes($result['warnings']));
    }

    public function testMissingSenderIdentityBlocks(): void
    {
        $organisation = $this->configuredOrganisation('US');

        $campaign = $this->campaign();
        unset($campaign['from_email'], $campaign['from_name']);
        $organisation['default_sender_email'] = '';
        $organisation['default_sender_name']  = '';

        $result = $this->validator()->validate($campaign, $organisation, ['eligible' => 10]);

        $this->assertFalse($result['valid']);
        $this->assertContainsString(ReasonCode::NO_SENDER_IDENTITY, $this->codes($result['blocking']));
    }

    public function testAnUnverifiedSendingDomainBlocks(): void
    {
        $organisation = $this->configuredOrganisation('US', verifyDomain: false);

        $result = $this->validator()->validate($this->campaign(), $organisation, ['eligible' => 10]);

        $this->assertFalse($result['valid']);
        $this->assertContainsString(ReasonCode::SENDER_DOMAIN_UNVERIFIED, $this->codes($result['blocking']));
    }

    public function testMissingSubjectOrContentBlocks(): void
    {
        $organisation = $this->configuredOrganisation('US');

        $campaign            = $this->campaign();
        $campaign['subject'] = '';

        $result = $this->validator()->validate($campaign, $organisation, ['eligible' => 10]);
        $this->assertContainsString(ReasonCode::NO_SUBJECT, $this->codes($result['blocking']));

        $campaign                 = $this->campaign();
        $campaign['html_content'] = '   ';

        $result = $this->validator()->validate($campaign, $organisation, ['eligible' => 10]);
        $this->assertContainsString(ReasonCode::NO_CONTENT, $this->codes($result['blocking']));
    }

    public function testDeceptiveSubjectLinesAreBlockedForUsCampaigns(): void
    {
        $organisation = $this->configuredOrganisation('US');

        foreach ([
            'Re: your enquiry',
            'FW: quick question',
            'Your order confirmation #4821',
            'Urgent: your account payment',
            'Undelivered mail returned to sender',
        ] as $subject) {
            $campaign            = $this->campaign();
            $campaign['subject'] = $subject;

            $result = $this->validator()->validate($campaign, $organisation, ['eligible' => 10]);

            $this->assertContainsString(
                ReasonCode::DECEPTIVE_SUBJECT,
                $this->codes($result['blocking']),
                'Expected "' . $subject . '" to be refused as misleading'
            );
        }
    }

    public function testAnHonestSubjectLineIsAccepted(): void
    {
        $organisation = $this->configuredOrganisation('US');

        foreach ([
            'Your annual plumbing check is due',
            '20% off drain cleaning this month',
            'We are open over the holidays',
        ] as $subject) {
            $campaign            = $this->campaign();
            $campaign['subject'] = $subject;

            $result = $this->validator()->validate($campaign, $organisation, ['eligible' => 10]);

            $this->assertTrue($result['valid'], 'Expected "' . $subject . '" to pass');
        }
    }

    public function testAContentWithoutAnUnsubscribeLinkWarns(): void
    {
        $organisation = $this->configuredOrganisation('US');

        $campaign                 = $this->campaign();
        $campaign['html_content'] = '<p>Hello, we are open this weekend.</p>';

        $result = $this->validator()->validate($campaign, $organisation, ['eligible' => 10]);

        // The renderer guarantees a footer, so this is a warning rather than a
        // block — but it is surfaced, because relying on the automatic footer
        // means nobody reviewed the placement.
        $this->assertTrue($result['valid']);
        $this->assertContainsString(ReasonCode::NO_UNSUBSCRIBE, $this->codes($result['warnings']));
    }

    public function testACampaignWithNoAudienceOrNoEligibleRecipientsBlocks(): void
    {
        $organisation = $this->configuredOrganisation('US');

        $campaign = $this->campaign();
        unset($campaign['segment_id'], $campaign['list_id']);

        $result = $this->validator()->validate($campaign, $organisation, []);
        $this->assertContainsString(ReasonCode::NO_AUDIENCE, $this->codes($result['blocking']));

        // An audience that resolves to nobody sendable is just as much a stop.
        $result = $this->validator()->validate($this->campaign(), $organisation, ['eligible' => 0]);
        $this->assertContainsString(ReasonCode::NO_ELIGIBLE_RECIPIENTS, $this->codes($result['blocking']));
    }

    public function testACampaignBeyondTheDailySendLimitBlocks(): void
    {
        $organisation                     = $this->configuredOrganisation('US');
        $organisation['daily_send_limit'] = 500;

        $result = $this->validator()->validate($this->campaign(), $organisation, ['eligible' => 5000]);

        $this->assertFalse($result['valid']);
        $this->assertContainsString(ReasonCode::ORG_DAILY_LIMIT_REACHED, $this->codes($result['blocking']));
    }

    public function testAPausedOrSuspendedOrganisationCannotSend(): void
    {
        $organisation                   = $this->configuredOrganisation('US');
        $organisation['sending_paused'] = 1;
        $organisation['sending_paused_reason'] = 'Complaint rate above threshold';

        $result = $this->validator()->validate($this->campaign(), $organisation, ['eligible' => 10]);

        $this->assertFalse($result['valid']);
        $this->assertContainsString(ReasonCode::ORG_SENDING_PAUSED, $this->codes($result['blocking']));

        $organisation['sending_paused'] = 0;
        $organisation['status']         = 'suspended';

        $result = $this->validator()->validate($this->campaign(), $organisation, ['eligible' => 10]);
        $this->assertContainsString(ReasonCode::ORG_SUSPENDED, $this->codes($result['blocking']));
    }

    public function testAPromotionRelabelledAsTransactionalIsBlocked(): void
    {
        $organisation = $this->configuredOrganisation('US');

        $campaign                  = $this->campaign();
        $campaign['campaign_type'] = 'promotion';
        $campaign['message_class'] = 'transactional';

        $result = $this->validator()->validate($campaign, $organisation, ['eligible' => 10]);

        $this->assertFalse($result['valid']);
        $this->assertContainsString(ReasonCode::MARKETING_AS_TRANSACTIONAL, $this->codes($result['blocking']));
    }

    // ---------------------------------------------------------------- helpers

    private function validator(): CampaignValidator
    {
        return $this->container->make(CampaignValidator::class);
    }

    /** @return array<string,mixed> */
    private function campaign(): array
    {
        return [
            'name'          => 'Autumn maintenance reminder',
            'subject'       => 'Your annual plumbing check is due',
            'from_name'     => 'Perth Plumbing Co',
            'from_email'    => 'hello@perthplumbing.test',
            'campaign_type' => 'service',
            'message_class' => 'marketing',
            'html_content'  => '<p>Hello {{first_name}}, book your check.</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
            'segment_id'    => 1,
        ];
    }

    /** @return array<string,mixed> */
    private function configuredOrganisation(string $country, bool $verifyDomain = true): array
    {
        $org = $this->createOrganisation(['country' => $country, 'name' => 'Perth Plumbing Co']);
        $this->bindTenant($org['organisation_id']);

        $this->connection->execute(
            'UPDATE organisations SET address_line1 = ?, address_city = ?, address_state = ?, address_postcode = ?,
                    address_country = ?, contact_phone = ?, contact_email = ?,
                    default_sender_name = ?, default_sender_email = ?, daily_send_limit = 100000
             WHERE id = ?',
            [
                '12 Example Street', 'Perth', 'WA', '6000', $country,
                '+61 8 5550 1000', 'hello@perthplumbing.test',
                'Perth Plumbing Co', 'hello@perthplumbing.test',
                $org['organisation_id'],
            ]
        );

        $this->connection->table('sending_domains')->insert([
            'organisation_id' => $org['organisation_id'],
            'domain'          => 'perthplumbing.test',
            'status'          => $verifyDomain ? 'verified' : 'pending',
            'dkim_status'     => $verifyDomain ? 'verified' : 'pending',
            'provider'        => 'ses',
            'created_at'      => $this->clock->nowString(),
            'updated_at'      => $this->clock->nowString(),
        ]);

        return $this->bindTenant($org['organisation_id']);
    }

    /** @param array<int,array{code:string,message:string}> $findings */
    private function codes(array $findings): string
    {
        return implode(', ', array_column($findings, 'code'));
    }
}
