<?php

namespace App\Enums;

enum MotionCategory: string
{
    case LowerThird = 'lower_third';
    case HeadlineBar = 'headline_bar';
    case Ticker = 'ticker';
    case StatCounter = 'stat_counter';
    case QuoteCard = 'quote_card';
    case MapCallout = 'map_callout';
    case IconCallout = 'icon_callout';
    case KineticType = 'kinetic_type';
    case Transition = 'transition';
    case AnimatedBackground = 'animated_background';
    case Stinger = 'stinger';
    case SourceBadge = 'source_badge';
    case AiDisclosureBadge = 'ai_disclosure_badge';

    public function getLabel(): string
    {
        return match ($this) {
            self::LowerThird => 'Lower Third',
            self::HeadlineBar => 'Headline Bar',
            self::Ticker => 'Breaking News Ticker',
            self::StatCounter => 'Stat Counter / Chart',
            self::QuoteCard => 'Quote Card',
            self::MapCallout => 'Map / Location Callout',
            self::IconCallout => 'Icon Callout',
            self::KineticType => 'Kinetic Typography',
            self::Transition => 'Scene Transition',
            self::AnimatedBackground => 'Animated Background',
            self::Stinger => 'Intro / Outro Stinger',
            self::SourceBadge => 'Source Verification Badge',
            self::AiDisclosureBadge => 'AI Disclosure Badge',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}

