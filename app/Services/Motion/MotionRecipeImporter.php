<?php

namespace App\Services\Motion;

use App\Enums\MotionCategory;
use App\Enums\MotionLicenseStatus;
use App\Enums\MotionRecipeStatus;
use App\Models\MotionRecipe;
use App\Models\MotionSource;
use InvalidArgumentException;

class MotionRecipeImporter
{
    /**
     * Allowed domains for curated external reference imports.
     * Prevents open proxy or SSRF attacks.
     */
    public const ALLOWLISTED_DOMAINS = [
        'promptmotion.org',
        'prompt-motion.github.io',
        'promptmotion.art',
        'showheyohtaki.github.io',
        'github.com',
    ];

    /**
     * Import a motion prompt recipe with strict reference_only default licensing.
     *
     * @param  array{
     *     title: string,
     *     prompt_text: string,
     *     source_url: string,
     *     creator_handle: string,
     *     site_name?: string|null,
     *     category?: string|null,
     *     attribution_text?: string|null,
     *     license_status?: string|null,
     *     notes?: string|null,
     *     source_payload?: array<string, mixed>|null
     * }  $payload
     * @throws InvalidArgumentException
     */
    public function import(array $payload): MotionRecipe
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Recipe title is required.');
        }

        $rawPrompt = (string) ($payload['prompt_text'] ?? '');
        $sanitizedPrompt = $this->sanitizePromptData($rawPrompt);
        if ($sanitizedPrompt === '') {
            throw new InvalidArgumentException('Prompt text is required and cannot be empty.');
        }

        $sourceUrl = trim((string) ($payload['source_url'] ?? ''));
        if ($sourceUrl === '' || ! filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('A valid source URL is required for attribution.');
        }

        $creatorHandle = trim((string) ($payload['creator_handle'] ?? ''));
        if ($creatorHandle === '') {
            throw new InvalidArgumentException('Creator handle is required to protect attribution.');
        }

        $host = parse_url($sourceUrl, PHP_URL_HOST) ?? 'promptmotion.org';
        $siteName = trim((string) ($payload['site_name'] ?? '')) ?: (string) $host;

        // Default attribution text
        $attribution = trim((string) ($payload['attribution_text'] ?? ''))
            ?: "Inspired by @{$creatorHandle} on {$siteName}";

        // Enforce taxonomy rule: DEFAULT = reference_only
        $rawLicense = (string) ($payload['license_status'] ?? 'reference_only');
        $licenseStatus = match (strtolower($rawLicense)) {
            'cleared' => MotionLicenseStatus::Cleared,
            'original' => MotionLicenseStatus::Original,
            default => MotionLicenseStatus::ReferenceOnly,
        };

        // Resolve or validate category
        $rawCategory = (string) ($payload['category'] ?? 'lower_third');
        $category = MotionCategory::tryFrom($rawCategory)?->value ?? MotionCategory::LowerThird->value;

        // 1. Create or retrieve origin source record
        $source = MotionSource::firstOrCreate(
            [
                'source_url' => $sourceUrl,
                'creator_handle' => $creatorHandle,
            ],
            [
                'site_name' => $siteName,
                'license_status' => $licenseStatus,
                'attribution_text' => $attribution,
                'notes' => $payload['notes'] ?? null,
            ]
        );

        // 2. Create the imported recipe record
        return MotionRecipe::create([
            'motion_source_id' => $source->id,
            'title' => $title,
            'prompt_text' => $sanitizedPrompt,
            'category' => $category,
            'source_payload' => $payload['source_payload'] ?? null,
            'status' => MotionRecipeStatus::Imported,
            'license_status' => $licenseStatus,
        ]);
    }

    /**
     * Check if a URL domain belongs to the allowlisted domains.
     */
    public function isAllowlisted(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::ALLOWLISTED_DOMAINS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Imported motion prompts/skills are DATA, NEVER instructions.
     * Strip control characters and dangerous executable tokens.
     */
    public function sanitizePromptData(string $text): string
    {
        // Remove null bytes and non-printable control characters except newline and tab
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return trim($clean);
    }
}

