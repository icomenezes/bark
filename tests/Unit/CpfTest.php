<?php

namespace Tests\Unit;

use App\Support\Cpf;
use Tests\TestCase;

class CpfTest extends TestCase
{
    public function test_digits_strips_formatting(): void
    {
        $this->assertSame('52998224725', Cpf::digits('529.982.247-25'));
        $this->assertSame('52998224725', Cpf::digits('52998224725'));
    }

    public function test_digits_handles_null_and_empty(): void
    {
        $this->assertSame('', Cpf::digits(null));
        $this->assertSame('', Cpf::digits(''));
    }

    public function test_is_valid_accepts_real_cpf(): void
    {
        $this->assertTrue(Cpf::isValid('529.982.247-25'));
        $this->assertTrue(Cpf::isValid('52998224725'));
        $this->assertTrue(Cpf::isValid('111.444.777-35'));
    }

    public function test_is_valid_rejects_wrong_check_digits(): void
    {
        $this->assertFalse(Cpf::isValid('529.982.247-26'));
        $this->assertFalse(Cpf::isValid('111.444.777-30'));
    }

    /** Sequências repetidas passam no cálculo do DV, então precisam de rejeição explícita. */
    public function test_is_valid_rejects_repeated_sequences(): void
    {
        foreach (range(0, 9) as $digit) {
            $this->assertFalse(Cpf::isValid(str_repeat((string) $digit, 11)), "aceitou {$digit} repetido");
        }
    }

    public function test_is_valid_rejects_wrong_length_null_and_empty(): void
    {
        $this->assertFalse(Cpf::isValid('5299822472'));
        $this->assertFalse(Cpf::isValid('529982247250'));
        $this->assertFalse(Cpf::isValid(null));
        $this->assertFalse(Cpf::isValid(''));
    }

    public function test_format_applies_the_mask(): void
    {
        $this->assertSame('529.982.247-25', Cpf::format('52998224725'));
        $this->assertSame('529.982.247-25', Cpf::format('529.982.247-25'));
    }

    public function test_format_returns_input_unchanged_when_not_eleven_digits(): void
    {
        $this->assertSame('123', Cpf::format('123'));
    }

    public function test_mask_hides_the_first_three_and_last_two_digits(): void
    {
        $this->assertSame('***.982.247-**', Cpf::mask('529.982.247-25'));
        $this->assertSame('***.982.247-**', Cpf::mask('52998224725'));
    }

    public function test_mask_never_leaks_digits_of_malformed_input(): void
    {
        $this->assertSame('***.***.***-**', Cpf::mask('123'));
        $this->assertSame('***.***.***-**', Cpf::mask(''));
    }
}
