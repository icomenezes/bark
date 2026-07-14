<?php

namespace App\Http\Controllers;

use App\Mail\BoasVindas;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PublicRegisterController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name'     => ['required', 'string', 'max:150'],
                'email'    => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                'whatsapp' => ['nullable', 'string', 'max:20'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => collect($e->errors())->flatten()->first(),
            ], 422);
        }

        if (User::where('email', $data['email'])->exists()) {
            return response()->json([
                'error' => 'Já existe uma conta com esse e-mail.',
            ], 409);
        }

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => 'client',
            'whatsapp' => isset($data['whatsapp']) ? preg_replace('/\D/', '', $data['whatsapp']) : null,
        ]);

        Mail::to($user->email)->queue(new BoasVindas($user));
        $this->notify->boasVindas($user);

        return response()->json([
            'id'    => $user->id,
            'login' => $user->email,
            'url'   => config('app.url'),
        ], 201);
    }
}
