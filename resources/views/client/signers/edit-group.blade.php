@extends('client.layout')
@section('title', 'Editar grupo')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-xl font-semibold text-white">Editar grupo</h1>

    @if ($errors->any())
        <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <form method="POST" action="{{ route('signers.groups.update', $signerGroup) }}" class="space-y-3">
            @csrf
            @method('PATCH')
            <input type="text" name="name" placeholder="Nome do grupo" required maxlength="255"
                   value="{{ old('name', $signerGroup->name) }}"
                   class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="members[]" multiple class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm" size="8">
                @foreach ($signers as $signer)
                    <option value="{{ $signer->id }}" @selected($signerGroup->members->contains($signer->id))>{{ $signer->name }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500">Segure Ctrl (ou Cmd no Mac) para selecionar vários signatários.</p>
            <div class="flex items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white"
                        style="background-color: var(--color-primary);">Salvar</button>
                <a href="{{ route('signers.index') }}" class="text-sm text-gray-400 hover:text-white">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection
