@extends('admin.layout')
@section('title', 'Novo Usuário')

@section('content')
<div class="max-w-lg">

    <div class="bg-gray-900 rounded-lg border border-gray-800 p-6">
        <h2 class="text-white font-semibold mb-5">Cadastrar novo usuário</h2>

        <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-5">
            @csrf

            <div>
                <label for="name" class="block text-xs font-medium text-gray-400 mb-1.5">Nome</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}"
                       required autofocus
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('name') border-red-500 @enderror"
                       placeholder="Nome completo">
                @error('name')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-xs font-medium text-gray-400 mb-1.5">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       required
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('email') border-red-500 @enderror"
                       placeholder="email@exemplo.com">
                @error('email')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-xs font-medium text-gray-400 mb-1.5">Senha</label>
                <input id="password" type="password" name="password"
                       required autocomplete="new-password"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('password') border-red-500 @enderror"
                       placeholder="Mínimo 8 caracteres">
                @error('password')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-xs font-medium text-gray-400 mb-1.5">Confirmar senha</label>
                <input id="password_confirmation" type="password" name="password_confirmation"
                       required autocomplete="new-password"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors"
                       placeholder="Repita a senha">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-400 mb-2">Perfil</label>
                <div class="flex gap-3">
                    <label class="flex-1 flex items-center gap-3 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 cursor-pointer
                                  has-[:checked]:border-blue-500 has-[:checked]:bg-blue-900/20 transition-colors">
                        <input type="radio" name="role" value="client"
                               class="text-blue-600 focus:ring-blue-500 focus:ring-offset-gray-900"
                               {{ old('role', 'client') === 'client' ? 'checked' : '' }}>
                        <div>
                            <p class="text-sm font-medium text-white">Cliente</p>
                            <p class="text-xs text-gray-500">Acesso à área do cliente</p>
                        </div>
                    </label>
                    <label class="flex-1 flex items-center gap-3 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 cursor-pointer
                                  has-[:checked]:border-blue-500 has-[:checked]:bg-blue-900/20 transition-colors">
                        <input type="radio" name="role" value="admin"
                               class="text-blue-600 focus:ring-blue-500 focus:ring-offset-gray-900"
                               {{ old('role') === 'admin' ? 'checked' : '' }}>
                        <div>
                            <p class="text-sm font-medium text-white">Admin</p>
                            <p class="text-xs text-gray-500">Acesso total ao sistema</p>
                        </div>
                    </label>
                </div>
                @error('role')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="whatsapp" class="block text-xs font-medium text-gray-400 mb-1.5">WhatsApp (opcional)</label>
                <input id="whatsapp" type="text" name="whatsapp" value="{{ old('whatsapp') }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('whatsapp') border-red-500 @enderror"
                       placeholder="(11) 99999-9999">
                @error('whatsapp')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold px-6 py-2.5 rounded-lg transition-colors">
                    Criar usuário
                </button>
                <a href="{{ route('admin.users.index') }}"
                   class="text-sm text-gray-400 hover:text-white transition-colors">
                    Cancelar
                </a>
            </div>
        </form>
    </div>

</div>
@endsection
