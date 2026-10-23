<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Models\News;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewsDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_news_table_has_all_required_columns(): void
    {
        $columns = DB::select('PRAGMA table_info(news)');

        $columnNames = collect($columns)
            ->pluck('name')
            ->all();

        $this->assertContains('id', $columnNames);
        $this->assertContains('title', $columnNames);
        $this->assertContains('slug', $columnNames);
        $this->assertContains('content', $columnNames);
        $this->assertContains('summary', $columnNames);
        $this->assertContains('source_name', $columnNames);
        $this->assertContains('source_url', $columnNames);
        $this->assertContains('image', $columnNames);
        $this->assertContains('category', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('published_at', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);
    }

    public function test_news_table_enforces_unique_slug(): void
    {
        $indexes = DB::select('PRAGMA index_list(news)');

        $uniqueIndexes = collect($indexes)
            ->filter(fn ($index) => (bool) $index->unique)
            ->pluck('name')
            ->all();

        $this->assertContains('news_slug_unique', $uniqueIndexes);
    }

    public function test_news_table_applies_database_defaults(): void
    {
        News::query()->create([
            'title' => 'Default values article',
            'slug' => 'default-values-article',
        ]);

        $record = DB::table('news')->first();

        $this->assertSame('technology', $record->category);
        $this->assertSame('draft', $record->status);
    }

    public function test_news_record_can_be_created(): void
    {
        $news = News::query()->create([
            'title' => 'Laravel 12 released',
            'slug' => 'laravel-12-released',
            'content' => 'Laravel 12 has been released with new features.',
            'summary' => 'Laravel 12 release notes.',
            'source_name' => 'Laravel News',
            'source_url' => 'https://laravel-news.com',
            'image' => 'news/images/example.png',
            'category' => 'technology',
            'status' => 'ready',
            'published_at' => now(),
        ]);

        $this->assertDatabaseHas('news', [
            'slug' => 'laravel-12-released',
            'status' => 'ready',
            'source_name' => 'Laravel News',
            'category' => 'technology',
        ]);

        $this->assertSame('Laravel 12 released', $news->title);
        $this->assertModelExists($news);
    }

    public function test_news_record_can_be_edited(): void
    {
        $news = News::factory()->create([
            'title' => 'Original title',
        ]);

        $news->update([
            'title' => 'Updated title',
            'slug' => 'updated-title',
        ]);

        $news->refresh();

        $this->assertSame('Updated title', $news->title);
        $this->assertSame('updated-title', $news->slug);

        $this->assertDatabaseHas('news', [
            'id' => $news->id,
            'title' => 'Updated title',
            'slug' => 'updated-title',
        ]);
    }

    public function test_news_record_can_be_deleted(): void
    {
        $news = News::factory()->create();

        $news->delete();

        $this->assertModelMissing($news);
        $this->assertDatabaseCount('news', 0);
    }

    public function test_duplicate_slug_is_rejected_by_the_database(): void
    {
        News::query()->create([
            'title' => 'First article',
            'slug' => 'same-slug',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        News::query()->create([
            'title' => 'Second article',
            'slug' => 'same-slug',
        ]);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $news = News::factory()->create([
            'status' => 'ready',
        ]);

        $news->refresh();

        $this->assertSame(NewsStatus::Ready, $news->status);
        $this->assertTrue($news->status instanceof NewsStatus);

        $this->assertDatabaseHas('news', [
            'id' => $news->id,
            'status' => 'ready',
        ]);
    }

    public function test_published_at_is_cast_to_datetime(): void
    {
        $news = News::factory()->create([
            'published_at' => '2025-01-15 10:30:00',
        ]);

        $news->refresh();

        $this->assertInstanceOf(CarbonInterface::class, $news->published_at);
        $this->assertSame('2025-01-15 10:30:00', $news->published_at->format('Y-m-d H:i:s'));
    }
}
