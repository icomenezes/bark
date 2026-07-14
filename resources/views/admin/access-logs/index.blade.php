@extends('admin.layout')
@section('title', 'Logs de Acesso')

@section('content')
<div class="space-y-4">

    {{-- Filtros --}}
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-400 mb-1">Usuário</label>
            <select name="user_id" class="bg-gray-800 border border-gray-700 rounded-md px-3 py-1.5 text-sm text-white">
                <option value="">Todos</option>
                @foreach($users as $u)
                <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Evento</label>
            <select name="event" class="bg-gray-800 border border-gray-700 rounded-md px-3 py-1.5 text-sm text-white">
                <option value="">Todos</option>
                <option value="login" @selected(request('event')=='login')>Login</option>
                <option value="logout" @selected(request('event')=='logout')>Logout</option>
                <option value="access_denied" @selected(request('event')=='access_denied')>Acesso negado</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Data</label>
            <input type="date" name="date" value="{{ request('date') }}"
                   class="bg-gray-800 border border-gray-700 rounded-md px-3 py-1.5 text-sm text-white">
        </div>
        <button type="submit"
                class="px-4 py-1.5 rounded-md text-xs font-medium bg-blue-600 hover:bg-blue-500 text-white transition-colors">
            Filtrar
        </button>
        @if(request()->hasAny(['user_id','event','date']))
        <a href="{{ route('admin.access-logs.index') }}"
           class="px-4 py-1.5 rounded-md text-xs font-medium bg-gray-700 hover:bg-gray-600 text-gray-300 transition-colors">
            Limpar
        </a>
        @endif
    </form>

    {{-- Tabela --}}
    <div class="bg-gray-900 rounded-lg border border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 border-b border-gray-800">
                    <th class="text-left px-5 py-3">Data/Hora</th>
                    <th class="text-left px-5 py-3">Usuário</th>
                    <th class="text-left px-5 py-3">Evento</th>
                    <th class="text-left px-5 py-3">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($logs as $log)
                <tr>
                    <td class="px-5 py-2.5 text-gray-400 text-xs whitespace-nowrap">
                        {{ $log->created_at->format('d/m/Y H:i:s') }}
                    </td>
                    <td class="px-5 py-2.5">
                        <a href="{{ route('admin.users.show', $log->user) }}"
                           class="text-white hover:text-blue-400 transition-colors text-xs">
                            {{ $log->user->name }}
                        </a>
                    </td>
                    <td class="px-5 py-2.5">
                        <span class="px-2 py-0.5 rounded text-xs bg-{{ $log->eventColor() }}-900/30 text-{{ $log->eventColor() }}-400 border border-{{ $log->eventColor() }}-800/50">
                            {{ $log->eventLabel() }}
                        </span>
                    </td>
                    <td class="px-5 py-2.5 text-gray-600 text-xs font-mono">{{ $log->ip_address }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-5 py-10 text-center text-gray-500">Nenhum registro encontrado.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $logs->links() }}
</div>
@endsection
