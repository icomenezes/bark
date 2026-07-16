@extends('client.layout')
@section('title', 'Início')

@section('content')
<div class="max-w-7xl mx-auto space-y-4">

    <div>
        <h1 class="text-xl font-semibold text-white">Olá, {{ auth()->user()->name }} 👋</h1>
        <p class="text-xs text-gray-500 mt-0.5">Bem-vindo(a) ao {{ $settings->company_name ?? config('app.name') }}</p>
    </div>

    {{-- Área dos módulos do sistema: acesso rápido aos itens do menu --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <a href="{{ route('certificates.index') }}"
           class="bg-gray-900 border border-gray-800 rounded-xl p-6 hover:border-gray-700 transition-colors group">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center mb-4" style="background-color: var(--color-primary)">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="font-medium text-white group-hover:text-blue-400 transition-colors">Certificados</p>
            <p class="text-sm text-gray-500 mt-1">Gerencie seus certificados digitais para assinatura.</p>
        </a>

        <a href="{{ route('sign-document.index') }}"
           class="bg-gray-900 border border-gray-800 rounded-xl p-6 hover:border-gray-700 transition-colors group">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center mb-4" style="background-color: var(--color-primary)">
                <x-signature-icon class="w-5 h-5 text-white" />
            </div>
            <p class="font-medium text-white group-hover:text-blue-400 transition-colors">Assinar Documento</p>
            <p class="text-sm text-gray-500 mt-1">Assine um PDF avulso com seu certificado.</p>
        </a>

        <a href="{{ route('envelopes.index') }}"
           class="bg-gray-900 border border-gray-800 rounded-xl p-6 hover:border-gray-700 transition-colors group">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center mb-4" style="background-color: var(--color-primary)">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <p class="font-medium text-white group-hover:text-blue-400 transition-colors">Envelopes</p>
            <p class="text-sm text-gray-500 mt-1">Envie documentos para assinatura eletrônica multi-signatário.</p>
        </a>
    </div>

</div>
@endsection
