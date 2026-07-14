@extends('client.layout')
@section('title', 'Cadastro de certificado')

@section('content')
<div class="max-w-5xl mx-auto space-y-4">
    <div>
        <h1 class="text-xl font-semibold text-white">Cadastro de certificado</h1>
        <p class="text-xs text-gray-500 mt-0.5">A senha é validada contra o PFX no momento do cadastro</p>
    </div>

    <form method="POST" action="{{ route('certificates.store') }}" enctype="multipart/form-data">
        @csrf
        @include('client.certificates._form', ['certificate' => null])
    </form>
</div>
@endsection
