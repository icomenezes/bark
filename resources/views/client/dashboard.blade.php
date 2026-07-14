@extends('client.layout')
@section('title', 'Início')

@section('content')
<div class="max-w-7xl mx-auto space-y-4">

    <div>
        <h1 class="text-xl font-semibold text-white">Olá, {{ auth()->user()->name }} 👋</h1>
        <p class="text-xs text-gray-500 mt-0.5">Bem-vindo(a) ao {{ $settings->company_name ?? config('app.name') }}</p>
    </div>

    {{-- Área dos módulos do sistema: substitua este placeholder pelo conteúdo do seu nicho --}}
    <div class="text-center py-20 text-gray-500 bg-gray-900 border border-gray-800 rounded-xl">
        <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                  d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
        </svg>
        <p class="font-medium">Nenhum módulo instalado</p>
        <p class="text-sm mt-1">Esta é a área principal do cliente — adicione aqui os módulos do seu sistema.</p>
    </div>

</div>
@endsection
