@extends('admin.layout')

@section('title', 'Configurações')

@section('content')
<div class="max-w-2xl">
    <h2 class="text-xl font-bold text-white mb-6">Configurações do Sistema</h2>

    <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PATCH')

        {{-- Identidade --}}
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-6 space-y-4">
            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Identidade Visual</h3>

            <div>
                <label class="block text-sm text-gray-300 mb-1">Nome da empresa</label>
                <input type="text" name="company_name" value="{{ old('company_name', $settings->company_name) }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                @error('company_name') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Cor primária</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="primary_color" value="{{ old('primary_color', $settings->primary_color) }}"
                               class="h-9 w-16 rounded border border-gray-700 bg-gray-800 cursor-pointer">
                        <input type="text" id="primary_color_text" value="{{ old('primary_color', $settings->primary_color) }}"
                               class="flex-1 bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm font-mono focus:outline-none focus:border-blue-500"
                               readonly>
                    </div>
                    @error('primary_color') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Cor de destaque</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="accent_color" value="{{ old('accent_color', $settings->accent_color) }}"
                               class="h-9 w-16 rounded border border-gray-700 bg-gray-800 cursor-pointer">
                        <input type="text" id="accent_color_text" value="{{ old('accent_color', $settings->accent_color) }}"
                               class="flex-1 bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm font-mono focus:outline-none focus:border-blue-500"
                               readonly>
                    </div>
                    @error('accent_color') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Logo --}}
            <div>
                <label class="block text-sm text-gray-300 mb-1">Logo <span class="text-gray-500">(PNG, JPG, SVG — máx. 1 MB)</span></label>
                @if($settings->logo_url)
                    <div class="mb-2 flex items-center gap-3">
                        <img src="{{ $settings->logo_url }}" alt="Logo atual" class="h-10 object-contain bg-gray-800 px-2 rounded">
                        <span class="text-xs text-gray-500">Logo atual — envie um novo para substituir</span>
                    </div>
                @endif
                <input type="file" name="logo" accept="image/*"
                       class="block w-full text-sm text-gray-400 file:mr-3 file:py-1.5 file:px-4 file:rounded file:border-0 file:bg-gray-700 file:text-white file:text-sm hover:file:bg-gray-600">
                @error('logo') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Favicon --}}
            <div>
                <label class="block text-sm text-gray-300 mb-1">Favicon <span class="text-gray-500">(PNG, ICO, SVG — máx. 256 KB)</span></label>
                @if($settings->favicon_url)
                    <div class="mb-2 flex items-center gap-3">
                        <img src="{{ $settings->favicon_url }}" alt="Favicon atual" class="h-8 w-8 object-contain">
                        <span class="text-xs text-gray-500">Favicon atual</span>
                    </div>
                @endif
                <input type="file" name="favicon" accept="image/*,.ico"
                       class="block w-full text-sm text-gray-400 file:mr-3 file:py-1.5 file:px-4 file:rounded file:border-0 file:bg-gray-700 file:text-white file:text-sm hover:file:bg-gray-600">
                @error('favicon') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Suporte --}}
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-6 space-y-4">
            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Contato de Suporte</h3>

            <div>
                <label class="block text-sm text-gray-300 mb-1">E-mail de suporte</label>
                <input type="email" name="support_email" value="{{ old('support_email', $settings->support_email) }}"
                       placeholder="suporte@suaempresa.com.br"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                @error('support_email') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm text-gray-300 mb-1">WhatsApp de suporte <span class="text-gray-500">(com DDD, ex: 11999998888)</span></label>
                <input type="text" name="support_whatsapp" value="{{ old('support_whatsapp', $settings->support_whatsapp) }}"
                       placeholder="11999998888"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                @error('support_whatsapp') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-300">Notificações WhatsApp</p>
                    <p class="text-xs text-gray-500">Envia alertas e avisos via WhatsApp (requer Evolution API configurada)</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="whatsapp_enabled" value="1" class="sr-only peer"
                           @checked(old('whatsapp_enabled', $settings->whatsapp_enabled ?? false))>
                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer
                                peer-checked:bg-blue-600 after:content-[''] after:absolute after:top-[2px]
                                after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5
                                after:transition-all peer-checked:after:translate-x-full"></div>
                </label>
            </div>

            <div>
                <label class="block text-sm text-gray-300 mb-1">Certificado da plataforma (lacre de envelopes)</label>
                <select name="platform_certificate_id"
                        class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">— nenhum —</option>
                    @foreach ($certificates as $cert)
                        <option value="{{ $cert->id }}" @selected(old('platform_certificate_id', $settings->platform_certificate_id) == $cert->id)>
                            {{ $cert->description }} ({{ $cert->user->name }}@if($cert->expires_at) — vence {{ $cert->expires_at->format('d/m/Y') }}@endif)
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1">Usado para assinar digitalmente os envelopes concluídos.</p>
                @error('platform_certificate_id') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit"
                    class="px-6 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded text-sm font-medium transition-colors">
                Salvar configurações
            </button>
        </div>
    </form>

    {{-- Testar WhatsApp --}}
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-6 space-y-4 mt-6">
        <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Testar WhatsApp</h3>
        <p class="text-xs text-gray-500">Envia uma mensagem de teste via Evolution API para validar a conexão da instância.</p>

        @if (session('whatsappTestError'))
            <div class="bg-red-900/30 border border-red-800 rounded px-3 py-2 text-sm text-red-300">
                Falha ao enviar: {{ session('whatsappTestError') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.whatsapp-test') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1">Número (com DDD)</label>
                <input type="text" name="phone" value="{{ old('phone') }}" placeholder="11999998888"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                @error('phone') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Mensagem</label>
                <textarea name="message" rows="3" placeholder="Mensagem de teste do sistema"
                          class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">{{ old('message') }}</textarea>
                @error('message') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end">
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded text-sm font-medium transition-colors">
                    Enviar teste
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    // Sincroniza color picker → campo texto
    document.querySelectorAll('input[type="color"]').forEach(function (picker) {
        var textId = picker.name + '_text';
        var text   = document.getElementById(textId);
        if (!text) return;
        picker.addEventListener('input', function () { text.value = picker.value; });
    });
</script>
@endpush
@endsection
