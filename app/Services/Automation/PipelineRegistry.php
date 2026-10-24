<?php

namespace App\Services\Automation;

use InvalidArgumentException;

class PipelineRegistry
{
    /**
     * @var array<string, list<string>>
     */
    private array $pipelines = [];

    public function __construct()
    {
        foreach ((array) config('automation.pipelines', []) as $name => $stages) {
            if (is_string($name) && is_array($stages)) {
                $this->register($name, $stages);
            }
        }
    }

    /**
     * @param  array<int, string>  $stages
     */
    public function register(string $pipeline, array $stages): void
    {
        $pipeline = trim($pipeline);

        if ($pipeline === '') {
            throw new InvalidArgumentException('Pipeline name cannot be empty.');
        }

        $normalized = [];

        foreach ($stages as $stage) {
            if (! is_string($stage) || trim($stage) === '') {
                throw new InvalidArgumentException('Pipeline stages must be non-empty strings.');
            }

            $normalized[] = trim($stage);
        }

        $this->pipelines[$pipeline] = $normalized;
    }

    public function has(string $pipeline): bool
    {
        return array_key_exists($pipeline, $this->pipelines);
    }

    /**
     * @return list<string>
     */
    public function stages(string $pipeline): array
    {
        return $this->pipelines[$pipeline] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function pipelines(): array
    {
        return $this->pipelines;
    }
}
