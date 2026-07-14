<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\AccessLogService;
use Illuminate\Http\Request;
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

        $expiresAt = $this->readPfxExpiration($request->file('pfx')->getRealPath(), $request->password);

        $certificate = auth()->user()->certificates()->create([
            'description' => $request->description,
            'reference' => $request->reference,
            'pfx_path' => '',
            'password' => $request->password,
            'expires_at' => $expiresAt,
        ]);

        $certificate->update($this->storeFiles($request, $certificate));

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

        // PFX novo (ou só troca de senha) — revalida contra o arquivo e atualiza a validade
        if ($request->hasFile('pfx')) {
            $data['password'] = $request->password;
            $data['expires_at'] = $this->readPfxExpiration($request->file('pfx')->getRealPath(), $request->password);
        } elseif ($request->filled('password')) {
            $data['password'] = $request->password;
            $data['expires_at'] = $this->readPfxExpiration(
                Storage::disk('local')->path($certificate->pfx_path),
                $request->password
            );
        }

        $certificate->update($data + $this->storeFiles($request, $certificate));

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
     * Senha errada nunca chega ao banco.
     */
    private function readPfxExpiration(string $pfxAbsolutePath, string $password): ?string
    {
        $p12 = [];
        if (! openssl_pkcs12_read((string) file_get_contents($pfxAbsolutePath), $p12, $password)) {
            throw ValidationException::withMessages([
                'password' => 'Não foi possível ler o PFX: senha incorreta ou arquivo inválido.',
            ]);
        }

        $info = openssl_x509_parse($p12['cert']) ?: [];

        return isset($info['validTo_time_t']) ? date('Y-m-d', (int) $info['validTo_time_t']) : null;
    }

    /** Grava PFX e imagens em certificates/{id}/ no disk local; retorna os paths preenchidos. */
    private function storeFiles(Request $request, Certificate $certificate): array
    {
        $dir = 'certificates/'.$certificate->id;
        $data = [];

        if ($request->hasFile('pfx')) {
            $data['pfx_path'] = $request->file('pfx')->storeAs($dir, 'certificate.pfx', 'local');
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
