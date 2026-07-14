@extends('client.layout')
@section('title', 'Editar certificado')

@section('content')
<div class="max-w-5xl mx-auto space-y-4">
    <div>
        <h1 class="text-xl font-semibold text-white">Editar certificado #{{ $certificate->id }}</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ $certificate->description }}</p>
    </div>

    <form method="POST" action="{{ route('certificates.update', $certificate) }}" enctype="multipart/form-data">
        @csrf @method('PATCH')
        @include('client.certificates._form')
    </form>
</div>
@endsection
