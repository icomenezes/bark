<?php

namespace Tests\Unit;

use App\Rules\WebhookUrl;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\FakesWebhookDns;
use Tests\TestCase;

class WebhookUrlRuleTest extends TestCase
{
    use FakesWebhookDns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWebhookDns([
            'ponto.example.com' => ['93.184.216.34'],
            'api.cliente.com.br' => ['200.160.2.3', '2001:12ff::10'],
            'interno.example.com' => ['10.0.0.5'],
            'misto.example.com' => ['93.184.216.34', '127.0.0.1'],
            'loopback6.example.com' => ['::1'],
        ]);
    }

    public function test_rejects_domains_that_resolve_to_internal_addresses(): void
    {
        // Domínio público apontando para IP interno: só a resolução do DNS pega.
        $this->assertFalse($this->passes('https://interno.example.com/hook'));
        $this->assertFalse($this->passes('https://misto.example.com/hook'));
        $this->assertFalse($this->passes('https://loopback6.example.com/hook'));
    }

    public function test_rejects_domains_that_do_not_resolve(): void
    {
        $this->assertFalse($this->passes('https://nao-existe.example.com/hook'));
    }

    private function passes(string $url): bool
    {
        return Validator::make(['url' => $url], ['url' => [new WebhookUrl]])->passes();
    }

    public function test_accepts_public_https_urls(): void
    {
        $this->assertTrue($this->passes('https://ponto.example.com/webhooks/assinador'));
        $this->assertTrue($this->passes('https://api.cliente.com.br:8443/hook?conta=7'));
        $this->assertTrue($this->passes('https://8.8.8.8/hook'));
    }

    public function test_rejects_non_https_outside_local(): void
    {
        $this->assertFalse($this->passes('http://ponto.example.com/hook'));
        $this->assertFalse($this->passes('ftp://ponto.example.com/hook'));
        $this->assertFalse($this->passes('não é uma url'));
    }

    public function test_rejects_local_and_internal_hosts(): void
    {
        foreach ([
            'https://localhost/hook',
            'https://app.localhost/hook',
            'https://127.0.0.1/hook',
            'https://10.0.0.5/hook',
            'https://192.168.1.10/hook',
            'https://172.16.0.1/hook',
            'https://169.254.169.254/latest/meta-data',
            'https://[::1]/hook',
            'https://2130706433/hook',
            'https://127.1/hook',
            'https://intranet/hook',
        ] as $url) {
            $this->assertFalse($this->passes($url), "Deveria recusar {$url}");
        }
    }

    public function test_rejects_urls_longer_than_2048_characters(): void
    {
        $this->assertFalse($this->passes('https://ponto.example.com/'.str_repeat('a', 2048)));
    }

    public function test_local_environment_accepts_http_and_localhost(): void
    {
        $this->app['env'] = 'local';

        $this->assertTrue($this->passes('http://localhost:8000/webhooks/assinador'));
        $this->assertTrue($this->passes('http://127.0.0.1/hook'));
        $this->assertFalse($this->passes('não é uma url'));
    }
}
