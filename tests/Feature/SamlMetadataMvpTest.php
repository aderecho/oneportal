<?php

namespace Tests\Feature;

use Tests\TestCase;

class SamlMetadataMvpTest extends TestCase
{
    public function test_metadata_endpoint_exposes_public_idp_metadata_without_private_keys(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8012',
            'services.saml.idp_public_cert' => "-----BEGIN CERTIFICATE-----\nMIICtestcert==\n-----END CERTIFICATE-----",
        ]);

        $this->get('/saml2/metadata')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/samlmetadata+xml')
            ->assertSee('EntityDescriptor', false)
            ->assertSee('IDPSSODescriptor', false)
            ->assertSee('entityID="http://127.0.0.1:8012/saml2/metadata"', false)
            ->assertSee('Location="http://127.0.0.1:8012/saml2/sso"', false)
            ->assertSee('KeyDescriptor', false)
            ->assertSee('<ds:X509Certificate>MIICtestcert==</ds:X509Certificate>', false)
            ->assertDontSee('PRIVATE KEY', false);
    }
}
