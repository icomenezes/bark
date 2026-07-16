@extends('admin.layout')
@section('title', 'Planos')

@section('header-actions')
<a href="{{ route('admin.plans.create') }}"
   class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
    </svg>
    Novo plano
</a>
@endsection

@section('content')
<div class="bg-gray-900 rounded-lg border border-gray-800 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-800">
                <th class="text-left px-6 py-4 text-gray-400 font-medium">Plano</th>
                <th class="text-left px-6 py-4 text-gray-400 font-medium">PDFs/mês</th>
                <th class="text-left px-6 py-4 text-gray-400 font-medium">Envelopes/mês</th>
                <th class="text-left px-6 py-4 text-gray-400 font-medium">Clientes</th>
                <th class="px-6 py-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-800">
            @forelse($plans as $plan)
            <tr class="hover:bg-gray-800/50 transition-colors">
                <td class="px-6 py-4 font-medium text-white">{{ $plan->name }}</td>
                <td class="px-6 py-4 text-gray-400">{{ $plan->max_pdfs_per_month }}</td>
                <td class="px-6 py-4 text-gray-400">{{ $plan->max_envelopes_per_month }}</td>
                <td class="px-6 py-4 text-gray-400">{{ $plan->users_count }}</td>
                <td class="px-6 py-4">
                    <div class="flex items-center gap-2 justify-end">
                        <a href="{{ route('admin.plans.edit', $plan) }}"
                           class="text-gray-400 hover:text-white p-1.5 rounded-md hover:bg-gray-700 transition-colors" title="Editar">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </a>
                        <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}"
                              onsubmit="return confirm('Remover este plano? Clientes atribuídos ficarão sem plano (bloqueados até nova atribuição).')">
                            @csrf @method('DELETE')
                            <button class="text-gray-400 hover:text-red-400 p-1.5 rounded-md hover:bg-gray-700 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="px-6 py-12 text-center text-gray-500">Nenhum plano cadastrado.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
