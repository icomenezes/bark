<?php

namespace Tests\Unit;

use App\Support\ColorShade;
use Tests\TestCase;

class ColorShadeTest extends TestCase
{
    public function test_to_rgb_parses_hex(): void
    {
        $this->assertSame([12, 15, 24], ColorShade::toRgb('#0c0f18'));
    }

    public function test_to_rgb_parses_hex_without_hash(): void
    {
        $this->assertSame([12, 15, 24], ColorShade::toRgb('0c0f18'));
    }

    public function test_lighten_moves_channels_toward_white(): void
    {
        $lightened = ColorShade::lighten('#0c0f18', 0.5);

        $this->assertSame('#86878c', $lightened);
    }

    public function test_lighten_zero_amount_returns_original(): void
    {
        $this->assertSame('#0c0f18', ColorShade::lighten('#0c0f18', 0));
    }

    public function test_lighten_full_amount_returns_white(): void
    {
        $this->assertSame('#ffffff', ColorShade::lighten('#0c0f18', 1));
    }
}
