@extends('admin.layout')
@section('title', 'Editar Usuário')

@section('content')
<div class="max-w-lg">

    <div class="bg-gray-900 rounded-lg border border-gray-800 p-6">
        <h2 class="text-white font-semibold mb-5">Editar usuário</h2>

        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-5">
            @csrf
            @method('PATCH')

            <div>
                <label for="name" class="block text-xs font-medium text-gray-400 mb-1.5">Nome</label>
                <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}"
                       required autofocus
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('name') border-red-500 @enderror">
                @error('name')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-xs font-medium text-gray-400 mb-1.5">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}"
                       required
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('email') border-red-500 @enderror">
                @error('email')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="whatsapp" class="block text-xs font-medium text-gray-400 mb-1.5">WhatsApp (opcional)</label>
                <input id="whatsapp" type="text" name="whatsapp" value="{{ old('whatsapp', $user->whatsapp) }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm placeholder-gray-600
                              focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors
                              @error('whatsapp') border-red-500 @enderror"
                       placeholder="(11) 99999-9999">
                @error('whatsapp')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="plan_id" class="block text-xs font-medium text-gray-400 mb-1.5">Plano (limite de uso)</label>
                <select id="plan_id" name="plan_id"
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm
                               focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                    <option value="">— nenhum (cliente ficará bloqueado até atribuir um plano) —</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" @selected(old('plan_id', $user->plan_id) == $plan->id)>
                            {{ $plan->name }} ({{ $plan->max_pdfs_per_month }} PDFs / {{ $plan->max_envelopes_per_month }} envelopes por mês)
                        </option>
                    @endforeach
                </select>
                @error('plan_id')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold px-6 py-2.5 rounded-lg transition-colors">
                    Salvar alterações
                </button>
                <a href="{{ route('admin.users.show', $user) }}"
                   class="text-sm text-gray-400 hover:text-white transition-colors">
                    Cancelar
                </a>
            </div>
        </form>
    </div>

</div>
@endsection
