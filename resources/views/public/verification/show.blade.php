<x-guest-layout>
    <div class="text-center mb-6">
        <h2 class="text-lg font-semibold text-white">Verificação de documento</h2>
        <p class="text-xs text-gray-500 mt-1">Código {{ $code }}</p>
    </div>

    <dl class="space-y-4 text-sm">
        <div>
            <dt class="text-gray-500">Documento</dt>
            <dd class="text-white font-medium">{{ $title }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Status</dt>
            <dd class="text-white font-medium">{{ ucfirst($status) }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Hash SHA-256</dt>
            <dd class="text-white font-mono text-xs break-all">{{ $sha256 }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 mb-2">Assinaturas</dt>
            <dd class="space-y-2">
                @foreach ($signers as $signer)
                    <div class="flex justify-between border-b border-gray-800 pb-2">
                        <span class="text-white">{{ $signer['name'] }}</span>
                        <span class="text-gray-500 text-xs">
                            {{ $signer['signed_at']?->format('d/m/Y H:i') ?? '—' }}
                        </span>
                    </div>
                @endforeach
            </dd>
        </div>
    </dl>
</x-guest-layout>
