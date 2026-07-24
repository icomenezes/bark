@extends('admin.layout')
@section('title', 'Usuário: ' . $user->name)

@section('content')
<div class="max-w-4xl space-y-6">

    @if(session('success'))
    <div class="px-4 py-3 rounded-lg bg-green-900/30 border border-green-800 text-green-400 text-sm">
        {{ session('success') }}
    </div>
    @endif

    {{-- Perfil + Status --}}
    <div class="bg-gray-900 rounded-lg border border-gray-800 p-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-gray-700 rounded-md flex items-center justify-center text-lg font-bold text-white">
            {{ strtoupper(substr($user->name, 0, 1)) }}
        </div>
        <div>
            <h2 class="font-semibold text-white">{{ $user->name }}</h2>
            <p class="text-sm text-gray-400">{{ $user->email }}</p>
            @if($user->whatsapp)
            <p class="text-xs text-gray-500 mt-0.5">WhatsApp: {{ $user->whatsapp }}</p>
            @endif
        </div>
        <div class="ml-auto flex items-center gap-2">
            @if($user->isOnline())
                <span class="flex items-center gap-1 px-2.5 py-1 rounded-md text-xs bg-green-900/40 text-green-400 border border-green-800">
                    <span class="w-1.5 h-1.5 rounded-md bg-green-400 animate-pulse"></span> Online
                </span>
            @endif
            @if($user->isAdmin())
                <span class="px-2.5 py-1 rounded-md text-xs bg-blue-900/40 text-blue-400 border border-blue-800">Admin</span>
            @else
                <span class="px-2.5 py-1 rounded-md text-xs bg-gray-800 text-gray-400 border border-gray-700">Cliente</span>
            @endif
        </div>
    </div>

    {{-- Dados de cadastro --}}
    <div class="bg-gray-900 rounded-lg border border-gray-800 p-5">
        <h3 class="font-semibold text-white text-sm mb-3">Cadastro</h3>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div class="flex justify-between sm:block">
                <dt class="text-gray-500 text-xs">Criado em</dt>
                <dd class="text-gray-300">{{ $user->created_at->format('d/m/Y H:i') }}</dd>
            </div>
            <div class="flex justify-between sm:block">
                <dt class="text-gray-500 text-xs">Último acesso</dt>
                <dd class="text-gray-300">{{ $user->activeSession?->last_seen_at?->diffForHumans() ?? '—' }}</dd>
            </div>
            @if(!$user->isAdmin())
            <div class="flex justify-between sm:block">
                <dt class="text-gray-500 text-xs">Plano</dt>
                <dd class="text-gray-300">
                    @if($user->plan)
                        {{ $user->plan->name }}
                    @else
                        <span class="text-red-400">Nenhum (bloqueado)</span>
                    @endif
                </dd>
            </div>
            @endif
        </dl>
    </div>

    {{-- Logs recentes --}}
    @if($recentLogs->count())
    <div class="bg-gray-900 rounded-lg border border-gray-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold text-white text-sm">Atividade recente</h3>
            <a href="{{ route('admin.access-logs.index', ['user_id' => $user->id]) }}"
               class="text-xs text-blue-400 hover:text-blue-300">Ver todos →</a>
        </div>
        <div class="divide-y divide-gray-800">
            @foreach($recentLogs as $log)
            <div class="flex items-center gap-3 px-6 py-2.5">
                <span class="w-2 h-2 rounded-md bg-{{ $log->eventColor() }}-500 shrink-0"></span>
                <span class="text-xs text-gray-300 flex-1">{{ $log->eventLabel() }}</span>
                <span class="text-xs text-gray-600">{{ $log->created_at->format('d/m H:i') }}</span>
                <span class="text-xs text-gray-700">{{ $log->ip_address }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="flex items-center justify-between">
        <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-400 hover:text-white transition-colors">← Voltar aos usuários</a>
        <a href="{{ route('admin.users.edit', $user) }}"
           class="text-sm text-blue-400 hover:text-blue-300 transition-colors">Editar usuário →</a>
    </div>
</div>
@endsection
