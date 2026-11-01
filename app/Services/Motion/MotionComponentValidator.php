<?php

namespace App\Services\Motion;

use App\Enums\MotionCategory;
use App\Exceptions\Motion\MotionValidationException;

class MotionComponentValidator
{
    public const MAX_TOTAL_BYTES = 102400; // 100 KB cap

    public const MIN_CONTRAST_RATIO = 4.5; // WCAG AA minimum

    public const VALID_FORMATS = ['9:16', '16:9', '1:1'];

    /**
     * Forbidden APIs that violate offline determinism and sandbox isolation.
     *
     * @var array<string, string>
     */
    protected const FORBIDDEN_PATTERNS = [
        '/\bfetch\s*\(/i' => 'Forbidden network API: fetch() is not allowed in offline components',
        '/\bXMLHttpRequest\b/i' => 'Forbidden network API: XMLHttpRequest is not allowed',
        '/\bWebSocket\b/i' => 'Forbidden network API: WebSocket is not allowed',
        '/https?:\/\//i' => 'Forbidden external URL: components must be fully self-contained offline',
        '/\bMath\.random\s*\(/i' => 'Non-deterministic API: Math.random() is forbidden; all randomness must use the seeded PRNG',
        '/\bDate\.now\s*\(/i' => 'Non-deterministic API: Date.now() is forbidden',
        '/\bnew\s+Date\b/i' => 'Non-deterministic API: new Date() is forbidden',
        '/\bperformance\.now\s*\(/i' => 'Non-deterministic API: performance.now() is forbidden',
        '/\bsetTimeout\s*\(/i' => 'Timer leak API: setTimeout() is forbidden; use GSAP timeline sequencing',
        '/\bsetInterval\s*\(/i' => 'Timer leak API: setInterval() is forbidden; use GSAP timeline sequencing',
        '/\brequestAnimationFrame\s*\(/i' => 'Timer leak API: requestAnimationFrame() is forbidden; use GSAP timeline',
    ];

    /**
     * Validate component structure, offline safety, determinism, and contrast.
     *
     * @param  array<string, mixed>  $componentData
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(array $componentData): array
    {
        $errors = [];

        // 1. Identity & Category Check
        $key = trim((string) ($componentData['component_key'] ?? ''));
        if ($key === '') {
            $errors[] = 'Missing or empty component_key.';
        } elseif (! preg_match('/^[a-z0-9_\-]+$/i', $key)) {
            $errors[] = "Invalid component_key '{$key}': must contain only alphanumeric, dash, or underscore characters.";
        }

        $rawCategory = (string) ($componentData['category'] ?? '');
        if (MotionCategory::tryFrom($rawCategory) === null) {
            $errors[] = "Invalid motion category '{$rawCategory}'.";
        }

        // 2. Code Templates Check
        $html = (string) ($componentData['html_template'] ?? '');
        $gsap = (string) ($componentData['gsap_script'] ?? '');

        if (trim($html) === '') {
            $errors[] = 'html_template cannot be empty.';
        }
        if (trim($gsap) === '') {
            $errors[] = 'gsap_script cannot be empty.';
        }

        $totalBytes = strlen($html) + strlen($gsap);
        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            $errors[] = "Component size ({$totalBytes} bytes) exceeds maximum limit of ".self::MAX_TOTAL_BYTES.' bytes.';
        }

        // 3. Forbidden APIs and Network Isolation Scan
        $combinedCode = $html."\n".$gsap;
        foreach (self::FORBIDDEN_PATTERNS as $pattern => $message) {
            if (preg_match($pattern, $combinedCode)) {
                $errors[] = $message;
            }
        }

        // 4. Supported Formats Check
        $formats = $componentData['supported_formats'] ?? [];
        if (! is_array($formats) || empty($formats)) {
            $errors[] = 'supported_formats must be a non-empty array of aspect ratios.';
        } else {
            foreach ($formats as $fmt) {
                if (! in_array($fmt, self::VALID_FORMATS, true)) {
                    $errors[] = "Invalid format '{$fmt}'. Valid formats are: ".implode(', ', self::VALID_FORMATS);
                }
            }
        }

        // 5. Contrast Rating Check
        if (isset($componentData['contrast_rating'])) {
            $contrast = (float) $componentData['contrast_rating'];
            if ($contrast < self::MIN_CONTRAST_RATIO) {
                $errors[] = "Contrast rating ({$contrast}:1) is below WCAG AA minimum threshold of ".self::MIN_CONTRAST_RATIO.':1.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate and throw if invalid.
     *
     * @param  array<string, mixed>  $componentData
     * @throws MotionValidationException
     */
    public function assertValid(array $componentData): void
    {
        $result = $this->validate($componentData);

        if (! $result['valid']) {
            throw new MotionValidationException($result['errors']);
        }
    }
}

