@extends('admin.layout')
@section('title', 'Usuários')

@section('header-actions')
<a href="{{ route('admin.users.create') }}"
   class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
    </svg>
    Novo usuário
</a>
@endsection

@section('content')
<div class="bg-gray-900 rounded-lg border border-gray-800 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-800">
                <th class="text-left px-6 py-4 text-gray-400 font-medium">Usuário</th>
                <th class="text-left px-6 py-4 text-gray-400 font-medium">E-mail</th>
                <th class="text-left px-6 py-4 text-gray-400 font-medium">Perfil</th>
                <th class="text-left px-6 py-4 text-gray-400 font-medium">Status</th>
                <th class="px-6 py-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-800">
            @forelse($users as $user)
            <tr class="hover:bg-gray-800/50 transition-colors">
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-gray-700 rounded-md flex items-center justify-center text-xs font-bold text-white flex-shrink-0">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </div>
                        <a href="{{ route('admin.users.show', $user) }}" class="font-medium text-white hover:text-blue-400 transition-colors">
                            {{ $user->name }}
                        </a>
                    </div>
                </td>
                <td class="px-6 py-4 text-gray-400">{{ $user->email }}</td>
                <td class="px-6 py-4">
                    @if($user->isAdmin())
                        <span class="px-2.5 py-1 rounded-md text-xs bg-blue-900/40 text-blue-400 border border-blue-800">Admin</span>
                    @else
                        <span class="px-2.5 py-1 rounded-md text-xs bg-gray-800 text-gray-400 border border-gray-700">Cliente</span>
                    @endif
                </td>
                <td class="px-6 py-4">
                    @if($user->isOnline())
                        <span class="inline-flex items-center gap-1.5 text-xs text-green-400">
                            <span class="w-1.5 h-1.5 rounded-md bg-green-400 animate-pulse"></span> Online
                        </span>
                    @else
                        <span class="text-xs text-gray-500">Offline</span>
                    @endif
                </td>
                <td class="px-6 py-4">
                    <div class="flex items-center gap-2 justify-end">
                        <a href="{{ route('admin.users.show', $user) }}"
                           class="text-gray-400 hover:text-white p-1.5 rounded-md hover:bg-gray-700 transition-colors" title="Ver detalhes">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                            </svg>
                        </a>
                        @if(!$user->isAdmin())
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                              onsubmit="return confirm('Remover usuário?')">
                            @csrf @method('DELETE')
                            <button class="text-gray-400 hover:text-red-400 p-1.5 rounded-md hover:bg-gray-700 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="px-6 py-12 text-center text-gray-500">Nenhum usuário cadastrado.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
    @if($users->hasPages())
        <div class="px-6 py-4 border-t border-gray-800">{{ $users->links() }}</div>
    @endif
</div>
@endsection
