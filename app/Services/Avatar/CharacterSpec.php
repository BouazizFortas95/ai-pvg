<?php

namespace App\Services\Avatar;

/**
 * CharacterSpec — schema definition and validator for a generated avatar.
 *
 * Curated value-enums prevent arbitrary or copyrighted shapes.
 * No real-person likeness is permitted; only stylised originals.
 */
final class CharacterSpec
{
    public const HEAD_SHAPES = ['oval', 'round', 'square', 'heart', 'diamond'];

    public const SKIN_TONES = ['#FDDBB4', '#F0C27F', '#D4956A', '#A8634E', '#5C3217', '#F5C6A0'];

    public const HAIR_STYLES = ['short_neat', 'medium_wavy', 'long_straight', 'curly_afro', 'buzzcut', 'bun_high', 'bun_low', 'braids'];

    public const HAIR_COLORS = ['#1A0A00', '#3B1F0A', '#6B3A2A', '#C17F3B', '#D4AF37', '#E8D5B7', '#F5F5F5', '#8B0000', '#4A0080'];

    public const EYE_STYLES = ['almond', 'round', 'narrow', 'wide_set', 'hooded'];

    public const BROW_STYLES = ['thin_arched', 'thick_flat', 'bushy', 'tapered'];

    public const MOUTH_STYLES = ['full', 'thin', 'wide', 'small_bow'];

    public const GLASSES = ['none', 'round', 'rectangular', 'cat_eye', 'half_rim'];

    public const OUTFITS = ['suit_dark', 'suit_light', 'casual_tshirt', 'casual_hoodie', 'blazer_open', 'collarless_jacket'];

    public const PALETTES = ['dark_pro', 'light_editorial', 'neon_tech', 'warm_studio', 'cool_blue'];

    public const PROPORTIONS = ['average', 'tall_slim', 'petite', 'broad_shoulder'];

    public const VOICE_PROFILES = ['neutral_en', 'warm_en', 'crisp_en', 'deep_en'];

    /** @return array{ok: bool, errors: list<string>} */
    public static function validate(array $spec): array
    {
        $errors = [];

        $enums = [
            'head_shape' => self::HEAD_SHAPES,
            'skin_tone' => self::SKIN_TONES,
            'hair_style' => self::HAIR_STYLES,
            'hair_color' => self::HAIR_COLORS,
            'eye_style' => self::EYE_STYLES,
            'brow_style' => self::BROW_STYLES,
            'mouth_style' => self::MOUTH_STYLES,
            'glasses' => self::GLASSES,
            'outfit' => self::OUTFITS,
            'palette' => self::PALETTES,
            'body_proportions' => self::PROPORTIONS,
            'voice_profile' => self::VOICE_PROFILES,
        ];

        foreach ($enums as $field => $allowed) {
            if (! isset($spec[$field])) {
                $errors[] = "Missing required field: {$field}";
            } elseif (! in_array($spec[$field], $allowed, true)) {
                $errors[] = "Invalid value for {$field}: '{$spec[$field]}'. Allowed: ".implode(', ', $allowed);
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Generate a CharacterSpec deterministically from an AvatarPrng instance.
     */
    public static function generate(AvatarPrng $prng): array
    {
        return [
            'head_shape' => $prng->pick(self::HEAD_SHAPES),
            'skin_tone' => $prng->pick(self::SKIN_TONES),
            'hair_style' => $prng->pick(self::HAIR_STYLES),
            'hair_color' => $prng->pick(self::HAIR_COLORS),
            'eye_style' => $prng->pick(self::EYE_STYLES),
            'brow_style' => $prng->pick(self::BROW_STYLES),
            'mouth_style' => $prng->pick(self::MOUTH_STYLES),
            'glasses' => $prng->pick(self::GLASSES),
            'outfit' => $prng->pick(self::OUTFITS),
            'palette' => $prng->pick(self::PALETTES),
            'body_proportions' => $prng->pick(self::PROPORTIONS),
            'voice_profile' => $prng->pick(self::VOICE_PROFILES),
        ];
    }
}
