<?php

declare(strict_types=1);

namespace App\Controllers\Public_;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Repositories\OrganisationRepository;
use App\Services\FormService;
use App\Support\TenantContext;

/**
 * The public face of a signup form: the hosted page, and the endpoint an
 * embedded form posts to.
 *
 * No authentication, because the person filling it in is a member of the public.
 * The tenant comes from the form's own slug plus the organisation that owns it —
 * looked up together, so a slug from one organisation can never resolve against
 * another's form.
 */
final class FormController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly FormService $forms,
        private readonly OrganisationRepository $organisations,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    /** GET /f/{organisation}/{slug} */
    public function show(Request $request): Response
    {
        [$organisation, $form] = $this->resolve($request);

        return $this->renderPublic('public.form', [
            'organisation' => $organisation,
            'form'         => $form,
            'errors'       => [],
            'old'          => [],
        ]);
    }

    /** POST /f/{organisation}/{slug} */
    public function submit(Request $request): Response
    {
        [$organisation, $form] = $this->resolve($request);

        try {
            $result = $this->forms->submit($form, $request->all(), [
                'ip'           => $request->ip(),
                'user_agent'   => $request->userAgent(),
                'referrer'     => (string) $request->header('Referer'),
                'anonymous_id' => $request->string('anonymous_id'),
            ]);
        } catch (ValidationException $e) {
            return $this->renderPublic('public.form', [
                'organisation' => $organisation,
                'form'         => $form,
                'errors'       => $e->errors(),
                'old'          => $request->all(),
            ], 422);
        } finally {
            $this->tenant->clear();
        }

        if (($result['redirect'] ?? null) !== null && $result['redirect'] !== '') {
            return Response::redirect((string) $result['redirect']);
        }

        return $this->renderPublic('public.form_done', [
            'organisation' => $organisation,
            'message'      => $result['message'],
        ]);
    }

    /** @param array<string,mixed> $data */
    private function renderPublic(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Find the form, and bind the tenant it belongs to.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function resolve(Request $request): array
    {
        $organisationId = (int) $request->route('organisation');
        $slug           = (string) $request->route('slug', '');

        $organisation = $this->organisations->findById($organisationId);

        if ($organisation === null) {
            throw HttpException::notFound();
        }

        $this->tenant->clear();
        $this->tenant->bind($organisationId, null, $organisation);

        $form = $this->forms->findPublished($organisationId, $slug);

        if ($form === null) {
            $this->tenant->clear();

            throw HttpException::notFound();
        }

        return [$organisation, $form];
    }
}
