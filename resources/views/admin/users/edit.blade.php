@extends('admin.layout')
@section('title', 'Editar Usuário')

@section('content')
<div class="max-w-lg">

    <div class="bg-gray-900 rounded-lg border border-gray-800 p-6">
        <h2 class="text-white font-semibold mb-5">Editar usuário</h2>

        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-5">
            @csrf
            @method('PATCH')

            <div>
                <label for="name" class="block text-xs font-medium text-gray-400 mb-1.5">Nome</label>
                <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}"
                       required autofocus
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('name') border-red-500 @enderror">
                @error('name')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-xs font-medium text-gray-400 mb-1.5">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}"
                       required
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('email') border-red-500 @enderror">
                @error('email')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="whatsapp" class="block text-xs font-medium text-gray-400 mb-1.5">WhatsApp (opcional)</label>
                <input id="whatsapp" type="text" name="whatsapp" value="{{ old('whatsapp', $user->whatsapp) }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('whatsapp') border-red-500 @enderror"
                       placeholder="(11) 99999-9999">
                @error('whatsapp')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="plan_id" class="block text-xs font-medium text-gray-400 mb-1.5">Plano (limite de uso)</label>
                <select id="plan_id" name="plan_id"
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm
                               focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                    <option value="">— nenhum (cliente ficará bloqueado até atribuir um plano) —</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" @selected(old('plan_id', $user->plan_id) == $plan->id)>
                            {{ $plan->name }} ({{ $plan->max_pdfs_per_month }} PDFs / {{ $plan->max_envelopes_per_month }} envelopes por mês)
                        </option>
                    @endforeach
                </select>
                @error('plan_id')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="whatsapp_envelope_enabled" value="1"
                           {{ old('whatsapp_envelope_enabled', $user->whatsapp_envelope_enabled) ? 'checked' : '' }}
                           class="rounded bg-gray-800 border-gray-700 text-blue-600 focus:ring-blue-500 focus:ring-offset-gray-900">
                    <span class="text-xs font-medium text-gray-400">Permitir envio de envelope via WhatsApp</span>
                </label>
            </div>

            <div>
                <label for="default_envelope_channel" class="block text-xs font-medium text-gray-400 mb-1.5">Canal padrão de envio de envelope</label>
                <select id="default_envelope_channel" name="default_envelope_channel"
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm
                               focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                    <option value="email" @selected(old('default_envelope_channel', $user->default_envelope_channel) === 'email')>E-mail</option>
                    <option value="whatsapp" @selected(old('default_envelope_channel', $user->default_envelope_channel) === 'whatsapp')>WhatsApp</option>
                </select>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold px-6 py-2.5 rounded-lg transition-colors">
                    Salvar alterações
                </button>
                <a href="{{ route('admin.users.show', $user) }}"
                   class="text-sm text-gray-400 hover:text-white transition-colors">
                    Cancelar
                </a>
            </div>
        </form>
    </div>

    {{-- Token de API --}}
    <div class="bg-gray-900 rounded-lg border border-gray-800 p-6 mt-6">
        <h2 class="text-white font-semibold mb-2">Token de API</h2>
        <p class="text-xs text-gray-500 mb-4">Usado para integrações externas (ex.: sistema de ponto de venda) criarem envelopes via API.</p>

        @if (session('api_token'))
            <div class="bg-yellow-900/30 border border-yellow-800 rounded px-3 py-3 text-sm text-yellow-200 mb-4">
                <p class="font-medium mb-1">Copie este token agora — ele não será exibido novamente:</p>
                <code class="block bg-gray-950 border border-gray-800 rounded px-3 py-2 text-xs text-white break-all select-all">{{ session('api_token') }}</code>
            </div>
        @endif

        @if ($hasApiToken)
            <div class="flex items-center justify-between mb-4">
                <span class="inline-flex items-center gap-1.5 text-xs text-green-400">
                    <span class="w-1.5 h-1.5 rounded-md bg-green-400"></span> Token ativo
                </span>
                <form method="POST" action="{{ route('admin.users.api-token.revoke', $user) }}"
                      onsubmit="return confirm('Revogar o token de API deste usuário?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-400 hover:text-red-300 transition-colors">Revogar</button>
                </form>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.users.api-token.generate', $user) }}"
              @if($hasApiToken) onsubmit="return confirm('Gerar um novo token vai revogar o atual. Continuar?')" @endif>
            @csrf
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold px-6 py-2.5 rounded-lg transition-colors">
                {{ $hasApiToken ? 'Gerar novo token' : 'Gerar token de API' }}
            </button>
        </form>
    </div>

</div>
@endsection
