<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SamlLaunchMvpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'services.saml.signing_private_key' => $this->testPrivateKey(),
            'services.saml.signing_public_cert' => $this->testPublicCertificate(),
            'services.saml.signing_private_key_path' => '',
            'services.saml.signing_public_cert_path' => '',
        ]);
    }

    public function test_authorized_user_can_open_sso_launch_route(): void
    {
        $user = User::factory()->create();
        $provider = ServiceProvider::factory()->create([
            'name' => 'Rooms',
            'slug' => 'rooms',
            'entity_id' => 'https://rooms.example.test/saml/metadata',
            'acs_url' => 'https://rooms.example.test/saml/acs',
        ]);
        $user->directlyAssignedServiceProviders()->attach($provider->id, ['is_active' => true]);

        $this->actingAs($user)
            ->get('/sso/rooms')
            ->assertOk()
            ->assertSee('method="POST"', false)
            ->assertSee('action="https://rooms.example.test/saml/acs"', false)
            ->assertSee('name="SAMLResponse"', false)
            ->assertSee('Opening Rooms');
    }

    public function test_sso_launch_response_contains_user_and_provider_saml_data(): void
    {
        $user = User::factory()->create([
            'email' => 'person@example.test',
            'name' => 'Portal Person',
            'role' => 'user',
        ]);
        $provider = ServiceProvider::factory()->create([
            'slug' => 'rooms',
            'entity_id' => 'https://rooms.example.test/saml/metadata',
            'acs_url' => 'https://rooms.example.test/saml/acs',
            'attribute_release' => ['email', 'name'],
        ]);
        $user->directlyAssignedServiceProviders()->attach($provider->id, ['is_active' => true]);

        $html = $this->actingAs($user)
            ->get('/sso/rooms')
            ->assertOk()
            ->getContent();

        preg_match('/name="SAMLResponse" value="([^"]+)"/', $html, $matches);
        $xml = base64_decode(html_entity_decode($matches[1]));

        $this->assertStringContainsString('Destination="https://rooms.example.test/saml/acs"', $xml);
        $this->assertStringContainsString('<ds:Signature', $xml);
        $this->assertStringContainsString('<saml:Audience>https://rooms.example.test/saml/metadata</saml:Audience>', $xml);
        $this->assertStringContainsString('<saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">person@example.test</saml:NameID>', $xml);
        $this->assertStringContainsString('<saml:Attribute Name="email"><saml:AttributeValue>person@example.test</saml:AttributeValue></saml:Attribute>', $xml);
        $this->assertStringContainsString('<saml:Attribute Name="name"><saml:AttributeValue>Portal Person</saml:AttributeValue></saml:Attribute>', $xml);
        $this->assertStringNotContainsString('<saml:Attribute Name="role">', $xml);
    }

    public function test_sp_initiated_sso_request_posts_response_to_provider_acs(): void
    {
        config(['app.url' => 'http://127.0.0.1:8012']);

        $user = User::factory()->superAdmin()->create([
            'email' => 'person@example.test',
            'name' => 'Portal Person',
        ]);
        ServiceProvider::factory()->create([
            'name' => 'Rooms',
            'slug' => 'rooms',
            'entity_id' => 'http://localhost:8000/saml2/metadata',
            'acs_url' => 'http://localhost:8000/saml2/acs',
        ]);

        $requestId = '_rooms-request-1';
        $issueInstant = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $authnRequest = <<<XML
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="{$requestId}" Version="2.0" IssueInstant="{$issueInstant}" Destination="http://127.0.0.1:8012/saml2/sso" AssertionConsumerServiceURL="http://localhost:8000/saml2/acs" ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
  <saml:Issuer xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">http://localhost:8000/saml2/metadata</saml:Issuer>
</samlp:AuthnRequest>
XML;

        $html = $this->actingAs($user)
            ->get('/saml2/sso?'.http_build_query([
                'SAMLRequest' => base64_encode(gzdeflate($authnRequest)),
                'RelayState' => '/MainDashboard',
            ]))
            ->assertOk()
            ->assertSee('action="http://localhost:8000/saml2/acs"', false)
            ->assertSee('name="RelayState" value="/MainDashboard"', false)
            ->getContent();

        preg_match('/name="SAMLResponse" value="([^"]+)"/', $html, $matches);
        $xml = base64_decode(html_entity_decode($matches[1]));

        $this->assertStringContainsString('InResponseTo="'.$requestId.'"', $xml);
        $this->assertStringContainsString('<ds:Signature', $xml);
        $this->assertStringContainsString('<saml:Audience>http://localhost:8000/saml2/metadata</saml:Audience>', $xml);
    }

    public function test_sp_initiated_sso_accepts_normalized_issuer_match(): void
    {
        config(['app.url' => 'https://ams.upcebu.edu.ph']);

        $user = User::factory()->superAdmin()->create([
            'email' => 'person@example.test',
            'name' => 'Portal Person',
        ]);
        ServiceProvider::factory()->create([
            'name' => 'FMS',
            'slug' => 'fms',
            'entity_id' => 'https://fms.upcebu.edu.ph/saml2/metadata',
            'acs_url' => 'https://fms.upcebu.edu.ph/saml2/acs',
        ]);

        $requestId = '_fms-request-1';
        $issuer = 'https://fms.upcebu.edu.ph//saml2/metadata';
        $issueInstant = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $authnRequest = <<<XML
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="{$requestId}" Version="2.0" IssueInstant="{$issueInstant}" Destination="https://ams.upcebu.edu.ph/saml2/sso" AssertionConsumerServiceURL="https://fms.upcebu.edu.ph/saml2/acs" ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
  <saml:Issuer xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">{$issuer}</saml:Issuer>
</samlp:AuthnRequest>
XML;

        $html = $this->actingAs($user)
            ->get('/saml2/sso?'.http_build_query([
                'SAMLRequest' => base64_encode(gzdeflate($authnRequest)),
                'RelayState' => '/MainDashboard',
            ]))
            ->assertOk()
            ->assertSee('action="https://fms.upcebu.edu.ph/saml2/acs"', false)
            ->getContent();

        preg_match('/name="SAMLResponse" value="([^"]+)"/', $html, $matches);
        $xml = base64_decode(html_entity_decode($matches[1]));

        $this->assertStringContainsString('InResponseTo="'.$requestId.'"', $xml);
        $this->assertStringContainsString('<ds:Signature', $xml);
        $this->assertStringContainsString('<saml:Audience>'.$issuer.'</saml:Audience>', $xml);
    }

    public function test_unassigned_user_cannot_open_sso_launch_route(): void
    {
        $user = User::factory()->create();
        ServiceProvider::factory()->create(['slug' => 'rooms']);

        $this->actingAs($user)
            ->get('/sso/rooms')
            ->assertForbidden();
    }

    public function test_department_admin_can_open_department_provider_launch_route(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->departmentAdmin()->create(['department_id' => $department->id]);
        $provider = ServiceProvider::factory()->create(['slug' => 'rooms']);
        $department->serviceProviders()->attach($provider->id, ['is_active' => true]);

        $this->actingAs($user)
            ->get('/sso/rooms')
            ->assertOk();
    }

    public function test_inactive_provider_launch_route_stays_not_found(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ServiceProvider::factory()->create([
            'slug' => 'rooms',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get('/sso/rooms')
            ->assertNotFound();
    }

    private function testPrivateKey(): string
    {
        return <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDEoCiWVqb7bN3G
aWSgiDeILnf9reH0NBJl75kp/7tvHME8XBEP5WfSPjPz1joGNAaipUGjX2/rvtMg
LQGiw2aNnsn39h2WM+LFkjpxNfdH4Is7CX4m3U3Q2YMn0RkXLSj8IcV0V+Br75To
bY6XH4BPaQMEYqDIaLKu43td/GkNzkH/8K1m1xEy+QE2eiEi6eE9xcVqgH9Scqhj
rYrskSahdZ98gGeAdGzTCRYM+SYjdbuG2RHbSE2imKuLxXeplw/g/O+EHvTHMpOd
lIUHNY3L5PYoQDw8+glftNH/c+QgmnTSdvaLSJ3KaNV09cJUKP4elbxk3O10xvDO
I3V4B1iDAgMBAAECggEADh7eRCV8aqGqFPmiwHoOKUwKfLHt9N65OXth+NCY1Wx2
KLWPq/xXuc5KoNsJKcBga0H8E5bnaSb72a8N9k1feXUr80qbwBrrn64G5Jh8U0V3
Dr6oZ1XfwnoXzcs+rxmlLq8IRvBf4kgQ+nAwkaWybVwvw+wSIB4XzBVCmOh+19h1
MhmG6/XFBNkrjrZkAlW38s9EZ31ArKrd8sDVr5WBRCNiKA7GBVxlG0bvuGuTQkj9
aK/VH4mC4nqekNUsFk8Ea8pk02HBau8BaH+4tn3S/Icu+33ZGXa1j+UfDEQwwCoR
UDB2Y/CBm/3vmSWHu0WEqyjzpyyhijR0bvHXe/HKuQKBgQDofa3HLKg7tioT3Ybf
z5BR+d6/vOF5l5HMe1YQ38ZkGCmhdgY/WdvRJbQ2K8Zy+A8szEw4ezcUomAnRlSL
hCRF5vpk3oD0/1MIPT/6LROh1n5A4DQ8cpxs5/vpOzWT6on+2oc+rxpWpkcsBTfI
Ygg3AGd1aK//kclKUoo9jgm6TwKBgQDS6D+fvRLsNSxN+fCQMUXOSOIhHzLBes3B
zhcBn3Xl6VHAn1Kk+IPj43sqqiR7HYZHfauu6Rj4IOasDWco4Cp59rBR8CglIbzm
n6jEqfIFbBZUAfdod6GxI2boNczFv9S1fe39MGZU3kKqWh9VDZ2quqQy75Bc6QNv
tIgIBME2rQKBgQCfbLmoKdKrYSNuVXP4KyaZEuam6A94Qyd8nt3U8AYovx2Bh56A
7CcuZ8bUV8WDn8Xe00MEtQzhn4FtgKmGnZEDP++6r7dVAkWFgshdgRYECYrkgkBd
FLJVy81rGM+OHVrm5hVfXqhtvzLiK9z7JQCjlgT3sKOHxapz+c0NDa0ubQKBgQCg
/PSz1UAb03HUEXcRmUMWhK1fRgZGCh3zD8YmY6cvX4F4sjeHKj+ukKe+LtlcIm/v
NugAMo3O8E363eqdTX1i1KAM6lzjth0LdUQF01rbpAD9iE9HH2EoW//pJ5xfGd4d
9Q12zS11ML87c2x2tw9IFzkDBVzmxvXsYgq2gRme9QKBgGdH0v4XJk+zM3IrtDZC
L78SfFZ9voVRnL49F9/+EHE2BbnxGniHDmYS+BY/ikwe5+0al8zEUw/sWabAfJNA
9ZKhhFjEhdtaydpGhnUFQFLGsJv/+TSQyV/Lb7+KQxPffkhYFLBoJ3BJGmYTfrz4
lE+6rzDQUi2WsjYTICn1hT5q
-----END PRIVATE KEY-----
PEM;
    }

    private function testPublicCertificate(): string
    {
        return <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDBTCCAe2gAwIBAgIUHA2SUdWFxrD9nRh6OQvLvPbL9JwwDQYJKoZIhvcNAQEL
BQAwEjEQMA4GA1UEAwwHdGVzdC1zcDAeFw0yNjA3MDcwNzAwMDBaFw0zNjA3MDUw
NzAwMDBaMBIxEDAOBgNVBAMMB3Rlc3Qtc3AwggEiMA0GCSqGSIb3DQEBAQUAA4IB
DwAwggEKAoIBAQDEoCiWVqb7bN3GaWSgiDeILnf9reH0NBJl75kp/7tvHME8XBEP
5WfSPjPz1joGNAaipUGjX2/rvtMgLQGiw2aNnsn39h2WM+LFkjpxNfdH4Is7CX4m
3U3Q2YMn0RkXLSj8IcV0V+Br75TobY6XH4BPaQMEYqDIaLKu43td/GkNzkH/8K1m
1xEy+QE2eiEi6eE9xcVqgH9ScqhjrYrskSahdZ98gGeAdGzTCRYM+SYjdbuG2RHb
SE2imKuLxXeplw/g/O+EHvTHMpOdlIUHNY3L5PYoQDw8+glftNH/c+QgmnTSdvaL
SJ3KaNV09cJUKP4elbxk3O10xvDOI3V4B1iDAgMBAAGjUzBRMB0GA1UdDgQWBBQL
E3hVYQTQU7U5rtZ9dF2Sds7XHjAfBgNVHSMEGDAWgBQLE3hVYQTQU7U5rtZ9dF2S
ds7XHjAPBgNVHRMBAf8EBTADAQH/MA0GCSqGSIb3DQEBCwUAA4IBAQBBZMgshJR2
r8+zkjIkFV2mAJwXq7NscXnCq1ZMg2pjQbPNR3Fs/ANj5Yp9DmrXwAo9Gkrpr12u
W2XWIXcehThmK4CBkSbcacZ3Zpj0ZBqrkE4qODUzA++Wj8pC+LTkgZ+RfS8r5hwe
CN18rzui7NQAFlfk5M2W1lHIcnqWlArKp77Rm+QbE+rVV9YeqGHVNzVnA81hPbfR
PM0pJ4yyXfQ6Iv35Ckh1TFlngAmA8CxoBkOZpxKo+5xYjK6+pVOAhtl79UdG7p26
zKnLEHdNY96M1E1nNY35ei9jlJkC9HRfvm9s+vwYOvhiCCuRT9UAEKCUkyQUb5X6
G3vAwdZS
-----END CERTIFICATE-----
PEM;
    }
}
