<?php

namespace App\Http\Controllers;

use App\Models\ServiceProvider;
use App\Models\User;
use App\Services\Saml\SamlResponseFactory;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SamlSsoController extends Controller
{
    public function __invoke(Request $request, SamlResponseFactory $samlResponseFactory): Response|RedirectResponse
    {
        if (! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect('/');
        }

        $authnRequest = $this->decodeAuthnRequest((string) $request->query('SAMLRequest', ''));
        $issuer = $this->extractIssuer($authnRequest);
        $requestId = $this->extractRequestId($authnRequest);

        $provider = ServiceProvider::query()
            ->where('entity_id', $issuer)
            ->where('is_active', true)
            ->firstOrFail();

        /** @var User $user */
        $user = $request->user()->loadMissing('department');

        abort_unless($this->userCanLaunch($user, $provider), 403);

        return response()->view('saml.post', [
            'acsUrl' => $provider->acs_url,
            'provider' => $provider,
            'relayState' => $request->query('RelayState', $provider->default_relay_state),
            'samlResponse' => $samlResponseFactory->makeBase64Response($user, $provider, $requestId),
        ]);
    }

    private function decodeAuthnRequest(string $encoded): string
    {
        abort_if($encoded === '', 422, 'Missing SAMLRequest.');

        $raw = base64_decode($encoded, true);
        abort_if($raw === false, 422, 'Invalid SAMLRequest encoding.');

        $inflated = @gzinflate($raw);

        return $inflated !== false ? $inflated : $raw;
    }

    private function extractIssuer(string $xml): string
    {
        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);

        $issuer = (string) $xpath->evaluate('string(/*[local-name()="AuthnRequest"]/*[local-name()="Issuer"])');

        abort_if($issuer === '', 422, 'SAMLRequest did not include an issuer.');

        return $issuer;
    }

    private function extractRequestId(string $xml): ?string
    {
        $document = $this->loadXml($xml);
        $id = (string) $document->documentElement?->getAttribute('ID');

        return $id !== '' ? $id : null;
    }

    private function loadXml(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $loaded = @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        abort_unless($loaded, 422, 'Invalid SAMLRequest XML.');

        return $document;
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
