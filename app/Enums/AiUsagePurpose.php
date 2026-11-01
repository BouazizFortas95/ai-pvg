<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AiUsagePurpose: string implements HasLabel
{
    case Script = 'script';
    case Score = 'score';
    case Critic = 'critic';
    case Thumbnail = 'thumbnail';
    case Metadata = 'metadata';
    case Audio = 'audio';
    case Tts = 'tts';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Script => 'Script',
            self::Score => 'Score',
            self::Critic => 'Critic',
            self::Thumbnail => 'Thumbnail',
            self::Metadata => 'Metadata',
            self::Audio => 'Audio',
            self::Tts => 'TTS Audio',
        };
    }
}
