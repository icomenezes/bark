<x-guest-layout>
    <x-auth-session-status class="mb-4 text-sm text-green-400" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-xs font-medium text-gray-400 mb-1.5">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   required autofocus autocomplete="username"
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                          focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors"
                   placeholder="seu@email.com">
            @error('email')
            <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-xs font-medium text-gray-400 mb-1.5">Senha</label>
            <input id="password" type="password" name="password"
                   required autocomplete="current-password"
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                          focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors"
                   placeholder="••••••••">
            @error('password')
            <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 cursor-pointer">
                <input id="remember_me" type="checkbox" name="remember"
                       class="rounded border-gray-600 bg-gray-800 text-blue-600 focus:ring-blue-500 focus:ring-offset-gray-900">
                <span class="text-xs text-gray-400">Lembrar de mim</span>
            </label>

            @if (Route::has('password.request'))
            <a href="{{ route('password.request') }}"
               class="text-xs text-blue-500 hover:text-blue-400 transition-colors">
                Esqueceu a senha?
            </a>
            @endif
        </div>

        <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-semibold text-sm py-2.5 rounded-lg transition-colors">
            Entrar
        </button>
    </form>
</x-guest-layout>
