<?php

namespace Tests\Feature;

use App\Models\SignedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignedDocumentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_code_is_unique(): void
    {
        SignedDocument::factory()->create(['verification_code' => 'dup-code']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        SignedDocument::factory()->create(['verification_code' => 'dup-code']);
    }

    public function test_envelope_verification_code_is_generated_by_migration_backfill(): void
    {
        $envelope = \App\Models\Envelope::factory()->create();

        $this->assertNotEmpty($envelope->verification_code);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($envelope->verification_code));
    }
}
