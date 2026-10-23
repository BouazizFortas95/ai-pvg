<?php

namespace Tests\Feature;

use App\Filament\Resources\News\Pages\CreateNews;
use App\Filament\Resources\News\Pages\EditNews;
use App\Filament\Resources\NewsSources\Pages\CreateNewsSource;
use App\Filament\Resources\NewsSources\Pages\EditNewsSource;
use App\Filament\Resources\NewsSources\Pages\ListNewsSources;
use App\Models\News;
use App\Models\NewsSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NewsSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_source_can_be_created_through_the_form(): void
    {
        Livewire::test(CreateNewsSource::class)
            ->fillForm([
                'name' => 'Tech Daily',
                'website_url' => 'https://techdaily.example.com',
                'rss_url' => 'https://techdaily.example.com/rss',
                'description' => 'A daily technology publication.',
                'language' => 'en',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news_sources', [
            'name' => 'Tech Daily',
            'slug' => 'tech-daily',
            'website_url' => 'https://techdaily.example.com',
            'rss_url' => 'https://techdaily.example.com/rss',
            'language' => 'en',
            'is_active' => true,
        ]);
    }

    public function test_source_can_be_updated_through_the_form(): void
    {
        $source = NewsSource::factory()->create([
            'name' => 'Before',
            'slug' => 'before',
        ]);

        Livewire::test(EditNewsSource::class, ['record' => $source->getKey()])
            ->fillForm([
                'name' => 'After',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news_sources', [
            'id' => $source->getKey(),
            'name' => 'After',
            'slug' => 'after',
            'is_active' => false,
        ]);
    }

    public function test_source_slug_is_auto_generated_from_the_name(): void
    {
        Livewire::test(CreateNewsSource::class)
            ->fillForm([
                'name' => 'World News Network',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news_sources', [
            'name' => 'World News Network',
            'slug' => 'world-news-network',
        ]);
    }

    public function test_source_slug_must_be_unique(): void
    {
        NewsSource::factory()->create([
            'slug' => 'taken-slug',
        ]);

        Livewire::test(CreateNewsSource::class)
            ->fillForm([
                'name' => 'Another source',
                'slug' => 'taken-slug',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertDatabaseCount('news_sources', 1);
    }

    public function test_website_url_must_be_a_valid_url(): void
    {
        Livewire::test(CreateNewsSource::class)
            ->fillForm([
                'name' => 'Broken website source',
                'website_url' => 'definitely not a url',
            ])
            ->call('create')
            ->assertHasFormErrors(['website_url']);

        $this->assertDatabaseCount('news_sources', 0);
    }

    public function test_rss_url_must_be_a_valid_url(): void
    {
        Livewire::test(CreateNewsSource::class)
            ->fillForm([
                'name' => 'Broken rss source',
                'rss_url' => 'not-a-valid-url',
            ])
            ->call('create')
            ->assertHasFormErrors(['rss_url']);

        $this->assertDatabaseCount('news_sources', 0);
    }

    public function test_source_name_is_required(): void
    {
        Livewire::test(CreateNewsSource::class)
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_source_with_articles_cannot_be_deleted(): void
    {
        $source = NewsSource::factory()->create();

        News::factory()->for($source, 'source')->create();

        try {
            $source->delete();

            $this->fail('Expected the deletion of a source holding articles to be blocked.');
        } catch (\DomainException) {
            // Expected: deletions of sources that own articles are rejected.
        }

        $this->assertDatabaseHas('news_sources', ['id' => $source->getKey()]);
    }

    public function test_unused_source_can_be_deleted_from_the_table(): void
    {
        $source = NewsSource::factory()->create();

        Livewire::test(ListNewsSources::class)
            ->callTableAction('delete', $source);

        $this->assertDatabaseMissing('news_sources', [
            'id' => $source->getKey(),
        ]);
    }

    public function test_source_table_lists_all_sources(): void
    {
        NewsSource::factory()->count(3)->create();

        Livewire::test(ListNewsSources::class)
            ->assertCanSeeTableRecords(NewsSource::query()->get());
    }

    public function test_only_active_sources_are_selectable_for_new_articles(): void
    {
        NewsSource::factory()->create();
        NewsSource::factory()->inactive()->create();

        $livewire = Livewire::test(CreateNews::class);

        $options = collect(
            $livewire->instance()->form->getFlatFields(withHidden: true)['news_source_id']->getOptionsForJs(),
        );

        $this->assertSame(1, $options->count(), 'Only the active source should be listed for new assignments.');
    }

    public function test_inactive_current_source_stays_visible_when_editing_an_article(): void
    {
        $source = NewsSource::factory()->inactive()->create();

        $news = News::factory()->for($source, 'source')->create();

        $livewire = Livewire::test(EditNews::class, ['record' => $news->getKey()]);

        $options = collect(
            $livewire->instance()->form->getFlatFields(withHidden: true)['news_source_id']->getOptionsForJs(),
        );

        $this->assertContains((string) $source->getKey(), $options->pluck('value'));
    }

    public function test_supported_languages_are_en_ar_and_fr(): void
    {
        $this->assertSame(['en', 'ar', 'fr'], array_keys(NewsSource::LANGUAGES));
        $this->assertSame(['English', 'Arabic', 'French'], array_values(NewsSource::LANGUAGES));
    }

    public function test_source_defaults_to_english_for_new_rows(): void
    {
        // Database-level default.
        Livewire::test(CreateNewsSource::class)
            ->fillForm([
                'name' => 'No language picked',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news_sources', [
            'name' => 'No language picked',
            'language' => 'en',
        ]);
    }
}
