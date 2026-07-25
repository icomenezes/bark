@extends('client.layout')
@section('title', 'Início')

@section('content')
<div class="max-w-7xl mx-auto space-y-4">

    <div>
        <h1 class="text-xl font-semibold text-white">Olá, {{ auth()->user()->name }} 👋</h1>
        <p class="text-xs text-gray-500 mt-0.5">Bem-vindo(a) ao {{ $settings->company_name ?? config('app.name') }}</p>
    </div>

    {{-- Números do usuário --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

        {{-- Envelopes --}}
        <a href="{{ route('envelopes.index') }}"
           class="bg-gray-900 border border-gray-800 rounded-xl p-5 hover:border-gray-700 transition-colors">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Envelopes</span>
                <div class="w-8 h-8 bg-blue-900/50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white">{{ $stats['envelopes'] }}</p>
            <p class="text-xs text-gray-500 mt-1">
                @if($stats['max_envelopes_month'] !== null)
                    {{ $stats['envelopes_month'] }} de {{ $stats['max_envelopes_month'] }} este mês
                @else
                    {{ $stats['envelopes_month'] }} este mês
                @endif
            </p>
        </a>

        {{-- Assinaturas avulsas --}}
        <a href="{{ route('sign-document.index') }}"
           class="bg-gray-900 border border-gray-800 rounded-xl p-5 hover:border-gray-700 transition-colors">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Assinaturas avulsas</span>
                <div class="w-8 h-8 bg-amber-900/50 rounded-lg flex items-center justify-center">
                    <x-signature-icon class="w-4 h-4 text-amber-400" />
                </div>
            </div>
            <p class="text-3xl font-bold text-white">{{ $stats['signatures'] }}</p>
            <p class="text-xs text-gray-500 mt-1">
                @if($stats['max_signatures_month'] !== null)
                    {{ $stats['signatures_month'] }} de {{ $stats['max_signatures_month'] }} este mês
                @else
                    {{ $stats['signatures_month'] }} este mês
                @endif
            </p>
        </a>

        {{-- Signatários --}}
        <a href="{{ route('signers.index') }}"
           class="bg-gray-900 border border-gray-800 rounded-xl p-5 hover:border-gray-700 transition-colors">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Signatários</span>
                <div class="w-8 h-8 bg-purple-900/50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white">{{ $stats['signers'] }}</p>
            <p class="text-xs text-gray-500 mt-1">cadastrados</p>
        </a>

        {{-- Grupos --}}
        <a href="{{ route('signers.index') }}"
           class="bg-gray-900 border border-gray-800 rounded-xl p-5 hover:border-gray-700 transition-colors">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Grupos</span>
                <div class="w-8 h-8 bg-teal-900/50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white">{{ $stats['groups'] }}</p>
            <p class="text-xs text-gray-500 mt-1">criados</p>
        </a>

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
