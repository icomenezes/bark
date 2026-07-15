<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $settings->company_name ?? config('app.name') }} — Assinatura</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-950 text-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="relative w-full max-w-md">
        <div class="text-center mb-8">
            @if (!empty($settings->logo_url))
                <img src="{{ $settings->logo_url }}" alt="" class="h-12 mx-auto mb-4">
            @endif
            <h1 class="text-xl font-bold text-white">{{ $settings->company_name ?? config('app.name') }}</h1>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 shadow-2xl text-center space-y-4">
            <svg class="w-12 h-12 mx-auto text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <h2 class="text-lg font-semibold text-white">{{ $title }}</h2>
            <p class="text-sm text-gray-300 leading-relaxed">{{ $message }}</p>
        </div>

        <p class="text-center text-xs text-gray-600 mt-6">
            &copy; {{ date('Y') }} {{ $settings->company_name ?? config('app.name') }}
        </p>
    </div>
</body>
</html>
