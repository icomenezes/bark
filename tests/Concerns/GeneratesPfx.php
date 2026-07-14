<?php

namespace Tests\Concerns;

/**
 * Gera um PFX autoassinado real para os testes de certificado/assinatura.
 * O openssl.cnf mínimo é necessário no Windows, onde o PHP não encontra
 * a config padrão do OpenSSL e openssl_csr_new falha silenciosamente.
 */
trait GeneratesPfx
{
    protected function generatePfx(string $password = 'secret', int $validDays = 365): string
    {
        $cnf = tempnam(sys_get_temp_dir(), 'ssl_').'.cnf';
        file_put_contents($cnf, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
        $config = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $key = openssl_pkey_new($config);
        $this->assertNotFalse($key, 'Falha ao gerar chave privada: '.openssl_error_string());

        $csr = openssl_csr_new(['commonName' => 'Teste Casca'], $key, $config);
        $this->assertNotFalse($csr, 'Falha ao gerar CSR: '.openssl_error_string());

        $cert = openssl_csr_sign($csr, null, $key, $validDays, $config);
        $this->assertNotFalse($cert, 'Falha ao autoassinar: '.openssl_error_string());

        $pfx = '';
        openssl_pkcs12_export($cert, $pfx, $key, $password);
        $this->assertNotEmpty($pfx, 'Falha ao exportar PFX: '.openssl_error_string());

        $path = tempnam(sys_get_temp_dir(), 'pfx_').'.pfx';
        file_put_contents($path, $pfx);

        @unlink($cnf);

        return $path;
    }
}
