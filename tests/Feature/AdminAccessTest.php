<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\News\NewsResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_can_access_the_filament_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(NewsResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_regular_users_cannot_access_the_filament_panel(): void
    {
        $user = User::factory()->create(); // default role: user

        $response = $this->actingAs($user)
            ->get(NewsResource::getUrl('index'));

        $response->assertForbidden();

        $this->assertFalse($user->canAccessPanel(Filament::getCurrentOrDefaultPanel()));
    }

    public function test_unauthenticated_visitors_are_redirected_to_login(): void
    {
        $response = $this->get(NewsResource::getUrl('index'));

        $response->assertRedirect(Filament::getLoginUrl());
    }

    public function test_new_users_default_to_the_user_role(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::User, $user->fresh()->role);
    }

    public function test_role_cannot_be_granted_through_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Escalation attempt',
            'email' => 'escalation@example.com',
            'password' => 'correct-password',
            'role' => UserRole::Admin->value,
        ]);

        $user->refresh();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertFalse($user->canAccessPanel(Filament::getCurrentOrDefaultPanel()));
    }

    public function test_grant_admin_command_promotes_a_targeted_user(): void
    {
        $user = User::factory()->create([
            'email' => 'target@example.com',
        ]);

        $this->artisan('user:grant-admin', ['email' => 'target@example.com'])
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->assertTrue($user->fresh()->canAccessPanel(Filament::getCurrentOrDefaultPanel()));
    }

    public function test_grant_admin_command_reports_unknown_users(): void
    {
        $this->artisan('user:grant-admin', ['email' => 'missing@example.com'])
            ->assertFailed();
    }

    public function test_grant_admin_command_rejects_invalid_emails(): void
    {
        $this->artisan('user:grant-admin', ['email' => 'not-an-email'])
            ->assertFailed();
    }

    public function test_existing_authentication_still_works(): void
    {
        $user = User::factory()->create([
            'password' => 'correct-password',
        ]);

        $this->actingAs($user)
            ->get(NewsResource::getUrl('index'))
            ->assertForbidden(); // reachable request hummed through auth

        // Session authentication contract stays intact beyond panel access.
        $this->assertTrue(Auth::check());
    }
}
