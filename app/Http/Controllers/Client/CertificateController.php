<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\AccessLogService;
use App\Services\Pdf\Pkcs12Reader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CertificateController extends Controller
{
    public function __construct(private AccessLogService $accessLog) {}

    public function index()
    {
        $certificates = auth()->user()->certificates()->latest()->paginate(20);

        return view('client.certificates.index', compact('certificates'));
    }

    public function create()
    {
        return view('client.certificates.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'description' => ['required', 'string', 'max:250'],
            'reference' => ['nullable', 'string', 'max:15'],
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string', 'max:255'],
            'sign_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'logo_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $pfx = $this->readPfx((string) file_get_contents($request->file('pfx')->getRealPath()), $request->password);

        $certificate = auth()->user()->certificates()->create([
            'description' => $request->description,
            'reference' => $request->reference,
            'pfx_path' => '',
            'password' => $request->password,
            'expires_at' => $pfx['expires_at'],
        ]);

        $certificate->update($this->storeFiles($request, $certificate, $pfx['content']));

        $this->accessLog->log(auth()->user(), 'certificate_created', ['certificate_id' => $certificate->id]);

        return redirect()->route('certificates.index')
            ->with('success', 'Certificado cadastrado com sucesso.');
    }

    public function edit(Certificate $certificate)
    {
        $this->authorizeOwner($certificate);

        return view('client.certificates.edit', compact('certificate'));
    }

    public function update(Request $request, Certificate $certificate)
    {
        $this->authorizeOwner($certificate);

        $request->validate([
            'description' => ['required', 'string', 'max:250'],
            'reference' => ['nullable', 'string', 'max:15'],
            'pfx' => ['nullable', 'file', 'max:5120'],
            'password' => ['nullable', 'string', 'max:255', 'required_with:pfx'],
            'sign_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'logo_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $data = [
            'description' => $request->description,
            'reference' => $request->reference,
        ];
        $pfxContent = null;

        // PFX novo (ou só troca de senha) — revalida contra o arquivo e atualiza a validade
        if ($request->hasFile('pfx')) {
            $pfx = $this->readPfx((string) file_get_contents($request->file('pfx')->getRealPath()), $request->password);
            $data['password'] = $request->password;
            $data['expires_at'] = $pfx['expires_at'];
            $pfxContent = $pfx['content'];
        } elseif ($request->filled('password')) {
            $pfx = $this->readPfx(
                (string) Storage::disk('local')->get($certificate->pfx_path),
                $request->password
            );
            $data['password'] = $request->password;
            $data['expires_at'] = $pfx['expires_at'];
            $pfxContent = $pfx['content'];
        }

        $certificate->update($data + $this->storeFiles($request, $certificate, $pfxContent));

        return redirect()->route('certificates.index')
            ->with('success', 'Certificado atualizado com sucesso.');
    }

    public function destroy(Certificate $certificate)
    {
        $this->authorizeOwner($certificate);

        Storage::disk('local')->deleteDirectory('certificates/'.$certificate->id);
        $certificate->delete();

        $this->accessLog->log(auth()->user(), 'certificate_deleted', ['certificate_id' => $certificate->id]);

        return redirect()->route('certificates.index')->with('success', 'Certificado removido.');
    }

    /** Marca este certificado como o usado para lacrar os próprios envelopes do cliente. */
    public function useAsSigning(Certificate $certificate)
    {
        $this->authorizeOwner($certificate);

        if ($certificate->isExpired()) {
            throw ValidationException::withMessages([
                'certificate' => 'Não é possível usar um certificado vencido para assinatura.',
            ]);
        }

        auth()->user()->update(['signing_certificate_id' => $certificate->id]);

        return redirect()->route('certificates.index')
            ->with('success', 'Certificado definido como padrão de assinatura.');
    }

    /** Serve as imagens privadas (logo/assinatura) para preview no form e na lista. */
    public function image(Certificate $certificate, string $type)
    {
        $this->authorizeOwner($certificate);

        $path = match ($type) {
            'sign' => $certificate->sign_image_path,
            'logo' => $certificate->logo_image_path,
            default => null,
        };

        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    private function authorizeOwner(Certificate $certificate): void
    {
        abort_if($certificate->user_id !== auth()->id(), 403);
    }

    /**
     * Valida a senha contra o PFX e extrai a data de expiração (validTo do X.509).
     * Senha errada nunca chega ao banco. PFX legado (RC2/3DES, ilegível pelo
     * OpenSSL 3) é convertido para o formato moderno — persista 'content'.
     *
     * @return array{expires_at: ?string, content: string}
     */
    private function readPfx(string $pfxContent, string $password): array
    {
        $reader = new Pkcs12Reader;
        $p12 = $reader->read($pfxContent, $password);

        if ($p12 === null) {
            Log::warning('Falha ao ler PFX no cadastro de certificado', [
                'user_id' => auth()->id(),
                'wrong_password' => $reader->wasWrongPassword(),
                'conversion_available' => $reader->conversionAvailable(),
                'openssl_errors' => $reader->errors(),
            ]);

            throw ValidationException::withMessages([
                'password' => $reader->wasWrongPassword()
                    ? 'Senha do PFX incorreta.'
                    : 'O PFX usa algoritmos antigos (RC2/3DES) que o OpenSSL 3 não lê e a conversão automática '
                      .'não está disponível neste servidor. Instale o CLI do OpenSSL (ou defina OPENSSL_BIN) '
                      .'ou reexporte o certificado em formato moderno. Detalhes em storage/logs/laravel.log.',
            ]);
        }

        $info = openssl_x509_parse($p12['cert']) ?: [];

        return [
            'expires_at' => isset($info['validTo_time_t']) ? date('Y-m-d', (int) $info['validTo_time_t']) : null,
            'content' => (string) $reader->normalizedContent(),
        ];
    }

    /** Grava PFX (conteúdo já normalizado) e imagens em certificates/{id}/ no disk local. */
    private function storeFiles(Request $request, Certificate $certificate, ?string $pfxContent = null): array
    {
        $dir = 'certificates/'.$certificate->id;
        $data = [];

        if ($pfxContent !== null) {
            $data['pfx_path'] = $dir.'/certificate.pfx';
            Storage::disk('local')->put($data['pfx_path'], $pfxContent);
        }
        if ($request->hasFile('sign_image')) {
            $ext = $request->file('sign_image')->extension();
            $data['sign_image_path'] = $request->file('sign_image')->storeAs($dir, 'sign_image.'.$ext, 'local');
        }
        if ($request->hasFile('logo_image')) {
            $ext = $request->file('logo_image')->extension();
            $data['logo_image_path'] = $request->file('logo_image')->storeAs($dir, 'logo_image.'.$ext, 'local');
        }

        return $data;
    }
}
