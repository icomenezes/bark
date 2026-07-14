@extends('admin.layout')
@section('title', 'Dashboard')

@section('content')
<div class="space-y-6">

    {{-- Cards de estatísticas --}}
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">

        {{-- Clientes --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Clientes</span>
                <div class="w-8 h-8 bg-green-900/50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white">{{ $clientsTotal }}</p>
            <p class="text-xs text-gray-500 mt-1">
                <span class="text-green-400">{{ $onlineNow->count() }} online agora</span>
            </p>
        </div>

        {{-- Online agora --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Online agora</span>
                <div class="w-8 h-8 bg-blue-900/50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white">{{ $onlineNow->count() }}</p>
            <p class="text-xs text-gray-500 mt-1">últimos 2 minutos</p>
        </div>

        {{-- Acessos negados --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Acessos negados</span>
                <div class="w-8 h-8 bg-red-900/50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-white">{{ $deniedToday }}</p>
            <p class="text-xs text-gray-500 mt-1">hoje</p>
        </div>

    </div>

    {{-- Online agora + Últimos cadastros --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Online agora --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-800 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                <h3 class="text-sm font-semibold text-white">Online agora</h3>
                <span class="ml-auto text-xs text-gray-500">{{ $onlineNow->count() }} usuário{{ $onlineNow->count() !== 1 ? 's' : '' }}</span>
            </div>
            <div class="divide-y divide-gray-800">
                @forelse($onlineNow as $session)
                <div class="flex items-center gap-3 px-5 py-3">
                    <div class="w-7 h-7 bg-gray-700 rounded-full flex items-center justify-center text-xs font-bold text-white shrink-0">
                        {{ strtoupper(substr($session->user->name, 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('admin.users.show', $session->user) }}"
                           class="text-sm text-white hover:text-blue-400 transition-colors truncate block">
                            {{ $session->user->name }}
                        </a>
                        <p class="text-xs text-gray-500">
                            {{ $session->last_seen_at->diffForHumans() }}
                        </p>
                    </div>
                    <span class="text-xs text-gray-600 font-mono shrink-0">{{ $session->ip_address }}</span>
                </div>
                @empty
                <p class="px-5 py-6 text-sm text-gray-600 text-center">Nenhum usuário online agora.</p>
                @endforelse
            </div>
        </div>

        {{-- Últimos cadastros --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-white">Últimos cadastros</h3>
                <a href="{{ route('admin.users.index') }}" class="text-xs text-blue-500 hover:text-blue-400 transition-colors">Ver todos →</a>
            </div>
            <div class="divide-y divide-gray-800">
                @forelse($recentUsers as $recentUser)
                <div class="flex items-center gap-3 px-5 py-3">
                    <div class="w-7 h-7 bg-gray-700 rounded-full flex items-center justify-center text-xs font-bold text-white shrink-0">
                        {{ strtoupper(substr($recentUser->name, 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('admin.users.show', $recentUser) }}"
                           class="text-sm text-white hover:text-blue-400 transition-colors truncate block">
                            {{ $recentUser->name }}
                        </a>
                        <p class="text-xs text-gray-500 truncate">{{ $recentUser->email }}</p>
                    </div>
                    <span class="text-xs text-gray-600 shrink-0">{{ $recentUser->created_at->format('d/m/Y') }}</span>
                </div>
                @empty
                <p class="px-5 py-6 text-sm text-gray-600 text-center">Nenhum usuário cadastrado ainda.</p>
                @endforelse
            </div>
        </div>

    </div>

    {{-- Acessos negados hoje + Log recente --}}
    @if($deniedToday > 0 || $recentLogs->count())
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-white">Atividade recente</h3>
            @if($deniedToday > 0)
            <span class="px-2.5 py-1 rounded-full text-xs bg-red-900/40 text-red-400 border border-red-800">
                {{ $deniedToday }} acesso{{ $deniedToday > 1 ? 's' : '' }} negado{{ $deniedToday > 1 ? 's' : '' }} hoje
            </span>
            @endif
            <a href="{{ route('admin.access-logs.index') }}" class="text-xs text-blue-500 hover:text-blue-400 transition-colors">Ver todos →</a>
        </div>
        <div class="divide-y divide-gray-800">
            @foreach($recentLogs as $log)
            <div class="flex items-center gap-3 px-5 py-2.5">
                <span class="w-1.5 h-1.5 rounded-full bg-{{ $log->eventColor() }}-500 shrink-0"></span>
                <span class="text-xs text-gray-300 flex-1">
                    <span class="text-white">{{ $log->user->name }}</span>
                    — {{ $log->eventLabel() }}
                </span>
                <span class="text-xs text-gray-600 shrink-0">{{ $log->created_at->format('H:i') }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Ações rápidas --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <a href="{{ route('admin.users.create') }}"
           class="flex flex-col items-center gap-2 bg-gray-900 border border-gray-800 hover:border-green-700 rounded-xl p-4 text-center transition-colors group">
            <div class="w-9 h-9 bg-green-900/40 group-hover:bg-green-900/70 rounded-lg flex items-center justify-center transition-colors">
                <svg class="w-5 h-5 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                </svg>
            </div>
            <span class="text-xs font-medium text-gray-300 group-hover:text-white transition-colors">Novo usuário</span>
        </a>
        <a href="{{ route('admin.users.index') }}"
           class="flex flex-col items-center gap-2 bg-gray-900 border border-gray-800 hover:border-gray-600 rounded-xl p-4 text-center transition-colors group">
            <div class="w-9 h-9 bg-gray-800 group-hover:bg-gray-700 rounded-lg flex items-center justify-center transition-colors">
                <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </div>
            <span class="text-xs font-medium text-gray-300 group-hover:text-white transition-colors">Usuários</span>
        </a>
        <a href="{{ route('admin.access-logs.index') }}"
           class="flex flex-col items-center gap-2 bg-gray-900 border border-gray-800 hover:border-blue-700 rounded-xl p-4 text-center transition-colors group">
            <div class="w-9 h-9 bg-blue-900/40 group-hover:bg-blue-900/70 rounded-lg flex items-center justify-center transition-colors">
                <svg class="w-5 h-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <span class="text-xs font-medium text-gray-300 group-hover:text-white transition-colors">Logs de acesso</span>
        </a>
        <a href="{{ route('admin.settings.edit') }}"
           class="flex flex-col items-center gap-2 bg-gray-900 border border-gray-800 hover:border-purple-700 rounded-xl p-4 text-center transition-colors group">
            <div class="w-9 h-9 bg-purple-900/40 group-hover:bg-purple-900/70 rounded-lg flex items-center justify-center transition-colors">
                <svg class="w-5 h-5 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <span class="text-xs font-medium text-gray-300 group-hover:text-white transition-colors">Configurações</span>
        </a>
    </div>

</div>
@endsection
