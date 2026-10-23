<?php

namespace Tests\Feature;

use App\Filament\Resources\News\Pages\CreateNews;
use App\Filament\Resources\News\Pages\EditNews;
use App\Filament\Resources\NewsCategories\Pages\CreateNewsCategory;
use App\Filament\Resources\NewsCategories\Pages\EditNewsCategory;
use App\Filament\Resources\NewsCategories\Pages\ListNewsCategories;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NewsCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_category_can_be_created_through_the_form(): void
    {
        Livewire::test(CreateNewsCategory::class)
            ->fillForm([
                'name' => 'Artificial Intelligence',
                'description' => 'AI related articles.',
                'color' => '#ef4444',
                'icon' => 'heroicon-o-cpu-chip',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news_categories', [
            'name' => 'Artificial Intelligence',
            'slug' => 'artificial-intelligence',
            'is_active' => true,
        ]);
    }

    public function test_category_can_be_updated_through_the_form(): void
    {
        $category = NewsCategory::factory()->create([
            'name' => 'Before',
            'slug' => 'before',
            'is_active' => true,
        ]);

        Livewire::test(EditNewsCategory::class, ['record' => $category->getKey()])
            ->fillForm([
                'name' => 'After',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news_categories', [
            'id' => $category->getKey(),
            'name' => 'After',
            'slug' => 'after',
            'is_active' => false,
        ]);
    }

    public function test_category_slug_is_auto_generated_from_the_name(): void
    {
        Livewire::test(CreateNewsCategory::class)
            ->fillForm([
                'name' => 'Machine Learning Advances',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('news_categories', [
            'name' => 'Machine Learning Advances',
            'slug' => 'machine-learning-advances',
        ]);
    }

    public function test_category_slug_must_be_unique(): void
    {
        NewsCategory::factory()->create([
            'slug' => 'taken-slug',
        ]);

        Livewire::test(CreateNewsCategory::class)
            ->fillForm([
                'name' => 'Another category',
                'slug' => 'taken-slug',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        // The migration-time backfill seeds the "Uncategorized" fallback
        // category, so only assert that the colliding slug was not re-used.
        $this->assertSame(1, NewsCategory::query()->where('slug', 'taken-slug')->count());
    }

    public function test_category_name_is_required(): void
    {
        Livewire::test(CreateNewsCategory::class)
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_category_with_articles_cannot_be_deleted(): void
    {
        $category = NewsCategory::factory()->create();

        News::factory()->for($category, 'category')->create();

        try {
            $category->delete();

            $this->fail('Expected the deletion of a category holding articles to be blocked.');
        } catch (\DomainException) {
            // Expected: deletions of categories that own articles are rejected.
        }

        $this->assertDatabaseHas('news_categories', ['id' => $category->getKey()]);
    }

    public function test_unused_category_can_be_deleted_from_the_table(): void
    {
        $category = NewsCategory::factory()->create();

        Livewire::test(ListNewsCategories::class)
            ->callTableAction('delete', $category);

        $this->assertDatabaseMissing('news_categories', [
            'id' => $category->getKey(),
        ]);
    }

    public function test_category_table_lists_all_categories(): void
    {
        NewsCategory::factory()->count(3)->create();

        Livewire::test(ListNewsCategories::class)
            ->assertCanSeeTableRecords(NewsCategory::query()->get());
    }

    public function test_category_active_status_is_toggled_via_the_form(): void
    {
        $category = NewsCategory::factory()->create([
            'is_active' => false,
        ]);

        Livewire::test(EditNewsCategory::class, ['record' => $category->getKey()])
            ->fillForm([
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_inactive_current_category_stays_visible_when_editing_an_article(): void
    {
        $category = NewsCategory::factory()->inactive()->create();

        $news = News::factory()->for($category, 'category')->create();

        $livewire = Livewire::test(EditNews::class, ['record' => $news->getKey()]);

        $options = collect(
            $livewire->instance()->form->getFlatFields(withHidden: true)['news_category_id']->getOptionsForJs(),
        );

        $this->assertContains((string) $category->getKey(), $options->pluck('value'));
    }

    public function test_only_active_categories_are_selectable_for_new_articles(): void
    {
        $activeCategory = NewsCategory::factory()->create(['is_active' => true]);
        $inactiveCategory = NewsCategory::factory()->inactive()->create();

        $livewire = Livewire::test(CreateNews::class);

        $options = collect(
            $livewire->instance()->form->getFlatFields(withHidden: true)['news_category_id']->getOptionsForJs(),
        );

        $values = $options->pluck('value');

        $this->assertContains((string) $activeCategory->getKey(), $values, 'The active category should be listed.');
        $this->assertNotContains(
            (string) $inactiveCategory->getKey(),
            $values,
            'The inactive category should not be listed for new assignments.',
        );
    }
}
