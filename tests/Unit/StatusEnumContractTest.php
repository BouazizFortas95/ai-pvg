<?php

namespace Tests\Unit;

use App\Enums\VideoRenderStatus;
use App\Enums\VoiceGenerationStatus;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusEnumContractTest extends TestCase
{
    /**
     * @return array<string, array{class-string<VoiceGenerationStatus|VideoRenderStatus>}>
     */
    public static function statusEnumProvider(): array
    {
        return [
            'voice generation status' => [VoiceGenerationStatus::class],
            'video render status' => [VideoRenderStatus::class],
        ];
    }

    /**
     * @param  class-string<VoiceGenerationStatus|VideoRenderStatus>  $enumClass
     */
    #[DataProvider('statusEnumProvider')]
    public function test_every_case_implements_filament_contracts_with_valid_labels_and_colors(string $enumClass): void
    {
        $cases = $enumClass::cases();

        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertInstanceOf(HasLabel::class, $case);
            $this->assertInstanceOf(HasColor::class, $case);

            $label = $case->getLabel();
            $color = $case->getColor();

            $this->assertIsString($label);
            $this->assertNotSame('', trim($label));

            $this->assertIsString($color);
            $this->assertContains($color, [
                'primary',
                'secondary',
                'success',
                'warning',
                'danger',
                'info',
                'gray',
            ]);
        }
    }
}
