<?php

namespace App\Contracts;

interface AIProviderInterface
{
    public function generate(string $prompt, array $options = []): string;

    public function supportsStructuredOutput(): bool;

    public function getProviderName(): string;
}
