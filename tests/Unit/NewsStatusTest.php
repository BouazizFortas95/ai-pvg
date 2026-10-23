<?php

namespace Tests\Unit;

use App\Enums\NewsStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NewsStatusTest extends TestCase
{
    public function test_enum_returns_expected_statuses(): void
    {
        $expected = ['draft', 'ready', 'processed', 'rejected'];

        $values = array_column(NewsStatus::cases(), 'value');

        $this->assertSame($expected, $values);
    }

    public function test_enum_can_be_constructed_from_strings(): void
    {
        foreach (['draft', 'ready', 'processed', 'rejected'] as $value) {
            $this->assertSame($value, (NewsStatus::from($value))->value);
        }
    }

    public static function statusLabelsProvider(): array
    {
        return [
            'draft' => ['draft', 'Draft'],
            'ready' => ['ready', 'Ready'],
            'processed' => ['processed', 'Processed'],
            'rejected' => ['rejected', 'Rejected'],
        ];
    }

    #[DataProvider('statusLabelsProvider')]
    public function test_labels_are_consistent(string $value, string $expectedLabel): void
    {
        $this->assertSame($expectedLabel, NewsStatus::from($value)->getLabel());
    }

    public static function statusColorsProvider(): array
    {
        return [
            'draft' => ['draft', 'gray'],
            'ready' => ['ready', 'success'],
            'processed' => ['processed', 'info'],
            'rejected' => ['rejected', 'danger'],
        ];
    }

    #[DataProvider('statusColorsProvider')]
    public function test_colors_are_consistent(string $value, string $expectedColor): void
    {
        $this->assertSame($expectedColor, NewsStatus::from($value)->getColor());
    }
}
