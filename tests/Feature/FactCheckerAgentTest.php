<?php

namespace Tests\Feature;

use App\Enums\ScriptFactCheckVerdict;
use App\Services\AI\ScriptPipeline\FactCheckerAgent;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FactCheckerAgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['automation.script_agents.claim_coverage_threshold' => 0.30]);
    }

    public function test_claims_receive_supported_uncertain_and_unsupported_verdicts_with_evidence(): void
    {
        $news = [[
            'id' => 1,
            'title' => 'Helios solid-state battery milestone',
            'summary' => 'Helios lab reports solid-state battery milestone 0 with 900 cycles.',
            'content' => 'Independent testers confirmed the cells retained capacity.',
            'source_name' => 'Helios Wire',
            'source_url' => 'https://example.test/helios',
        ]];

        $factSheet = [
            'claims' => [[
                'claim' => 'Helios lab reports solid-state battery milestone 0 with 900 cycles.',
                'source_news_ids' => [1],
            ]],
        ];

        $checked = app(FactCheckerAgent::class)->check([
            [
                'claim' => 'Helios lab reports solid-state battery milestone 0 with 900 cycles.',
                'source_news_ids' => [1],
            ],
            [
                'claim' => 'Battery quantum teleportation Mars',
                'source_news_ids' => [1],
            ],
            [
                'claim' => 'Quantum teleportation achieved on Mars in 2027.',
                'source_news_ids' => [1],
            ],
            [
                'claim' => 'A claim without explicit ids.',
                'source_news_ids' => [],
            ],
            [
                'claim' => 'A claim citing an unrelated id.',
                'source_news_ids' => [999],
            ],
        ], $news, $factSheet);

        $byClaim = collect($checked)->keyBy('claim');

        $this->assertSame(
            ScriptFactCheckVerdict::Supported,
            $byClaim['Helios lab reports solid-state battery milestone 0 with 900 cycles.']['verdict'],
        );
        $this->assertSame(
            ScriptFactCheckVerdict::Uncertain,
            $byClaim['Battery quantum teleportation Mars']['verdict'],
        );
        $this->assertSame(
            ScriptFactCheckVerdict::Unsupported,
            $byClaim['Quantum teleportation achieved on Mars in 2027.']['verdict'],
        );
        $this->assertSame(
            ScriptFactCheckVerdict::Uncertain,
            $byClaim['A claim without explicit ids.']['verdict'],
        );
        $this->assertSame(
            ScriptFactCheckVerdict::Unsupported,
            $byClaim['A claim citing an unrelated id.']['verdict'],
        );

        $this->assertStringContainsString('Helios Wire:', $byClaim['Helios lab reports solid-state battery milestone 0 with 900 cycles.']['evidence']);
        $this->assertSame([1], $byClaim['Battery quantum teleportation Mars']['source_news_ids']);
        $this->assertStringContainsString('not members of the grounded story', $byClaim['A claim citing an unrelated id.']['reason']);
        $this->assertTrue($byClaim['A claim without explicit ids.']['verdict']->blocksApproval());
    }
}
