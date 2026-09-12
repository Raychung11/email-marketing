<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CustomFieldRepository;
use App\Services\AuthManager;
use App\Services\AuthService;
use App\Services\OrganisationService;
use App\Support\TenantContext;

final class SettingsController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly OrganisationService $organisations,
        private readonly CustomFieldRepository $customFields,
        private readonly AuthService $authService,
        private readonly AuthManager $auth,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        return $this->render('settings.index', [
            'organisation' => $this->tenant->organisation(),
            'countries'    => $this->config->get('app.supported_countries', []),
            'currencies'   => $this->config->get('app.supported_currencies', []),
            'industries'   => $this->config->get('app.industries', []),
            'timezones'    => timezone_identifiers_list(),
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'             => 'required|max:200',
            'industry'         => 'nullable|max:60',
            'website'          => 'nullable|max:255',
            'country'          => 'required|max:2',
            'timezone'         => 'required|timezone',
            'currency'         => 'required|in:' . implode(',', (array) $this->config->get('app.supported_currencies', [])),
            'address_line1'    => 'nullable|max:200',
            'address_line2'    => 'nullable|max:200',
            'address_city'     => 'nullable|max:120',
            'address_state'    => 'nullable|max:120',
            'address_postcode' => 'nullable|max:30',
            'address_country'  => 'nullable|max:2',
            'contact_phone'    => 'nullable|max:40',
            'contact_email'    => 'nullable|email|max:255',
        ]);

        $this->organisations->update($this->tenant->organisationId(), array_merge($data, [
            'country'         => strtoupper((string) $data['country']),
            'currency'        => strtoupper((string) $data['currency']),
            'address_country' => isset($data['address_country'])
                ? strtoupper((string) $data['address_country'])
                : null,
        ]));

        return $this->withSuccess('/settings', 'Settings saved.');
    }

    public function updateBrand(Request $request): Response
    {
        $data = $this->validate($request, [
            'primary_colour'             => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'brand_voice'                => 'nullable|max:2000',
            'target_customer'            => 'nullable|max:2000',
            'products'                   => 'nullable|max:4000',
            'services'                   => 'nullable|max:4000',
            'unique_selling_proposition' => 'nullable|max:2000',
        ]);

        $this->organisations->update($this->tenant->organisationId(), $data);

        return $this->withSuccess('/settings/brand', 'Brand profile saved. The AI will use this as context.');
    }

    public function brand(Request $request): Response
    {
        return $this->render('settings.brand', ['organisation' => $this->tenant->organisation()]);
    }

    public function updateSender(Request $request): Response
    {
        $data = $this->validate($request, [
            'default_sender_name'  => 'required|max:120',
            'default_sender_email' => 'required|email|max:255',
            'reply_to_email'       => 'nullable|email|max:255',
        ]);

        $this->organisations->update($this->tenant->organisationId(), $data);

        return $this->withSuccess('/settings', 'Sender identity saved.');
    }

    // ------------------------------------------------------- custom fields

    public function customFields(Request $request): Response
    {
        return $this->render('settings.custom_fields', [
            'definitions' => $this->customFields->definitions('contact'),
            'types'       => $this->config->get('crm.custom_field_types', []),
        ]);
    }

    public function storeCustomField(Request $request): Response
    {
        $data = $this->validate($request, [
            'label'     => 'required|max:120',
            'key'       => 'required|alpha_dash|max:60',
            'type'      => 'required|in:' . implode(',', array_keys((array) $this->config->get('crm.custom_field_types', []))),
            'help_text' => 'nullable|max:255',
        ]);

        $key = strtolower(str_replace('-', '_', (string) $data['key']));

        if ($this->customFields->findByKey($key) !== null) {
            return $this->withError('/settings/custom-fields', 'A custom field with that key already exists.');
        }

        $options = array_values(array_filter(array_map(
            'trim',
            explode("\n", (string) $request->string('options'))
        ), static fn (string $option): bool => $option !== ''));

        $this->customFields->createDefinition([
            'entity_type' => 'contact',
            'key'         => $key,
            'label'       => $data['label'],
            'type'        => $data['type'],
            'options'     => $options === [] ? null : $options,
            'help_text'   => $data['help_text'] ?? null,
            'is_required' => $request->bool('is_required') ? 1 : 0,
        ]);

        return $this->withSuccess('/settings/custom-fields', 'Custom field created.');
    }

    public function destroyCustomField(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->customFields->findOrFail($id);
        $this->customFields->deleteDefinition($id);

        return $this->withSuccess('/settings/custom-fields', 'Custom field deleted.');
    }

    // -------------------------------------------------------------- profile

    public function profile(Request $request): Response
    {
        return $this->render('settings.profile', ['user' => $this->auth->userOrFail()]);
    }

    public function changePassword(Request $request): Response
    {
        $minLength = (int) $this->config->get('security.password.min_length', 12);

        $data = $this->validate($request, [
            'current_password' => 'required|max:255',
            'password'         => 'required|min:' . $minLength . '|max:255|confirmed',
        ], [
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        $changed = $this->authService->changePassword(
            (int) $this->auth->id(),
            (string) $data['current_password'],
            (string) $data['password']
        );

        if (!$changed) {
            return $this->withError('/settings/profile', 'Your current password is not correct.');
        }

        return $this->withSuccess('/settings/profile', 'Password changed.');
    }
}
