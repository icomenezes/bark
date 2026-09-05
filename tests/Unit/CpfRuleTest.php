<?php

namespace Tests\Unit;

use App\Rules\Cpf;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CpfRuleTest extends TestCase
{
    public function test_passes_for_a_valid_cpf(): void
    {
        $validator = Validator::make(['cpf' => '529.982.247-25'], ['cpf' => [new Cpf]]);

        $this->assertTrue($validator->passes());
    }

    public function test_fails_for_wrong_check_digits(): void
    {
        $validator = Validator::make(['cpf' => '529.982.247-26'], ['cpf' => [new Cpf]]);

        $this->assertFalse($validator->passes());
        $this->assertSame('Informe um CPF válido.', $validator->errors()->first('cpf'));
    }

    public function test_fails_for_repeated_sequence_that_the_old_regex_accepted(): void
    {
        $validator = Validator::make(['cpf' => '111.111.111-11'], ['cpf' => [new Cpf]]);

        $this->assertFalse($validator->passes());
    }

    public function test_accepts_unformatted_digits(): void
    {
        $validator = Validator::make(['cpf' => '52998224725'], ['cpf' => [new Cpf]]);

        $this->assertTrue($validator->passes());
    }
}
