<?php

namespace Tests\Unit;

use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution.url' => 'https://evolution.example.com',
            'services.evolution.instance' => 'test-instance',
            'services.evolution.key' => 'test-key',
        ]);
    }

    public function test_adds_ddi_to_11_digit_mobile_number(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        (new WhatsAppService)->send('11999998888', 'oi');

        Http::assertSent(function ($request) {
            return $request['number'] === '5511999998888';
        });
    }

    public function test_adds_ddi_to_10_digit_landline_number(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        (new WhatsAppService)->send('1133334444', 'oi');

        Http::assertSent(function ($request) {
            return $request['number'] === '551133334444';
        });
    }

    public function test_does_not_duplicate_ddi_already_present(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        (new WhatsAppService)->send('5511999998888', 'oi');

        Http::assertSent(function ($request) {
            return $request['number'] === '5511999998888';
        });
    }

    public function test_send_with_details_returns_error_on_http_failure(): void
    {
        Http::fake(['*' => Http::response(['message' => 'invalid instance'], 400)]);

        $result = (new WhatsAppService)->sendWithDetails('11999998888', 'oi');

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    public function test_send_with_details_returns_ok_on_success(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = (new WhatsAppService)->sendWithDetails('11999998888', 'oi');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function test_send_with_details_returns_error_when_not_configured(): void
    {
        config(['services.evolution.url' => '']);

        $result = (new WhatsAppService)->sendWithDetails('11999998888', 'oi');

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }
}
