<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordController;
use App\Controllers\Auth\RegisterController;
use App\Controllers\Ai\StudioController;
use App\Controllers\AnalyticsController;
use App\Controllers\ComplianceController;
use App\Controllers\Crm\CompanyController;
use App\Controllers\Crm\ContactController;
use App\Controllers\Crm\ImportController;
use App\Controllers\Crm\ListController;
use App\Controllers\Crm\SegmentController;
use App\Controllers\Crm\SuppressionController;
use App\Controllers\Crm\TagController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\Marketing\CampaignController;
use App\Controllers\Marketing\TemplateController;
use App\Controllers\OnboardingController;
use App\Controllers\OrganisationController;
use App\Controllers\Public_\SesWebhookController;
use App\Controllers\Public_\TrackingController;
use App\Controllers\Public_\UnsubscribeController;
use App\Controllers\Settings\DomainController;
use App\Controllers\SettingsController;
use App\Controllers\TeamController;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Middleware\BindTenant;
use App\Middleware\RequirePermission;
use App\Middleware\Throttle;

/*
 * Route table.
 *
 * Every authenticated route carries Authenticate → BindTenant, in that order, and
 * anything beyond reading carries a RequirePermission with a permission key. The
 * sidebar in config/navigation.php declares the same keys, so the menu and the
 * router cannot drift apart.
 */

return static function (Router $router): void {
    // ---------------------------------------------------------------- public
    $router->get('/', static fn (): \App\Core\Response => \App\Core\Response::redirect('/dashboard'));

    $router->get('/health', HealthController::class . '@live');
    $router->get('/health/ready', HealthController::class . '@ready');

    // Guest authentication. Throttled by IP on top of the per-account throttle in
    // AuthService, so neither a targeted nor a sprayed attack is cheap.
    $router->get('/login', LoginController::class . '@show');
    $router->post('/login', LoginController::class . '@login', [Throttle::class . ':20,300']);
    $router->post('/logout', LoginController::class . '@logout');

    $router->get('/register', RegisterController::class . '@show');
    $router->post('/register', RegisterController::class . '@store', [Throttle::class . ':10,3600']);

    $router->get('/forgot-password', PasswordController::class . '@showForgot');
    $router->post('/forgot-password', PasswordController::class . '@sendReset', [Throttle::class . ':10,900']);
    $router->get('/reset-password/{token}', PasswordController::class . '@showReset');
    $router->post('/reset-password', PasswordController::class . '@reset', [Throttle::class . ':10,900']);

    $router->get('/invitations/{token}', TeamController::class . '@showAcceptInvitation');
    $router->post('/invitations/accept', TeamController::class . '@acceptInvitation', [Throttle::class . ':10,900']);

    /*
     * Unsubscribe and the preference centre.
     *
     * No auth: the recipient is not a user. Authorisation is the HMAC-signed
     * token. GET only ever displays — the state change is a POST, because mail
     * clients and scanners pre-fetch links and a state-changing GET would
     * unsubscribe people who never clicked.
     */
    $router->get('/unsubscribe/{token}', UnsubscribeController::class . '@show', [Throttle::class . ':60,60']);
    $router->post('/unsubscribe/{token}', UnsubscribeController::class . '@unsubscribe', [Throttle::class . ':60,60']);
    $router->get('/preferences/{token}', UnsubscribeController::class . '@preferences', [Throttle::class . ':60,60']);
    $router->post('/preferences/{token}', UnsubscribeController::class . '@updatePreferences', [Throttle::class . ':60,60']);

    /*
     * Open and click tracking.
     *
     * No auth and no CSRF: the caller is a mail client or a recipient's browser.
     * The signed token is the authorisation, and the click endpoint resolves its
     * destination from campaign_links rather than from the URL, so it cannot be
     * turned into an open redirect. Throttled generously — a popular campaign
     * produces a burst of legitimate traffic from one mail provider's egress.
     */
    $router->get('/track/open/{token}', TrackingController::class . '@open', [Throttle::class . ':600,60']);
    $router->get('/track/click/{token}', TrackingController::class . '@click', [Throttle::class . ':600,60']);

    /*
     * Amazon SES delivery notifications, via SNS.
     *
     * No auth and no CSRF — Amazon has no credentials of ours to present. The SNS
     * signature is the authentication, and the controller checks it before it
     * reads anything else out of the body.
     */
    $router->post('/webhooks/aws/ses', SesWebhookController::class . '@handle', [Throttle::class . ':1000,60']);

    // ------------------------------------------------------- authenticated
    $authenticated = [Authenticate::class, BindTenant::class];

    $router->group(['middleware' => $authenticated], static function (Router $router): void {
        // Dashboard
        $router->get('/dashboard', DashboardController::class . '@index');
        /*
         * ------------------------------------------------------------------ AI
         *
         * Everything here produces a draft or a suggestion. Nothing sends, and
         * nothing skips review: an AI-written campaign lands in the same draft
         * state as one somebody typed and needs the same approval.
         */
        $router->get('/ai/studio', StudioController::class . '@index', [
            RequirePermission::class . ':ai.use',
        ]);
        $router->post('/ai/studio', StudioController::class . '@draft', [
            RequirePermission::class . ':ai.use', Throttle::class . ':30,3600',
        ]);
        $router->post('/ai/studio/keep', StudioController::class . '@keep', [
            RequirePermission::class . ':campaigns.create',
        ]);
        $router->post('/campaigns/{id}/ai/review', StudioController::class . '@reviewCampaign', [
            RequirePermission::class . ':ai.use', Throttle::class . ':30,3600',
        ]);
        $router->post('/segments/ai', StudioController::class . '@suggestSegment', [
            RequirePermission::class . ':ai.use', Throttle::class . ':60,3600',
        ]);
        $router->post('/campaigns/{id}/ai/subjects', StudioController::class . '@subjects', [
            RequirePermission::class . ':ai.use', Throttle::class . ':60,3600',
        ]);

        // ---------------------------------------------------------- analytics
        $router->get('/analytics', AnalyticsController::class . '@index', [
            RequirePermission::class . ':analytics.view',
        ]);
        $router->get('/analytics/campaigns', AnalyticsController::class . '@campaigns', [
            RequirePermission::class . ':analytics.view',
        ]);
        $router->get('/analytics/deliverability', AnalyticsController::class . '@deliverability', [
            RequirePermission::class . ':analytics.view',
        ]);

        // Organisation switching / creation
        $router->post('/organisations/switch', OrganisationController::class . '@switch');
        $router->get('/onboarding/organisation', OrganisationController::class . '@create');

        // Setup wizard
        $router->get('/onboarding', OnboardingController::class . '@index');
        $router->post('/onboarding/{step}', OnboardingController::class . '@completeStep');
        $router->post('/onboarding-skip', OnboardingController::class . '@skip');

        // ----------------------------------------------------------- contacts
        $router->get('/contacts', ContactController::class . '@index', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->get('/contacts/create', ContactController::class . '@create', [
            RequirePermission::class . ':contacts.create',
        ]);
        $router->post('/contacts', ContactController::class . '@store', [
            RequirePermission::class . ':contacts.create',
        ]);
        $router->get('/contacts/export', ContactController::class . '@export', [
            RequirePermission::class . ':contacts.export',
        ]);

        // Import wizard. Every step needs contacts.import; step 5 is the consent
        // gate and is what makes the whole flow safe.
        $router->get('/contacts/import', ImportController::class . '@index', [
            RequirePermission::class . ':contacts.import',
        ]);
        $router->post('/contacts/import', ImportController::class . '@upload', [
            RequirePermission::class . ':contacts.import',
        ]);
        $router->get('/contacts/import/{id}/map', ImportController::class . '@showMapping', [
            RequirePermission::class . ':contacts.import',
        ]);
        $router->post('/contacts/import/{id}/map', ImportController::class . '@saveMapping', [
            RequirePermission::class . ':contacts.import',
        ]);
        $router->get('/contacts/import/{id}/validate', ImportController::class . '@showValidation', [
            RequirePermission::class . ':contacts.import',
        ]);
        $router->post('/contacts/import/{id}/consent', ImportController::class . '@declareConsent', [
            RequirePermission::class . ':contacts.import',
        ]);
        $router->get('/contacts/import/{id}/options', ImportController::class . '@showOptions', [
            RequirePermission::class . ':contacts.import',
        ]);
        $router->post('/contacts/import/{id}/run', ImportController::class . '@run', [
            RequirePermission::class . ':contacts.import',
        ]);
        $router->get('/contacts/import/{id}/summary', ImportController::class . '@summary', [
            RequirePermission::class . ':contacts.import',
        ]);

        $router->post('/contacts/merge', ContactController::class . '@merge', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->get('/contacts/{id}', ContactController::class . '@show', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->get('/contacts/{id}/edit', ContactController::class . '@edit', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/contacts/{id}', ContactController::class . '@update', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/contacts/{id}/delete', ContactController::class . '@destroy', [
            RequirePermission::class . ':contacts.delete',
        ]);
        // Privacy erasure is a compliance action, not an edit.
        $router->post('/contacts/{id}/anonymise', ContactController::class . '@anonymise', [
            RequirePermission::class . ':compliance.manage',
        ]);
        $router->post('/contacts/{id}/tags', ContactController::class . '@addTag', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/contacts/{id}/tags/{tagId}/remove', ContactController::class . '@removeTag', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/contacts/{id}/consent', ContactController::class . '@recordConsent', [
            RequirePermission::class . ':contacts.edit',
        ]);

        // ---------------------------------------------------------- companies
        $router->get('/companies', CompanyController::class . '@index', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->post('/companies', CompanyController::class . '@store', [
            RequirePermission::class . ':contacts.create',
        ]);
        $router->get('/companies/{id}', CompanyController::class . '@show', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->post('/companies/{id}', CompanyController::class . '@update', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/companies/{id}/delete', CompanyController::class . '@destroy', [
            RequirePermission::class . ':contacts.delete',
        ]);

        // --------------------------------------------------------------- tags
        $router->get('/tags', TagController::class . '@index', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->post('/tags', TagController::class . '@store', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/tags/{id}', TagController::class . '@update', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/tags/{id}/delete', TagController::class . '@destroy', [
            RequirePermission::class . ':contacts.edit',
        ]);

        // -------------------------------------------------------------- lists
        $router->get('/lists', ListController::class . '@index', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->post('/lists', ListController::class . '@store', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->get('/lists/{id}', ListController::class . '@show', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->post('/lists/{id}', ListController::class . '@update', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/lists/{id}/delete', ListController::class . '@destroy', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/lists/{id}/contacts', ListController::class . '@addContact', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/lists/{id}/contacts/{contactId}/remove', ListController::class . '@removeContact', [
            RequirePermission::class . ':contacts.edit',
        ]);

        // ----------------------------------------------------------- segments
        $router->get('/segments', SegmentController::class . '@index', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->get('/segments/create', SegmentController::class . '@create', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/segments', SegmentController::class . '@store', [
            RequirePermission::class . ':contacts.edit',
        ]);
        // Live audience preview for the builder.
        $router->post('/segments/preview', SegmentController::class . '@preview', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->get('/segments/{id}', SegmentController::class . '@show', [
            RequirePermission::class . ':contacts.view',
        ]);
        $router->get('/segments/{id}/edit', SegmentController::class . '@edit', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/segments/{id}', SegmentController::class . '@update', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/segments/{id}/delete', SegmentController::class . '@destroy', [
            RequirePermission::class . ':contacts.edit',
        ]);
        $router->post('/segments/{id}/refresh', SegmentController::class . '@refreshCounts', [
            RequirePermission::class . ':contacts.view',
        ]);

        // -------------------------------------------------------- suppression
        // Reading and adding need compliance.manage; so does removal, which
        // additionally demands a written reason and is audited.
        $router->get('/suppressions', SuppressionController::class . '@index', [
            RequirePermission::class . ':compliance.manage',
        ]);
        $router->post('/suppressions', SuppressionController::class . '@store', [
            RequirePermission::class . ':compliance.manage',
        ]);
        $router->get('/suppressions/export', SuppressionController::class . '@export', [
            RequirePermission::class . ':compliance.manage',
        ]);
        $router->post('/suppressions/{id}/remove', SuppressionController::class . '@destroy', [
            RequirePermission::class . ':compliance.manage',
        ]);

        // --------------------------------------------------------- compliance
        $router->get('/compliance', ComplianceController::class . '@index', [
            RequirePermission::class . ':compliance.manage',
        ]);
        $router->post('/compliance', ComplianceController::class . '@update', [
            RequirePermission::class . ':compliance.manage',
        ]);
        $router->get('/compliance/audit-log', ComplianceController::class . '@auditLog', [
            RequirePermission::class . ':compliance.manage',
        ]);

        // --------------------------------------------------------------- team
        $router->get('/team', TeamController::class . '@index', [
            RequirePermission::class . ':users.manage',
        ]);
        $router->post('/team/invite', TeamController::class . '@invite', [
            RequirePermission::class . ':users.manage',
        ]);
        $router->post('/team/{id}/role', TeamController::class . '@changeRole', [
            RequirePermission::class . ':users.manage',
        ]);
        $router->post('/team/{id}/remove', TeamController::class . '@remove', [
            RequirePermission::class . ':users.manage',
        ]);

        // ----------------------------------------------------------- settings
        $router->get('/settings', SettingsController::class . '@index', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings', SettingsController::class . '@update', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->get('/settings/brand', SettingsController::class . '@brand', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/brand', SettingsController::class . '@updateBrand', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/sender', SettingsController::class . '@updateSender', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->get('/settings/custom-fields', SettingsController::class . '@customFields', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/custom-fields', SettingsController::class . '@storeCustomField', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/custom-fields/{id}/delete', SettingsController::class . '@destroyCustomField', [
            RequirePermission::class . ':settings.manage',
        ]);

        // ---------------------------------------------------------- campaigns
        $router->get('/campaigns', CampaignController::class . '@index', [
            RequirePermission::class . ':campaigns.view',
        ]);
        $router->get('/campaigns/create', CampaignController::class . '@create', [
            RequirePermission::class . ':campaigns.create',
        ]);
        $router->post('/campaigns', CampaignController::class . '@store', [
            RequirePermission::class . ':campaigns.create',
        ]);
        $router->get('/campaigns/{id}', CampaignController::class . '@show', [
            RequirePermission::class . ':campaigns.view',
        ]);
        $router->get('/campaigns/{id}/edit', CampaignController::class . '@edit', [
            RequirePermission::class . ':campaigns.edit',
        ]);
        $router->post('/campaigns/{id}', CampaignController::class . '@update', [
            RequirePermission::class . ':campaigns.edit',
        ]);
        $router->get('/campaigns/{id}/preview', CampaignController::class . '@preview', [
            RequirePermission::class . ':campaigns.view',
        ]);
        $router->post('/campaigns/{id}/validate', CampaignController::class . '@validateCampaign', [
            RequirePermission::class . ':campaigns.edit',
        ]);
        $router->post('/campaigns/{id}/submit', CampaignController::class . '@submit', [
            RequirePermission::class . ':campaigns.edit',
        ]);

        /*
         * Approval. The permission is necessary but not sufficient: the service
         * also refuses to let the author approve their own campaign, which the
         * permission matrix cannot express because a marketing manager
         * legitimately holds both permissions.
         */
        $router->post('/campaigns/{id}/approve', CampaignController::class . '@approve', [
            RequirePermission::class . ':campaigns.approve',
        ]);
        $router->post('/campaigns/{id}/request-changes', CampaignController::class . '@requestChanges', [
            RequirePermission::class . ':campaigns.approve',
        ]);

        $router->post('/campaigns/{id}/schedule', CampaignController::class . '@schedule', [
            RequirePermission::class . ':campaigns.send',
        ]);
        $router->post('/campaigns/{id}/send', CampaignController::class . '@sendNow', [
            RequirePermission::class . ':campaigns.send',
        ]);
        $router->post('/campaigns/{id}/unschedule', CampaignController::class . '@unschedule', [
            RequirePermission::class . ':campaigns.send',
        ]);
        $router->post('/campaigns/{id}/pause', CampaignController::class . '@pause', [
            RequirePermission::class . ':campaigns.send',
        ]);
        $router->post('/campaigns/{id}/resume', CampaignController::class . '@resume', [
            RequirePermission::class . ':campaigns.send',
        ]);
        $router->post('/campaigns/{id}/cancel', CampaignController::class . '@cancel', [
            RequirePermission::class . ':campaigns.send',
        ]);
        $router->post('/campaigns/{id}/duplicate', CampaignController::class . '@duplicate', [
            RequirePermission::class . ':campaigns.create',
        ]);
        $router->post('/campaigns/{id}/delete', CampaignController::class . '@destroy', [
            RequirePermission::class . ':campaigns.edit',
        ]);

        // ---------------------------------------------------------- templates
        $router->get('/templates', TemplateController::class . '@index', [
            RequirePermission::class . ':templates.manage,campaigns.view',
        ]);
        $router->get('/templates/create', TemplateController::class . '@create', [
            RequirePermission::class . ':templates.manage',
        ]);
        $router->post('/templates', TemplateController::class . '@store', [
            RequirePermission::class . ':templates.manage',
        ]);
        // Server-rendered preview: the editor shows exactly what will be sent.
        $router->post('/templates/preview', TemplateController::class . '@preview', [
            RequirePermission::class . ':templates.manage',
        ]);
        $router->get('/templates/{id}/edit', TemplateController::class . '@edit', [
            RequirePermission::class . ':templates.manage',
        ]);
        $router->post('/templates/{id}', TemplateController::class . '@update', [
            RequirePermission::class . ':templates.manage',
        ]);
        $router->post('/templates/{id}/duplicate', TemplateController::class . '@duplicate', [
            RequirePermission::class . ':templates.manage',
        ]);
        $router->post('/templates/{id}/test', TemplateController::class . '@sendTest', [
            RequirePermission::class . ':templates.manage',
        ]);
        $router->post('/templates/{id}/delete', TemplateController::class . '@destroy', [
            RequirePermission::class . ':templates.manage',
        ]);

        // ------------------------------------------------------ sending domains
        // Authentication setup is a settings concern, and a prerequisite for
        // every campaign: the validator refuses an unverified from-domain.
        $router->get('/settings/domains', DomainController::class . '@index', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/domains', DomainController::class . '@store', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->get('/settings/domains/{id}', DomainController::class . '@show', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/domains/{id}/verify', DomainController::class . '@verify', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/domains/{id}/refresh', DomainController::class . '@refresh', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/domains/{id}/test', DomainController::class . '@sendTest', [
            RequirePermission::class . ':settings.manage',
        ]);
        $router->post('/settings/domains/{id}/delete', DomainController::class . '@destroy', [
            RequirePermission::class . ':settings.manage',
        ]);

        // A user's own profile needs no permission: it is their own account.
        $router->get('/settings/profile', SettingsController::class . '@profile');
        $router->post('/settings/profile/password', SettingsController::class . '@changePassword');
    });
};
