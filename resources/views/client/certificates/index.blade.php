@extends('client.layout')
@section('title', 'Certificados')

@section('content')
<div class="max-w-7xl mx-auto space-y-4">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-white">Certificados digitais</h1>
            <p class="text-xs text-gray-500 mt-0.5">Certificados PFX usados para assinar documentos</p>
        </div>
        <a href="{{ route('certificates.create') }}"
           class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            Cadastrar
        </a>
    </div>

    <div class="bg-gray-900 rounded-lg border border-gray-800 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-800">
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Descrição</th>
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Referência</th>
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Certificado expira</th>
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Imagens</th>
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Assinatura</th>
                    <th class="px-6 py-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($certificates as $certificate)
                <tr class="hover:bg-gray-800/50 transition-colors">
                    <td class="px-6 py-4">
                        <a href="{{ route('certificates.edit', $certificate) }}"
                           class="font-medium text-white hover:text-blue-400 transition-colors">
                            {{ $certificate->description }}
                        </a>
                    </td>
                    <td class="px-6 py-4 text-gray-400">{{ $certificate->reference ?? '—' }}</td>
                    <td class="px-6 py-4">
                        @if($certificate->expires_at === null)
                            <span class="text-xs text-gray-500">—</span>
                        @elseif($certificate->isExpired())
                            <span class="px-2.5 py-1 rounded-md text-xs font-semibold bg-red-900/40 text-red-400 border border-red-800">
                                {{ $certificate->expires_at->format('d/m/Y') }} (expirado)
                            </span>
                        @else
                            <span class="px-2.5 py-1 rounded-md text-xs font-semibold bg-green-900/40 text-green-400 border border-green-800">
                                {{ $certificate->expires_at->format('d/m/Y') }} ({{ $certificate->daysToExpire() }} dias)
                            </span>
                        @endif
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            @if($certificate->logo_image_path)
                                <img src="{{ route('certificates.image', [$certificate, 'logo']) }}"
                                     alt="Logo" title="Logo" class="h-8 w-8 object-contain rounded bg-white/90">
                            @endif
                            @if($certificate->sign_image_path)
                                <img src="{{ route('certificates.image', [$certificate, 'sign']) }}"
                                     alt="Assinatura" title="Imagem da assinatura" class="h-8 w-12 object-contain rounded bg-white/90">
                            @endif
                            @if(!$certificate->logo_image_path && !$certificate->sign_image_path)
                                <span class="text-xs text-gray-500">—</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        @if($certificate->id === auth()->user()->signing_certificate_id)
                            <span class="px-2.5 py-1 rounded-md text-xs font-semibold bg-blue-900/40 text-blue-400 border border-blue-800">Padrão</span>
                        @elseif($certificate->isExpired())
                            <span class="text-xs text-gray-600">—</span>
                        @else
                            <form method="POST" action="{{ route('certificates.use-as-signing', $certificate) }}">
                                @csrf
                                <button class="text-xs text-blue-400 hover:text-blue-300">Usar como assinatura</button>
                            </form>
                        @endif
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2 justify-end">
                            <a href="{{ route('certificates.edit', $certificate) }}"
                               class="text-gray-400 hover:text-white p-1.5 rounded-md hover:bg-gray-700 transition-colors" title="Editar">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </a>
                            <form method="POST" action="{{ route('certificates.destroy', $certificate) }}"
                                  onsubmit="return confirm('Remover certificado?')">
                                @csrf @method('DELETE')
                                <button class="text-gray-400 hover:text-red-400 p-1.5 rounded-md hover:bg-gray-700 transition-colors" title="Excluir">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                        Nenhum certificado cadastrado.
                        <a href="{{ route('certificates.create') }}" class="text-blue-400 hover:underline">Cadastre o primeiro</a>.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($certificates->hasPages())
            <div class="px-6 py-4 border-t border-gray-800">{{ $certificates->links() }}</div>
        @endif
    </div>

</div>
@endsection
