@extends('client.layout')
@section('title', 'Signatários')

@section('content')
<div class="max-w-5xl mx-auto space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-white">Signatários</h1>
        <p class="text-xs text-gray-500 mt-0.5">Contatos e grupos reutilizáveis para seus envelopes</p>
    </div>

    @if (session('success'))
        <div class="bg-green-900/40 border border-green-700 text-green-300 px-4 py-3 rounded text-sm">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <h2 class="text-sm font-semibold text-white">Novo signatário</h2>
        <form method="POST" action="{{ route('signers.store') }}" class="grid md:grid-cols-2 gap-3">
            @csrf
            <input type="text" name="name" placeholder="Nome completo" required maxlength="255"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="channel" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
                <option value="email">Canal: E-mail</option>
                <option value="whatsapp">Canal: WhatsApp</option>
            </select>
            <input type="text" name="email" placeholder="E-mail" maxlength="255"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <input type="text" name="whatsapp" placeholder="WhatsApp (com DDD)" maxlength="20"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="auth_method" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
                <option value="link">Somente link</option>
                <option value="email_otp">Código por e-mail</option>
                <option value="whatsapp_otp">Código por WhatsApp</option>
            </select>
            <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white md:col-span-2"
                    style="background-color: var(--color-primary);">Salvar</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
        <h2 class="text-sm font-semibold text-white mb-3">Seus signatários</h2>
        <div class="space-y-2">
            @forelse ($signers as $signer)
                <div class="flex items-center justify-between border-b border-gray-800 pb-2 text-sm">
                    <div>
                        <span class="text-white">{{ $signer->name }}</span>
                        <span class="text-gray-500 text-xs ml-2">{{ $signer->email ?? $signer->whatsapp }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('signers.edit', $signer) }}" class="text-xs text-gray-400 hover:text-white">editar</a>
                        <form method="POST" action="{{ route('signers.destroy', $signer) }}"
                              onsubmit="return confirm('Remover este signatário?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-400 hover:text-red-300">remover</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum signatário salvo ainda.</p>
            @endforelse
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <h2 class="text-sm font-semibold text-white">Novo grupo</h2>
        <form method="POST" action="{{ route('signers.groups.store') }}" class="space-y-3">
            @csrf
            <input type="text" name="name" placeholder="Nome do grupo" required maxlength="255"
                   class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="members[]" multiple class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm" size="5">
                @foreach ($signers as $signer)
                    <option value="{{ $signer->id }}">{{ $signer->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white"
                    style="background-color: var(--color-primary);">Criar grupo</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
        <h2 class="text-sm font-semibold text-white mb-3">Seus grupos</h2>
        <div class="space-y-2">
            @forelse ($groups as $group)
                <div class="flex items-center justify-between border-b border-gray-800 pb-2 text-sm">
                    <div>
                        <span class="text-white">{{ $group->name }}</span>
                        <span class="text-gray-500 text-xs ml-2">{{ $group->members->count() }} membro(s)</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('signers.groups.edit', $group) }}" class="text-xs text-gray-400 hover:text-white">editar</a>
                        <form method="POST" action="{{ route('signers.groups.destroy', $group) }}"
                              onsubmit="return confirm('Remover este grupo?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-400 hover:text-red-300">remover</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum grupo criado ainda.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
