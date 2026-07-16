@extends('admin.layout')
@section('title', 'Editar Plano')

@section('content')
<div class="max-w-lg">
    <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="bg-gray-900 border border-gray-800 rounded-lg p-6 space-y-4">
        @csrf
        @method('PATCH')
        @include('admin.plans._form', ['plan' => $plan])

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.plans.index') }}" class="px-4 py-2 text-sm text-gray-400 hover:text-white transition-colors">Cancelar</a>
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded text-sm font-medium transition-colors">
                Salvar alterações
            </button>
        </div>
    </form>
</div>
@endsection
