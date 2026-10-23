<?php

use App\Models\News;
use App\Services\NewsLegacyBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            // Nullable foreign keys: existing records keep working and
            // are linked during the backfill below.
            $table->foreignId('news_category_id')
                ->nullable()
                ->constrained('news_categories')
                ->nullOnDelete();
            $table->foreignId('news_source_id')
                ->nullable()
                ->constrained('news_sources')
                ->nullOnDelete();

            // Normalized lookup values used for duplicate detection
            // without locking legacy columns or rejecting blank URLs.
            $table->string('normalized_source_url')->nullable()->index();
            $table->string('normalized_title')->nullable()->index();
        });

        app(NewsLegacyBackfillService::class)->run();

        $this->createPartialUniqueIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropForeign(['news_category_id']);
            $table->dropForeign(['news_source_id']);
            $table->dropIndex(['normalized_source_url']);
            $table->dropIndex(['normalized_title']);
            $table->dropColumn(['news_category_id', 'news_source_id', 'normalized_source_url', 'normalized_title']);
        });
    }

    /**
     * Enforce duplicate-safe writes for fresh, verified data.
     *
     * SQLite supports partial indexes: uniqueness applies only to
     * non-null values, so articles without a source URL (or without a
     * source) never collide. Before creating the indexes we verify the
     * existing data contains no duplicates — if conflicts exist, the
     * indexes are skipped (existing duplicates are reported, never
     * deleted) and the application falls back to service-level checks.
     */
    protected function createPartialUniqueIndexes(): void
    {
        $urlDuplicates = News::query()
            ->whereNotNull('normalized_source_url')
            ->orderBy('normalized_source_url')
            ->get('normalized_source_url')
            ->groupBy('normalized_source_url')
            ->filter(fn ($group) => $group->count() > 1);

        if ($urlDuplicates->isNotEmpty()) {
            $this->reportConflicts('normalized source URL', $urlDuplicates->keys());

            return;
        }

        $titleDuplicates = News::query()
            ->whereNotNull('news_source_id')
            ->whereNotNull('normalized_title')
            ->get()
            ->groupBy(fn ($article) => $article->news_source_id.'|'.$article->normalized_title)
            ->filter(fn ($group) => $group->count() > 1);

        if ($titleDuplicates->isNotEmpty()) {
            $this->reportConflicts('source + normalized title', $titleDuplicates->keys());

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX news_normalized_source_url_unique '
            .'ON news (normalized_source_url) WHERE normalized_source_url IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX news_source_normalized_title_unique '
            .'ON news (news_source_id, normalized_title) '
            .'WHERE news_source_id IS NOT NULL AND normalized_title IS NOT NULL'
        );
    }

    /**
     * @param  iterable<mixed>  $keys
     */
    protected function reportConflicts(string $type, iterable $keys): void
    {
        foreach ($keys as $key) {
            report("Duplicate-detection index [{$type}] was not created: existing duplicates found for [{$key}].");
        }
    }
};
