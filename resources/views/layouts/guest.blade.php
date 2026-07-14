<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Sistema Base') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-950 text-gray-100 min-h-screen flex items-center justify-center p-4">

    {{-- Grid decorativo no fundo --}}
    <div class="fixed inset-0 overflow-hidden pointer-events-none select-none" aria-hidden="true">
        <div class="absolute inset-0 grid grid-cols-3 sm:grid-cols-4 gap-1 p-1 opacity-[0.04]">
            @for($i = 0; $i < 16; $i++)
            <div class="bg-blue-400 rounded aspect-video"></div>
            @endfor
        </div>
        {{-- Gradiente central para não poluir o form --}}
        <div class="absolute inset-0 bg-gradient-radial-center"></div>
    </div>

    <div class="relative w-full max-w-sm">

        {{-- Logo / marca --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-blue-600 shadow-lg shadow-blue-900/50 mb-4">
                {{-- Ícone genérico --}}
                <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-white">{{ config('app.name', 'Sistema Base') }}</h1>
            <p class="text-xs text-gray-500 mt-1">Acesse sua conta</p>
        </div>

        {{-- Card do formulário --}}
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 shadow-2xl">
            {{ $slot }}
        </div>

        {{-- Rodapé --}}
        <p class="text-center text-xs text-gray-600 mt-6">
            &copy; {{ date('Y') }} {{ config('app.name', 'Sistema Base') }}
        </p>
    </div>

    <style>
        .bg-gradient-radial-center {
            background: radial-gradient(ellipse 60% 60% at 50% 50%, transparent 0%, rgb(3 7 18) 70%);
        }
    </style>
</body>
</html>
