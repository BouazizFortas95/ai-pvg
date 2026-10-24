<?php

namespace Tests\Feature;

use App\Models\NewsSource;
use App\Services\AI\ScriptPipeline\ScriptSourceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsScriptFixtures;
use Tests\TestCase;

class ScriptSourceFactoryTest extends TestCase
{
    use BuildsScriptFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_cluster_source_uses_windows_key_and_strips_ungrounded_claims(): void
    {
        $cluster = $this->makeScriptCluster();
        $validId = (int) $cluster->news->first()->getKey();
        $factSheet = $cluster->fact_sheet;
        $factSheet['claims'][] = [
            'claim' => 'A fabricated claim that is not tied to any member.',
            'source_news_ids' => [99999],
        ];
        $cluster->forceFill(['fact_sheet' => $factSheet])->save();

        $source = app(ScriptSourceFactory::class)->fromModel($cluster->fresh(['news.source']));

        $this->assertSame('cluster-'.$cluster->getKey().':g03-v1', $source->windowKey);
        $this->assertSame('cluster-'.$cluster->getKey(), $source->sourceKey);
        $this->assertSame(3, $source->sourceCount());
        $this->assertContains($validId, $source->newsIds());

        foreach ($source->claims as $claim) {
            $this->assertNotSame('A fabricated claim that is not tied to any member.', $claim['claim']);
            $this->assertNotEmpty($claim['source_news_ids']);
        }
    }

    public function test_single_news_source_uses_news_window_and_explicit_ids(): void
    {
        $news = $this->makeNewsWithSource(
            'Single article window',
            NewsSource::factory()->create(),
            ['summary' => 'Single article summary.'],
        );

        $source = app(ScriptSourceFactory::class)->fromModel($news, 30, 'ar');

        $this->assertSame('news-'.$news->getKey().':g03-v1', $source->windowKey);
        $this->assertSame([(int) $news->getKey()], $source->newsIds());
        $this->assertSame(30, $source->targetDuration);
        $this->assertSame('ar', $source->language);
        $this->assertSame([(int) $news->getKey()], $source->claims[0]['source_news_ids']);
    }
}
