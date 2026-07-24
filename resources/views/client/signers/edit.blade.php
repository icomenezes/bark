@extends('client.layout')
@section('title', 'Editar signatário')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-xl font-semibold text-white">Editar signatário</h1>

    @if ($errors->any())
        <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <form method="POST" action="{{ route('signers.update', $savedSigner) }}" class="grid md:grid-cols-2 gap-3">
            @csrf
            @method('PATCH')
            <input type="text" name="name" placeholder="Nome completo" required maxlength="255"
                   value="{{ old('name', $savedSigner->name) }}"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="channel" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
                <option value="email" @selected(old('channel', $savedSigner->channel) === 'email')>Canal: E-mail</option>
                <option value="whatsapp" @selected(old('channel', $savedSigner->channel) === 'whatsapp')>Canal: WhatsApp</option>
            </select>
            <input type="text" name="email" placeholder="E-mail" maxlength="255"
                   value="{{ old('email', $savedSigner->email) }}"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <input type="text" name="whatsapp" placeholder="WhatsApp (com DDD)" maxlength="20"
                   value="{{ old('whatsapp', $savedSigner->whatsapp) }}"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="auth_method" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
                <option value="link" @selected(old('auth_method', $savedSigner->auth_method) === 'link')>Somente link</option>
                <option value="email_otp" @selected(old('auth_method', $savedSigner->auth_method) === 'email_otp')>Código por e-mail</option>
                <option value="whatsapp_otp" @selected(old('auth_method', $savedSigner->auth_method) === 'whatsapp_otp')>Código por WhatsApp</option>
            </select>
            <div class="md:col-span-2 flex items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white"
                        style="background-color: var(--color-primary);">Salvar</button>
                <a href="{{ route('signers.index') }}" class="text-sm text-gray-400 hover:text-white">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection
