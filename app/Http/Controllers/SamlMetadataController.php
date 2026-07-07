<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SamlMetadataController extends Controller
{
    public function __invoke(): Response
    {
        $entityId = config('app.url').'/saml2/metadata';
        $ssoUrl = config('app.url').'/saml2/sso';
        $certificate = $this->normalizeCertificate((string) config('services.saml.idp_public_cert', ''));

        $xml = view('saml.metadata', [
            'entityId' => $entityId,
            'ssoUrl' => $ssoUrl,
            'certificate' => $certificate,
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    private function normalizeCertificate(string $certificate): string
    {
        return str_replace(
            ["-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----", "\r", "\n", ' '],
            '',
            trim($certificate),
        );
    }
}
