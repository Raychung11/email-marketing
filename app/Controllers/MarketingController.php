<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthManager;

/**
 * The public face of the product.
 *
 * Pricing is read from config/plans.php rather than written into the template,
 * because a price that is right on the marketing page and wrong in the billing
 * catalogue is worse than having no marketing page at all.
 */
final class MarketingController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly AuthManager $auth,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function home(Request $request): Response
    {
        // Somebody already signed in wants the product, not the sales pitch.
        if ($this->auth->check()) {
            return Response::redirect('/dashboard');
        }

        return $this->render('marketing.home', [
            'plans'      => $this->plans(),
            'currencies' => ['USD' => '$', 'AUD' => 'A$'],
        ]);
    }

    /**
     * @return array<int,array{key:string,name:string,description:string,prices:array<string,string>,limits:array<string,mixed>,highlight:bool}>
     */
    private function plans(): array
    {
        /** @var array<string,array<string,mixed>> $catalogue */
        $catalogue = $this->config->get('plans.plans', []);

        $plans = [];

        foreach ($catalogue as $key => $plan) {
            $prices = [];

            /** @var array<string,int> $minorUnits */
            $minorUnits = $plan['price'] ?? [];

            foreach ($minorUnits as $currency => $amount) {
                // Stored in minor units so nothing is ever a float. Whole
                // amounts lose the ".00", which reads better on a price card.
                $major = $amount / 100;

                $prices[$currency] = $major === floor($major)
                    ? number_format($major, 0)
                    : number_format($major, 2);
            }

            $plans[] = [
                'key'         => (string) $key,
                'name'        => (string) ($plan['name'] ?? $key),
                'description' => (string) ($plan['description'] ?? ''),
                'prices'      => $prices,
                'limits'      => (array) ($plan['limits'] ?? []),
                // The middle plan carries the emphasis, as it does everywhere.
                'highlight'   => $key === 'growth',
            ];
        }

        return $plans;
    }
}
