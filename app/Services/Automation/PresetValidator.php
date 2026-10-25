<?php

namespace App\Services\Automation;

use App\Enums\PresetFormat;
use RuntimeException;

class PresetValidator
{
    public function validatePreset(string $preset): PresetFormat
    {
        try {
            $enum = PresetFormat::from($preset);
        } catch (\ValueError) {
            throw new RuntimeException('Unknown preset: '.$preset);
        }
        $res = $enum->resolution();
        if ($res['width'] < 1 || $res['height'] < 1) {
            throw new RuntimeException('Invalid resolution for preset: '.$preset);
        }

        return $enum;
    }
}
