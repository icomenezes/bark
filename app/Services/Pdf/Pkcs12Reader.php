<?php

namespace App\Services\Pdf;

/**
 * Leitor de PFX/PKCS#12 resiliente a certificados legados.
 *
 * O PHP 8.3 usa OpenSSL 3, que removeu RC2-40/3DES-SHA1 do provider padrão —
 * PFX de ACs brasileiras exportados no formato antigo falham em
 * openssl_pkcs12_read com "digital envelope routines::unsupported" MESMO com
 * a senha correta (senha errada dá "mac verify failure", o que permite
 * distinguir os casos).
 *
 * Estratégia: leitura nativa → se falhar por algoritmo não suportado, extrai
 * o conteúdo via CLI openssl (com -legacy quando for OpenSSL 3) e reexporta
 * em formato moderno via openssl_pkcs12_export. O chamador deve persistir
 * normalizedContent() para não depender do CLI nas próximas leituras.
 */
class Pkcs12Reader
{
    private bool $wrongPassword = false;

    private bool $converted = false;

    private ?string $normalized = null;

    /** @var string[] */
    private array $errors = [];

    private static ?string $binary = null;

    /** Lê o PFX e devolve o array do openssl_pkcs12_read (cert, pkey, extracerts) ou null. */
    public function read(string $content, string $password): ?array
    {
        $this->wrongPassword = false;
        $this->converted = false;
        $this->normalized = null;
        $this->errors = [];

        $p12 = [];
        if (openssl_pkcs12_read($content, $p12, $password)) {
            $this->normalized = $content;

            return $p12;
        }

        $unsupported = false;
        while ($e = openssl_error_string()) {
            $this->errors[] = $e;
            if (str_contains($e, 'mac verify failure')) {
                $this->wrongPassword = true;
            }
            if (str_contains($e, 'unsupported')) {
                $unsupported = true;
            }
        }

        if ($this->wrongPassword || ! $unsupported) {
            return null;
        }

        // Algoritmo legado: converte via CLI e reexporta moderno
        $modern = $this->convertToModern($content, $password);
        if ($modern === null) {
            return null;
        }

        $p12 = [];
        if (! openssl_pkcs12_read($modern, $p12, $password)) {
            $this->errors[] = 'PFX convertido ainda ilegível: '.(openssl_error_string() ?: 'erro desconhecido');

            return null;
        }

        $this->converted = true;
        $this->normalized = $modern;

        return $p12;
    }

    /** Senha comprovadamente errada (mac verify failure) — não é problema de algoritmo. */
    public function wasWrongPassword(): bool
    {
        return $this->wrongPassword;
    }

    /** true quando o PFX era legado e foi reexportado em formato moderno. */
    public function wasConverted(): bool
    {
        return $this->converted;
    }

    /** Conteúdo do PFX legível nativamente (original ou convertido). Persista este. */
    public function normalizedContent(): ?string
    {
        return $this->normalized;
    }

    /** @return string[] erros do openssl/CLI para log */
    public function errors(): array
    {
        return $this->errors;
    }

    public function conversionAvailable(): bool
    {
        return self::binary() !== null;
    }

    // ─── Conversão via CLI ────────────────────────────────────────────────────

    /** Extrai PEM com o CLI openssl e reexporta como PKCS#12 moderno (mesma senha). */
    private function convertToModern(string $content, string $password): ?string
    {
        $bin = self::binary();
        if ($bin === null) {
            $this->errors[] = 'CLI openssl não encontrado para converter PFX legado (defina OPENSSL_BIN).';

            return null;
        }

        $pfxFile = (string) tempnam(sys_get_temp_dir(), 'pfx_in_');
        $passFile = (string) tempnam(sys_get_temp_dir(), 'pfx_pw_');
        $errFile = (string) tempnam(sys_get_temp_dir(), 'pfx_err_');
        file_put_contents($pfxFile, $content);
        file_put_contents($passFile, $password);

        try {
            // OpenSSL antigo (≤1.x) lê RC2 sem flag; OpenSSL 3 exige -legacy (provider à parte)
            foreach (['', ' -legacy'] as $legacyFlag) {
                $cmd = escapeshellarg($bin).' pkcs12 -in '.escapeshellarg($pfxFile)
                    .' -passin '.escapeshellarg('file:'.$passFile)
                    .' -nodes'.$legacyFlag
                    .' 2>'.escapeshellarg($errFile);

                exec($cmd, $out, $code);
                $pem = implode("\n", $out);

                if ($code === 0 && str_contains($pem, 'PRIVATE KEY')) {
                    return $this->exportModern($pem, $password);
                }

                $stderr = trim((string) @file_get_contents($errFile));
                if (str_contains($stderr, 'mac verify failure') || str_contains(strtolower($stderr), 'invalid password')) {
                    $this->wrongPassword = true;

                    return null;
                }
                $out = [];
            }

            $this->errors[] = 'CLI openssl não conseguiu extrair o PFX legado: '.trim((string) @file_get_contents($errFile));

            return null;
        } finally {
            @unlink($pfxFile);
            @unlink($passFile);
            @unlink($errFile);
        }
    }

    /** Remonta o PKCS#12 em formato moderno a partir do PEM extraído (chave + certs). */
    private function exportModern(string $pem, string $password): ?string
    {
        if (! preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', $pem, $keyMatch)) {
            $this->errors[] = 'PEM extraído não contém chave privada.';

            return null;
        }
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $certMatches);

        $key = openssl_pkey_get_private($keyMatch[0]);
        if ($key === false || empty($certMatches[0])) {
            $this->errors[] = 'Falha ao interpretar chave/certificados do PEM extraído.';

            return null;
        }

        $clientCert = null;
        $extras = [];
        foreach ($certMatches[0] as $certPem) {
            if ($clientCert === null && openssl_x509_check_private_key($certPem, $key)) {
                $clientCert = $certPem;
            } else {
                $extras[] = $certPem;
            }
        }

        if ($clientCert === null) {
            $this->errors[] = 'Nenhum certificado do PFX corresponde à chave privada.';

            return null;
        }

        $modern = '';
        $args = $extras ? ['extracerts' => $extras] : [];
        if (! openssl_pkcs12_export($clientCert, $modern, $key, $password, $args)) {
            $this->errors[] = 'Falha ao reexportar PKCS#12 moderno: '.(openssl_error_string() ?: 'erro desconhecido');

            return null;
        }

        return $modern;
    }

    /**
     * Localiza o CLI openssl. Nunca cacheia resultado negativo em static
     * (workers de longa duração prenderiam "não encontrado" para sempre).
     */
    private static function binary(): ?string
    {
        if (self::$binary !== null) {
            return self::$binary;
        }

        $configured = config('services.openssl.bin') ?: getenv('OPENSSL_BIN');
        if ($configured && file_exists($configured)) {
            return self::$binary = $configured;
        }

        $isWin = DIRECTORY_SEPARATOR === '\\';

        $cmd = $isWin ? 'where openssl 2>NUL' : 'which openssl 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code === 0 && ! empty($out[0]) && file_exists(trim($out[0]))) {
            return self::$binary = trim($out[0]);
        }

        if ($isWin) {
            $candidates = [
                'C:\\Program Files\\Git\\usr\\bin\\openssl.exe',
                'C:\\Program Files\\Git\\mingw64\\bin\\openssl.exe',
                'C:\\Program Files (x86)\\GnuWin32\\bin\\openssl.exe',
            ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return self::$binary = $candidate;
                }
            }
        }

        return null;
    }
}
