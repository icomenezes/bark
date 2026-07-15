<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Início') — {{ $settings->company_name ?? config('app.name') }}</title>
    @if($settings->favicon_url ?? false)
        <link rel="icon" href="{{ $settings->favicon_url }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --color-primary: {{ $settings->primary_color ?? '#1e40af' }};
            --color-accent:  {{ $settings->accent_color ?? '#3b82f6' }};
        }
    </style>
    {{-- PWA --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="{{ $settings->primary_color ?? '#1e40af' }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ $settings->company_name ?? config('app.name') }}">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen flex flex-col">

    <div x-data="{ mobileNavOpen: false }">
    <header class="bg-gray-900 border-b border-gray-800 px-6 py-4 flex items-center gap-4">
        <div class="flex items-center gap-3">
            @if($settings->logo_url ?? false)
                <img src="{{ $settings->logo_url }}" alt="{{ $settings->company_name }}" class="h-7 w-auto object-contain">
            @else
                <div class="w-7 h-7 rounded flex items-center justify-center" style="background-color: var(--color-primary)">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                </div>
            @endif
            <span class="font-semibold text-sm text-white">{{ $settings->company_name ?? config('app.name') }}</span>
        </div>

        <nav class="hidden md:flex items-center gap-1 ml-6">
            <a href="{{ route('dashboard') }}"
               class="px-3 py-2 rounded-md text-sm transition-colors
                      {{ request()->routeIs('dashboard') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                Início
            </a>
            <a href="{{ route('certificates.index') }}"
               class="px-3 py-2 rounded-md text-sm transition-colors
                      {{ request()->routeIs('certificates.*') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                Certificados
            </a>
            <a href="{{ route('sign-document.index') }}"
               class="px-3 py-2 rounded-md text-sm transition-colors
                      {{ request()->routeIs('sign-document.*') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                Assinar Documento
            </a>
        </nav>

        <div class="ml-auto flex items-center gap-3">
            <a href="{{ route('profile.edit') }}" class="text-sm text-gray-400 hover:text-white transition-colors hidden sm:block">
                {{ auth()->user()->name }}
            </a>
            <form method="POST" action="{{ route('logout') }}" class="hidden md:block">
                @csrf
                <button type="submit"
                        class="text-sm text-gray-400 hover:text-white transition-colors flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Sair
                </button>
            </form>

            <button type="button" @click="mobileNavOpen = !mobileNavOpen"
                    class="md:hidden text-gray-400 hover:text-white transition-colors p-1">
                <svg x-show="!mobileNavOpen" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
                <svg x-show="mobileNavOpen" x-cloak class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </header>

        <div x-show="mobileNavOpen" x-cloak @click.outside="mobileNavOpen = false"
             class="md:hidden bg-gray-900 border-b border-gray-800 px-4 py-3 space-y-1">
            <a href="{{ route('dashboard') }}"
               class="block px-3 py-2 rounded-md text-sm transition-colors
                      {{ request()->routeIs('dashboard') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                Início
            </a>
            <a href="{{ route('certificates.index') }}"
               class="block px-3 py-2 rounded-md text-sm transition-colors
                      {{ request()->routeIs('certificates.*') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                Certificados
            </a>
            <a href="{{ route('sign-document.index') }}"
               class="block px-3 py-2 rounded-md text-sm transition-colors
                      {{ request()->routeIs('sign-document.*') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                Assinar Documento
            </a>
            <a href="{{ route('profile.edit') }}"
               class="block px-3 py-2 rounded-md text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition-colors">
                {{ auth()->user()->name }}
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full text-left px-3 py-2 rounded-md text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition-colors flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Sair
                </button>
            </form>
        </div>
    </div>

    {{-- Banner "Adicionar à tela inicial" --}}
    <div id="pwa-banner" class="hidden fixed bottom-0 left-0 right-0 z-50 bg-gray-900 border-t border-gray-700 px-4 py-3 flex items-center gap-3 shadow-lg">
        <div class="w-9 h-9 rounded flex items-center justify-center flex-shrink-0" style="background-color: var(--color-primary)">
            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-white">Instalar aplicativo</p>
            <p class="text-xs text-gray-400">Adicione à tela inicial para acesso rápido</p>
        </div>
        <button id="pwa-install" class="px-3 py-1.5 text-sm font-medium text-white rounded" style="background-color: var(--color-primary)">
            Instalar
        </button>
        <button id="pwa-dismiss" class="text-gray-500 hover:text-gray-300 ml-1">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <main class="flex-1 p-6">
        <div class="max-w-7xl mx-auto">
            @if(session('success'))
                <div class="mb-6 flex items-center gap-3 bg-green-900/40 border border-green-700 text-green-300 px-4 py-3 rounded text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if(session('warning'))
                <div class="mb-6 flex items-center gap-3 bg-yellow-900/40 border border-yellow-700 text-yellow-300 px-4 py-3 rounded text-sm">
                    {{ session('warning') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-6 flex items-center gap-3 bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
                    {{ session('error') }}
                </div>
            @endif
        </div>
        @yield('content')
    </main>

@stack('scripts')
<script>
    // Heartbeat a cada 30s para manter sessão ativa
    setInterval(function () {
        fetch('{{ route('heartbeat') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({}),
        }).catch(function () {});
    }, 30000);

    // Service Worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
    }

    // Banner de instalação PWA
    var deferredPrompt;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        if (!localStorage.getItem('pwa-dismissed')) {
            document.getElementById('pwa-banner').classList.remove('hidden');
        }
    });

    document.getElementById('pwa-install').addEventListener('click', function () {
        document.getElementById('pwa-banner').classList.add('hidden');
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function () { deferredPrompt = null; });
        }
    });

    document.getElementById('pwa-dismiss').addEventListener('click', function () {
        document.getElementById('pwa-banner').classList.add('hidden');
        localStorage.setItem('pwa-dismissed', '1');
    });
</script>
</body>
</html>
