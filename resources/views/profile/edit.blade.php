@extends('client.layout')

@section('title', 'Perfil')

@section('content')
<div class="max-w-2xl space-y-6">
    <h2 class="text-xl font-bold text-white mb-2">Perfil</h2>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-6">
        @include('profile.partials.update-profile-information-form')
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-6">
        @include('profile.partials.update-password-form')
    </div>
</div>
@endsection
