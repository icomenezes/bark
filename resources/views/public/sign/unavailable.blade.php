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
            <svg class="w-12 h-12 mx-auto text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
            </svg>
            <p class="text-sm text-gray-300 leading-relaxed">{{ $reason }}</p>
        </div>

        <p class="text-center text-xs text-gray-600 mt-6">
            &copy; {{ date('Y') }} {{ $settings->company_name ?? config('app.name') }}
        </p>
    </div>
</body>
</html>
