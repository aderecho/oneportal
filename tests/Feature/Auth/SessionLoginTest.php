<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_sign_in_with_valid_credentials_and_read_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'standard.user@oneportal.test',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/login', [
            'email' => 'standard.user@oneportal.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.email', $user->email);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'auth.login',
        ]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_user_cannot_sign_in_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'standard.user@oneportal.test',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/login', [
            'email' => 'standard.user@oneportal.test',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/logout')
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'auth.logout',
        ]);
    }
}
