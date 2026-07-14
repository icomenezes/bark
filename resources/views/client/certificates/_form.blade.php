{{-- Form compartilhado de certificado. Recebe $certificate (null no create). --}}
@php($editing = isset($certificate) && $certificate !== null)

@if($errors->any())
    <div class="mb-6 bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
        <ul class="list-disc list-inside space-y-0.5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="bg-gray-900 rounded-lg border border-gray-800 p-6 space-y-6">

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-red-400 mb-1.5">Descrição do certificado: *</label>
            <input type="text" name="description" maxlength="250" required
                   value="{{ old('description', $editing ? $certificate->description : '') }}"
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white
                          focus:outline-none focus:border-blue-500">
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-300 mb-1.5">Referência (opcional):</label>
            <input type="text" name="reference" maxlength="15"
                   value="{{ old('reference', $editing ? $certificate->reference : '') }}"
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white
                          focus:outline-none focus:border-blue-500">
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-red-400 mb-1.5">
                Arquivo PFX: {{ $editing ? '(deixe vazio para manter o atual)' : '*' }}
            </label>
            <input type="file" name="pfx" accept=".pfx,.p12" @if(!$editing) required @endif
                   class="w-full text-sm text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0
                          file:bg-gray-700 file:text-white file:text-sm hover:file:bg-gray-600
                          bg-gray-800 border border-gray-700 rounded-lg">
            @if($editing && $certificate->pfx_path)
                <p class="text-xs text-gray-500 mt-1">Atual: {{ basename($certificate->pfx_path) }}
                    @if($certificate->expires_at) — válido até {{ $certificate->expires_at->format('d/m/Y') }} @endif
                </p>
            @endif
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-red-400 mb-1.5">
                Senha do PFX: {{ $editing ? '(só se trocar o PFX/senha)' : '*' }}
            </label>
            <input type="password" name="password" autocomplete="new-password" @if(!$editing) required @endif
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white
                          focus:outline-none focus:border-blue-500">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-1.5">Imagem de logo:</label>
            <div class="bg-gray-800 border border-gray-700 rounded-lg p-4 min-h-[10rem] flex flex-col items-center justify-center gap-3">
                @if($editing && $certificate->logo_image_path)
                    <img src="{{ route('certificates.image', [$certificate, 'logo']) }}"
                         alt="Logo atual" class="max-h-28 object-contain rounded bg-white/90">
                @endif
                <input type="file" name="logo_image" accept=".jpg,.jpeg,.png"
                       class="text-sm text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0
                              file:bg-gray-700 file:text-white file:text-sm hover:file:bg-gray-600">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-1.5">Imagem da assinatura (carimbo visual):</label>
            <div class="bg-gray-800 border border-gray-700 rounded-lg p-4 min-h-[10rem] flex flex-col items-center justify-center gap-3">
                @if($editing && $certificate->sign_image_path)
                    <img src="{{ route('certificates.image', [$certificate, 'sign']) }}"
                         alt="Assinatura atual" class="max-h-28 object-contain rounded bg-white/90">
                @endif
                <input type="file" name="sign_image" accept=".jpg,.jpeg,.png"
                       class="text-sm text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0
                              file:bg-gray-700 file:text-white file:text-sm hover:file:bg-gray-600">
                <p class="text-xs text-gray-500">Sem esta imagem o PDF é assinado sem carimbo visual.</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3 pt-2 border-t border-gray-800">
        <button type="submit"
                class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            Salvar
        </button>
        <a href="{{ route('certificates.index') }}"
           class="flex items-center gap-1.5 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Voltar
        </a>
    </div>

</div>
