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
        $this->assertStringContainsString('<saml:Audience>http://localhost:8000/saml2/metadata</saml:Audience>', $xml);
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
}
