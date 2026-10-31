<?php

namespace App\Services\Avatar;

/**
 * Generates character specifications deterministically from an AvatarPrng seed,
 * validates against schema enums, and computes canonical fingerprints and distance metrics.
 */
final class CharacterGenerator
{
    /**
     * Generate a validated CharacterSpec deterministically from a uint32 seed.
     *
     * @return array<string, string>
     */
    public function generateFromSeed(int $seed): array
    {
        $prng = new AvatarPrng($seed);
        $spec = CharacterSpec::generate($prng);

        $validation = CharacterSpec::validate($spec);
        if (! $validation['ok']) {
            throw new \InvalidArgumentException('CharacterSpec validation failed: '.implode('; ', $validation['errors']));
        }

        return $spec;
    }

    /**
     * Compute a canonical SHA-256 fingerprint for a character specification.
     * Keys are sorted alphabetically to ensure byte-identical hashes across platforms.
     */
    public static function fingerprint(array $spec): string
    {
        ksort($spec);

        return hash('sha256', (string) json_encode($spec, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Calculate the category difference count between two CharacterSpecs.
     * Returns an integer representing how many visual categories differ.
     */
    public static function categoryDistance(array $specA, array $specB): int
    {
        $differences = 0;
        $keys = array_unique(array_merge(array_keys($specA), array_keys($specB)));

        foreach ($keys as $key) {
            $valA = $specA[$key] ?? null;
            $valB = $specB[$key] ?? null;

            if ($valA !== $valB) {
                $differences++;
            }
        }

        return $differences;
    }
}
