<?php

namespace App\Http\Controllers;

use App\Models\ServiceProvider;
use App\Models\User;
use App\Services\Saml\SamlResponseFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SamlLaunchController extends Controller
{
    public function __invoke(Request $request, string $slug, SamlResponseFactory $samlResponseFactory): Response|RedirectResponse
    {
        if (! $request->user()) {
            return redirect('/');
        }

        $provider = ServiceProvider::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        /** @var User $user */
        $user = $request->user();

        abort_unless($this->userCanLaunch($user, $provider), 403);

        return response()->view('saml.post', [
            'acsUrl' => $provider->acs_url,
            'provider' => $provider,
            'relayState' => $provider->default_relay_state,
            'samlResponse' => $samlResponseFactory->makeBase64Response($user->loadMissing('department'), $provider),
        ]);
    }

    private function userCanLaunch(User $user, ServiceProvider $provider): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isDepartmentAdmin()) {
            return $user->department?->serviceProviders()
                ->where('service_providers.id', $provider->id)
                ->exists() ?? false;
        }

        return $user->directlyAssignedServiceProviders()
            ->where('service_providers.id', $provider->id)
            ->exists();
    }
}
