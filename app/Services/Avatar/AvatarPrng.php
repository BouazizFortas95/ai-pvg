<?php

namespace App\Services\Avatar;

/**
 * Mulberry32 PRNG — deterministic, seedable, integer-space identical to the
 * JavaScript mulberry32() function embedded in presenter compositions.
 *
 * PHP and JS produce the exact same float sequence for any given uint32 seed.
 * NEVER replace with Math::random() or any non-seeded source.
 */
final class AvatarPrng
{
    /** Current state as an unsigned 32-bit integer. */
    private int $state;

    public function __construct(int $seed)
    {
        // Mask to uint32 range to match JS bitwise behaviour.
        $this->state = $seed & 0xFFFFFFFF;
    }

    /**
     * Advance the PRNG and return a float in [0, 1).
     * Algorithm: canonical mulberry32, bit-for-bit identical to JS Math.imul.
     */
    public function next(): float
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;

        $t = $this->state;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t = ($t ^ ($t + self::imul($t ^ ($t >> 7), $t | 61))) & 0xFFFFFFFF;

        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296.0;
    }

    /**
     * Return a random integer in [$min, $max] (inclusive).
     */
    public function nextInt(int $min, int $max): int
    {
        return (int) floor($this->next() * ($max - $min + 1)) + $min;
    }

    /**
     * Pick a random element from a non-empty array.
     *
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->nextInt(0, count($items) - 1)];
    }

    /**
     * Bitwise 32-bit integer multiplication, identical to JavaScript's Math.imul().
     */
    private static function imul(int $a, int $b): int
    {
        $aHigh = ($a >> 16) & 0xFFFF;
        $aLow = $a & 0xFFFF;
        $bHigh = ($b >> 16) & 0xFFFF;
        $bLow = $b & 0xFFFF;

        return (($aLow * $bLow) + ((($aHigh * $bLow + $aLow * $bHigh) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
