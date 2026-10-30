<?php

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Enums\VideoRenderStatus;
use App\Enums\VideoScriptStatus;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\NewsScore;
use App\Models\NewsSource;
use App\Models\StoryCluster;
use App\Models\VideoRender;
use App\Services\AI\ScriptPipeline\ScriptPipelineService;
use App\Services\NewsCategoryResolver;
use App\Services\VideoRenderingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seed realistic, multi-source breaking tech news and run the local
 * G-02 triage, G-03 script-agent, and local Remotion rendering pipeline.
 *
 * Everything stays on disk: publishing is disabled, no external AI provider
 * is called, and the rendered MP4 is written to the configured output
 * directory (default storage/app/video_out/).
 */
class SeedMockCluster extends Command
{
    protected $signature = 'ai-pvg:seed-mock-cluster
        {--cluster-size=3 : Number of mock articles to create (3-5)}
        {--skip-triage : Skip running the G-02 triage pipeline after seeding}
        {--skip-script : Skip G-03 script generation after clustering}
        {--skip-render : Skip local video rendering after script approval}
        {--output-dir= : Directory for the rendered MP4 (default storage/app/video_out)}';

    protected $description = 'Seed realistic multi-source breaking tech news and run the local G-02/G-03/render pipeline';

    /**
     * @var list<array{source: string, slug: string, category: string, title: string, summary: string, content: string}>
     */
    private const STORIES = [
        [
            'source' => 'TechCrunch',
            'slug' => 'techcrunch-mock-cluster',
            'category' => 'AI',
            'title' => 'OpenAI launches Nova-1, its most capable AI model yet',
            'summary' => 'Breaking: OpenAI has launched Nova-1, a new flagship AI model that sets record scores on reasoning and coding benchmarks.',
            'content' => 'OpenAI today launched Nova-1, its most capable AI model to date. The launch marks a significant milestone in the AI industry, with Nova-1 achieving record reasoning scores across multiple benchmarks. This exclusive release positions OpenAI at the forefront of the AI model race, and developers can access the model through the API starting today.',
        ],
        [
            'source' => 'The Verge',
            'slug' => 'the-verge-mock-cluster',
            'category' => 'Security',
            'title' => 'Nova-1 AI model release: OpenAI new flagship raises the bar',
            'summary' => 'The Nova-1 AI model from OpenAI launches today with improved reasoning capabilities and record benchmark performance.',
            'content' => 'Breaking: The Nova-1 AI model release from OpenAI has arrived, setting a new bar for AI models. The launch includes enhanced security features and record reasoning capabilities. OpenAI says the new model is a breakthrough for AI safety and security, with built-in protections against common attack vectors.',
        ],
        [
            'source' => 'Ars Technica',
            'slug' => 'ars-technica-mock-cluster',
            'category' => 'Open Source',
            'title' => 'Ars Technica review: Nova-1 sets a new benchmark for AI models',
            'summary' => "OpenAI's Nova-1 AI model sets a new industry benchmark for reasoning and performance after its launch.",
            'content' => 'Breaking: Ars Technica reviews the Nova-1 AI model from OpenAI. The Nova-1 sets a new industry benchmark for reasoning and performance. This exclusive launch includes detailed technical analysis of the model architecture, training data, and the open-source tooling released alongside it.',
        ],
        [
            'source' => 'GitHub Blog',
            'slug' => 'github-blog-mock-cluster',
            'category' => 'Developer Tools',
            'title' => 'Developers build with Nova-1, OpenAI latest AI model',
            'summary' => 'GitHub developers are already integrating the Nova-1 AI model into code-generation workflows after its launch.',
            'content' => 'Breaking: GitHub developers are already integrating the Nova-1 AI model into code-generation workflows after its launch. The new AI model from OpenAI improves developer productivity with better reasoning and code-completion capabilities, and this exclusive launch has been welcomed by the open-source community.',
        ],
        [
            'source' => 'Hacker News',
            'slug' => 'hnrss-org-mock-cluster',
            'category' => 'AI',
            'title' => 'Nova-1 sparks debate: OpenAI releases new AI model with record reasoning',
            'summary' => 'The Nova-1 AI model release has sparked discussion across the community about OpenAI latest launch.',
            'content' => 'Breaking: The Nova-1 AI model release has sparked discussion across the technology community about OpenAI latest launch. The new AI model delivers record reasoning scores and has quickly become the top story, with developers debating its implications for the future of AI.',
        ],
    ];

    public function handle(): int
    {
        $clusterSize = $this->validateClusterSize();
        if ($clusterSize === null) {
            return self::FAILURE;
        }

        $this->configureSafety();

        Http::preventStrayRequests();

        $this->info('AI-PVG mock cluster seeding');
        $this->line('Publishing: disabled | Dry-run: off | Provider calls: local only');
        $this->line('Output directory: '.($this->option('output-dir') ?? storage_path('app/video_out')));
        $this->newLine();

        // 1. Seed mock articles with dynamic categories.
        $news = $this->seedMockArticles($clusterSize);
        $newsIds = collect($news)->map(fn (News $article): int => (int) $article->getKey())->all();

        $this->newLine();

        $cluster = null;

        // 2. Ensure every seeded article has a score in the auto-greenlight band
        // so that G-02 clustering reliably triggers auto-greenlight for the
        // overlapping multi-source story.
        if (! $this->option('skip-triage')) {
            foreach ($news as $article) {
                NewsScore::query()->updateOrCreate(
                    ['news_id' => (int) $article->getKey()],
                    [
                        'velocity_score' => 90.0,
                        'keyword_heat_score' => 90.0,
                        'novelty_score' => 90.0,
                        'source_diversity_score' => 90.0,
                        'recency_score' => 90.0,
                        'composite_score' => 95.0,
                        'band' => 'auto_greenlight',
                        'breakdown' => ['version' => 'g02-mock-seed', 'composite_score' => 95.0],
                        'scored_at' => now(),
                    ],
                );
            }

            $this->line('Running G-02 triage (cluster + auto-greenlight)...');
            $exitCode = Artisan::call('ai-pvg:triage', ['--cluster' => true]);
            if ($exitCode !== 0) {
                $this->error('Triage command failed with exit code '.$exitCode.'.');
            } else {
                $this->info('Triage complete.');
            }

            $cluster = $this->findCluster($newsIds);
            if ($cluster === null) {
                $this->warn('No story cluster was formed containing the seeded articles.');
            } else {
                $this->line(sprintf(
                    'Cluster formed: "%s" (%d sources, %d members).',
                    $cluster->canonical_title,
                    (int) $cluster->source_count,
                    $cluster->news->count()
                ));
            }
        }

        $this->newLine();

        // 3. Run G-03 script pipeline on the cluster.
        $script = null;
        if (! $this->option('skip-script')) {
            if ($cluster === null) {
                $this->warn('Skipping script generation: no cluster available.');
            } else {
                $this->line('Running G-03 script pipeline on cluster...');
                config(['automation.dry_run' => false]);
                FeatureFlag::setFlag('script_agents.enabled', true);
                $result = app(ScriptPipelineService::class)->runPipeline($cluster);
                $script = $result->script;
                if ($result->blocked) {
                    $this->warn('Script pipeline blocked: '.$result->message);
                } else {
                    $this->info('Script pipeline complete: '.$result->message);
                }

                // Approve the generated script so it is eligible for local rendering.
                if ($script !== null && $script->status !== VideoScriptStatus::Approved) {
                    $script->forceFill([
                        'status' => VideoScriptStatus::Approved,
                        'approved_at' => now(),
                    ])->save();
                    $this->line('Script approved for local rendering (ID '.$script->getKey().').');
                }
            }
        }

        $this->newLine();

        // 4. Local video rendering.
        if (! $this->option('skip-render')) {
            if ($script === null) {
                $this->warn('Skipping rendering: no approved script available.');
            } else {
                $this->line('Rendering local MP4...');
                $outputDir = $this->option('output-dir') ?? storage_path('app/video_out');
                config(['remotion.output_dir' => $outputDir]);

                $render = VideoRender::factory()->create([
                    'video_script_id' => $script->getKey(),
                    'status' => VideoRenderStatus::Pending->value,
                    'template' => 'cyber',
                ]);

                try {
                    $rendered = app(VideoRenderingService::class)->render($render);
                    if ($rendered->status === VideoRenderStatus::Completed) {
                        $this->info('Render complete: '.$rendered->output_path);
                    } else {
                        $this->error('Render failed: '.$rendered->error_message);
                    }
                } catch (\Throwable $exception) {
                    $this->error('Rendering threw an exception: '.$exception->getMessage());
                }
            }
        }

        $this->newLine();
        $this->info('Mock cluster seeding done.');
        $this->line(sprintf(
            'Articles: %d | Cluster: %s | Script: %s',
            count($newsIds),
            $cluster !== null ? 'yes ('.$cluster->canonical_title.')' : 'no',
            $script !== null ? 'approved #'.$script->getKey() : 'none'
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, News>
     */
    private function seedMockArticles(int $clusterSize): array
    {
        $now = now();
        $stories = array_slice(self::STORIES, 0, $clusterSize);
        $news = [];

        foreach ($stories as $index => $story) {
            $source = NewsSource::query()->firstOrCreate(
                ['slug' => $story['slug']],
                [
                    'name' => $story['source'],
                    'website_url' => 'https://example.com/'.$story['slug'],
                    'rss_url' => 'https://example.com/'.$story['slug'].'/feed.xml',
                    'description' => 'Mock source for pipeline proofing: '.$story['source'],
                    'language' => 'en',
                    'is_active' => true,
                ]
            );

            $normalizedTitle = Str::slug($story['title']);

            // Idempotent: skip stories that already exist for this source.
            $existing = News::query()
                ->where('news_source_id', $source->getKey())
                ->where('normalized_title', $normalizedTitle)
                ->first();

            if ($existing !== null) {
                $news[] = $existing;

                continue;
            }

            // Resolve the category dynamically through NewsCategoryResolver so
            // unknown labels are auto-created and known labels are reused.
            $category = app(NewsCategoryResolver::class)->resolveArticle(
                itemCategories: [$story['category']],
                title: $story['title'],
                summary: $story['summary'],
                content: $story['content'],
                fallback: config('news_categories.fallback', 'Uncategorized'),
                sourceName: $story['source'],
            );

            $slug = Str::slug($story['title'].'-'.$source->getKey());

            $news[] = News::create([
                'title' => $story['title'],
                'slug' => $slug,
                'summary' => $story['summary'],
                'content' => $story['content'],
                'source_name' => $story['source'],
                'source_url' => 'https://example.com/'.$story['slug'].'/story-'.$index,
                'category' => $category->slug,
                'status' => NewsStatus::Ready->value,
                'published_at' => $now->clone()->subMinutes(($index + 1) * 5),
                'news_category_id' => $category->getKey(),
                'news_source_id' => $source->getKey(),
                'normalized_source_url' => 'https://example.com/'.$story['slug'].'/story-'.$index,
                'normalized_title' => $normalizedTitle,
            ]);
        }

        return $news;
    }

    /**
     * Validate and normalize the cluster size option (must be 3-5).
     * Returns null when the option is invalid.
     */
    private function validateClusterSize(): ?int
    {
        $size = (int) $this->option('cluster-size');

        if ($size < 3 || $size > 5) {
            $this->error('--cluster-size must be between 3 and 5.');

            return null;
        }

        return $size;
    }

    /**
     * Find the story cluster (if any) that contains the seeded articles.
     *
     * @param  list<int>  $newsIds
     */
    private function findCluster(array $newsIds): ?StoryCluster
    {
        if (! Schema::hasTable('story_clusters')) {
            return null;
        }

        return StoryCluster::query()
            ->with(['news.newsScore'])
            ->whereHas('news', function ($query) use ($newsIds): void {
                $query->whereIn('news.id', $newsIds);
            })
            ->orderByDesc('story_clusters.id')
            ->first();
    }

    private function configureSafety(): void
    {
        // Local-only pipeline proofing: writes persist, publishing is off.
        config(['automation.triage.enabled' => true]);
        config(['automation.dry_run' => false]);
        config(['automation.publishing.enabled' => false]);
        config(['automation.script_agents.enabled' => true]);
        config(['automation.script_agents.use_ai' => false]);

        FeatureFlag::setFlag('triage.news_scoring_enabled', true);
        FeatureFlag::setFlag('script_agents.enabled', true);
    }
}
