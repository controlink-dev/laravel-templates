<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Codes\BarcodeFailure;
use Controlink\Templates\Codes\Code128Fit;
use Controlink\Templates\Tests\TestCase;

/**
 * Whole-dot arithmetic of Code 128 on a 203 dpi head (8 dots per millimetre).
 */
class Code128FitTest extends TestCase
{
    public function test_modules_of_the_fixture_code(): void
    {
        $this->assertSame(11 * 16 + 13 + 20, Code128Fit::modules('ART-0000000003'));
    }

    public function test_module_dots_is_the_largest_whole_dot_that_fits(): void
    {
        // 209 modules; 640 dots / 209 = 3.06.
        $this->assertSame(3, Code128Fit::moduleDots('ART-0000000003', 80.0));
    }

    public function test_below_two_dots_does_not_fit(): void
    {
        $this->expectException(BarcodeFailure::class);

        Code128Fit::moduleDots('ART-0000000003', 40.0);
    }

    public function test_does_not_fit_failure_carries_the_content(): void
    {
        try {
            Code128Fit::moduleDots('ART-0000000003', 40.0);
            $this->fail('Expected BarcodeFailure.');
        } catch (BarcodeFailure $e) {
            $this->assertSame('ART-0000000003', $e->content);
        }
    }

    public function test_max_chars_at_two_dots(): void
    {
        $this->assertSame(16, Code128Fit::maxChars(60.0));
        $this->assertSame(0, Code128Fit::maxChars(5.0));
    }

    public function test_a_value_of_max_chars_fits_and_one_more_does_not(): void
    {
        $fits = str_repeat('A', Code128Fit::maxChars(60.0));

        $this->assertSame(2, Code128Fit::moduleDots($fits, 60.0));

        $this->expectException(BarcodeFailure::class);
        Code128Fit::moduleDots($fits.'A', 60.0);
    }

    public function test_non_ascii_is_unencodable(): void
    {
        $this->expectException(BarcodeFailure::class);

        Code128Fit::assertEncodable('AÇO-1');
    }

    public function test_empty_and_control_characters_are_unencodable(): void
    {
        foreach (['', "AB\n", "A\tB"] as $value) {
            try {
                Code128Fit::assertEncodable($value);
                $this->fail('Expected BarcodeFailure for '.json_encode($value));
            } catch (BarcodeFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_printable_ascii_is_encodable(): void
    {
        Code128Fit::assertEncodable(' ~ART-0000000003!');

        $this->addToAssertionCount(1);
    }
}
