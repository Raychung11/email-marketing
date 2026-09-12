<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordController;
use App\Controllers\Auth\RegisterController;
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
use App\Controllers\OnboardingController;
use App\Controllers\OrganisationController;
use App\Controllers\Public_\UnsubscribeController;
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

    // ------------------------------------------------------- authenticated
    $authenticated = [Authenticate::class, BindTenant::class];

    $router->group(['middleware' => $authenticated], static function (Router $router): void {
        // Dashboard
        $router->get('/dashboard', DashboardController::class . '@index');
        $router->get('/analytics', DashboardController::class . '@analytics', [
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

        // A user's own profile needs no permission: it is their own account.
        $router->get('/settings/profile', SettingsController::class . '@profile');
        $router->post('/settings/profile/password', SettingsController::class . '@changePassword');
    });
};
