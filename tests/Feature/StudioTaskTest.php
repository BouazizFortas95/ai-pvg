<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class StudioTaskTest extends TestCase
{
    use RefreshDatabase;
    public function test_studio_requires_auth(): void
    {
        $response = $this->get('/studio');
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_theme_engine_saves_preferences(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        \App\Services\ThemeEngine::saveForUser($user->id, [
            'primary_color' => '#ff0000',
            'mode' => 'light',
        ]);
        $prefs = \App\Services\ThemeEngine::getThemeForUser($user->id);
        $this->assertSame('#ff0000', $prefs['--primary']);
        $this->assertSame('light', $prefs['--mode']);
    }
}
