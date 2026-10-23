<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Filament\Resources\News\NewsResource;
use App\Filament\Resources\News\Pages\CreateNews;
use App\Filament\Resources\News\Pages\EditNews;
use App\Filament\Resources\News\Pages\ListNews;
use App\Models\News;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class NewsFilamentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_resource_is_registered_in_the_panel(): void
    {
        $this->assertSame(News::class, NewsResource::getModel());

        $response = $this->get(NewsResource::getUrl('index'));

        $response->assertSuccessful();
    }

    public function test_resource_is_only_accessible_to_authenticated_users(): void
    {
        auth()->logout();

        $response = $this->get(NewsResource::getUrl('index'));

        $response->assertRedirect();
    }

    public function test_resource_has_correct_navigation_configuration(): void
    {
        $this->assertSame('Content Management', NewsResource::getNavigationGroup());
        $this->assertSame('News', NewsResource::getNavigationLabel());
        $this->assertSame(1, NewsResource::getNavigationSort());

        $icon = NewsResource::getNavigationIcon();

        $this->assertTrue(
            $icon === Heroicon::Newspaper || $icon === Heroicon::Newspaper->value,
            'Navigation icon should be Heroicon::Newspaper.',
        );
    }

    public function test_form_contains_all_required_fields(): void
    {
        Livewire::test(CreateNews::class)
            ->assertFormFieldExists('title')
            ->assertFormFieldExists('slug')
            ->assertFormFieldExists('category')
            ->assertFormFieldExists('status')
            ->assertFormFieldExists('summary')
            ->assertFormFieldExists('content')
            ->assertFormFieldExists('source_name')
            ->assertFormFieldExists('source_url')
            ->assertFormFieldExists('image')
            ->assertFormFieldExists('published_at');

        // Field-level configuration.
        Livewire::test(EditNews::class, ['record' => News::factory()->create()->getKey()])
            ->assertFormFieldExists('title', checkFieldUsing: fn ($field) => $field->isRequired())
            ->assertFormFieldExists('slug', checkFieldUsing: fn ($field) => $field->isRequired())
            ->assertFormFieldExists('source_url', checkFieldUsing: fn ($field) => in_array('url', $field->getValidationRules() ?? [], true));
    }

    public function test_image_field_only_allows_supported_image_formats(): void
    {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        Livewire::test(EditNews::class, ['record' => News::factory()->create()->getKey()])
            ->assertFormFieldExists('image', checkFieldUsing: function ($field) use ($allowedTypes) {
                return $field->getAcceptedFileTypes() === $allowedTypes
                    && $field->getDiskName() === 'public'
                    && $field->getMaxSize() === 5120;
            });
    }

    public function test_status_select_only_offers_supported_statuses(): void
    {
        $expectedOptions = [
            'draft' => 'Draft',
            'ready' => 'Ready',
            'processed' => 'Processed',
            'rejected' => 'Rejected',
        ];

        Livewire::test(EditNews::class, ['record' => News::factory()->create()->getKey()])
            ->assertFormFieldExists('status', checkFieldUsing: fn ($field) => $field->getOptions() === $expectedOptions);
    }

    public function test_news_can_be_created_through_the_form(): void
    {
        Storage::fake('public');

        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => 'Breaking: Filament 5 works great',
                'slug' => 'breaking-filament-5-works-great',
                'category' => 'technology',
                'status' => 'ready',
                'summary' => 'A quick summary.',
                'content' => '<p>Full article body.</p>',
                'source_name' => 'AI-PVG Blog',
                'source_url' => 'https://example.com/article',
                'published_at' => '2026-01-15 09:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news', [
            'title' => 'Breaking: Filament 5 works great',
            'slug' => 'breaking-filament-5-works-great',
            'category' => 'technology',
            'status' => 'ready',
            'source_name' => 'AI-PVG Blog',
            'source_url' => 'https://example.com/article',
        ]);
    }

    public function test_news_can_be_edited_through_the_form(): void
    {
        $news = News::factory()->create([
            'title' => 'Before edit',
            'status' => 'draft',
        ]);

        Livewire::test(EditNews::class, ['record' => $news->getKey()])
            ->fillForm([
                'title' => 'After edit',
                'slug' => 'after-edit',
                'status' => 'ready',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news', [
            'id' => $news->getKey(),
            'title' => 'After edit',
            'slug' => 'after-edit',
            'status' => 'ready',
        ]);
    }

    public function test_news_can_be_deleted_from_the_table(): void
    {
        $news = News::factory()->create();

        Livewire::test(ListNews::class)
            ->callTableAction('delete', $news);

        $this->assertDatabaseMissing('news', [
            'id' => $news->getKey(),
        ]);
    }

    public function test_bulk_delete_removes_selected_records(): void
    {
        $records = News::factory()->count(3)->create();

        Livewire::test(ListNews::class)
            ->callTableBulkAction('delete', $records);

        $this->assertDatabaseCount('news', 0);
    }

    public function test_title_is_required(): void
    {
        Livewire::test(CreateNews::class)
            ->fillForm([
                'slug' => 'missing-title-article',
            ])
            ->call('create')
            ->assertHasFormErrors(['title']);
    }

    public function test_slug_is_required(): void
    {
        // An empty title auto-generates an empty slug, so both
        // fields are expected to fail validation in this scenario.
        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['title', 'slug']);
    }

    public function test_slug_must_be_unique(): void
    {
        News::factory()->create([
            'slug' => 'already-taken',
        ]);

        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => 'Another article',
                'slug' => 'already-taken',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertDatabaseCount('news', 1);
    }

    public function test_unique_slug_is_ignored_for_the_same_record_on_edit(): void
    {
        $news = News::factory()->create([
            'slug' => 'own-slug',
        ]);

        Livewire::test(EditNews::class, ['record' => $news->getKey()])
            ->fillForm([
                'slug' => 'own-slug',
            ])
            ->call('save')
            ->assertHasNoFormErrors(['slug']);

        $this->assertDatabaseCount('news', 1);
    }

    public function test_source_url_must_be_a_valid_url(): void
    {
        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => 'Invalid source URL article',
                'source_url' => 'definitely not a url',
            ])
            ->call('create')
            ->assertHasFormErrors(['source_url'])
            // The request aborts validation; the record must not exist.
            ->assertNotSet('data.source_url', fn ($state) => true);

        $this->assertDatabaseMissing('news', [
            'title' => 'Invalid source URL article',
        ]);
    }

    public function test_slug_is_auto_generated_from_the_title(): void
    {
        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => 'Filament Five Full Stack',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news', [
            'title' => 'Filament Five Full Stack',
            'slug' => 'filament-five-full-stack',
        ]);
    }

    public function test_table_shows_news_records(): void
    {
        $news = News::factory()->count(5)->create();

        $newsQuery = Livewire::test(ListNews::class);

        $newsQuery->assertCanSeeTableRecords($news);
    }

    public function test_table_can_be_searched_by_title(): void
    {
        $matching = News::factory()->create([
            'title' => 'Quantum computing breakthrough',
        ]);

        $other = News::factory()->create([
            'title' => 'Local cooking fair opens',
        ]);

        Livewire::test(ListNews::class)
            ->searchTable('Quantum')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_table_can_filter_by_category(): void
    {
        $technology = News::factory()->create([
            'category' => 'technology',
        ]);

        $business = News::factory()->create([
            'category' => 'business',
        ]);

        Livewire::test(ListNews::class)
            ->filterTable('category', 'technology')
            ->assertCanSeeTableRecords([$technology])
            ->assertCanNotSeeTableRecords([$business]);
    }

    public function test_table_can_filter_by_status(): void
    {
        $ready = News::factory()->create([
            'status' => 'ready',
        ]);

        $rejected = News::factory()->create([
            'status' => 'rejected',
        ]);

        Livewire::test(ListNews::class)
            ->filterTable('status', NewsStatus::Ready)
            ->assertCanSeeTableRecords([$ready])
            ->assertCanNotSeeTableRecords([$rejected]);
    }

    public function test_table_is_sorted_by_newest_creation_date_by_default(): void
    {
        $first = News::factory()->create([
            'title' => 'Oldest article',
            'created_at' => now()->subDays(2),
        ]);

        $second = News::factory()->create([
            'title' => 'Middle article',
            'created_at' => now()->subDay(),
        ]);

        $third = News::factory()->create([
            'title' => 'Newest article',
            'created_at' => now(),
        ]);

        Livewire::test(ListNews::class)
            ->assertCanSeeTableRecords([$third, $second, $first], inOrder: true);
    }

    public function test_table_columns_exist(): void
    {
        $livewireComponent = Livewire::test(ListNews::class);

        foreach (['title', 'category', 'status', 'source_name', 'published_at', 'created_at'] as $column) {
            $livewireComponent->assertTableColumnExists($column);
        }
    }
}
