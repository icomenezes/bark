<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BoasVindas;
use App\Models\Plan;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class UserController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    public function index()
    {
        $users = User::where('role', 'client')
            ->with('activeSession')
            ->latest()
            ->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user)
    {
        $user->load('plan');
        $recentLogs = $user->accessLogs()->limit(20)->get();

        return view('admin.users.show', compact('user', 'recentLogs'));
    }

    public function create()
    {
        $plans = Plan::orderBy('name')->get();

        return view('admin.users.create', compact('plans'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::min(8)],
            'role'     => ['required', 'in:admin,client'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'plan_id'  => ['nullable', 'integer', 'exists:plans,id'],
            'whatsapp_envelope_enabled' => ['nullable', 'boolean'],
            'default_envelope_channel' => ['nullable', 'in:email,whatsapp'],
        ]);

        $whatsappEnabled = $request->boolean('whatsapp_envelope_enabled');

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
            'whatsapp' => $request->whatsapp ? preg_replace('/\D/', '', $request->whatsapp) : null,
            'plan_id'  => $request->plan_id ?: null,
            'whatsapp_envelope_enabled' => $whatsappEnabled,
            'default_envelope_channel' => $whatsappEnabled ? ($request->input('default_envelope_channel') ?: 'email') : 'email',
        ]);

        Mail::to($user->email)->queue(new BoasVindas($user));
        $this->notify->boasVindas($user);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'Usuário criado com sucesso.');
    }

    public function edit(User $user)
    {
        $plans = Plan::orderBy('name')->get();
        $hasApiToken = $user->tokens()->exists();

        return view('admin.users.edit', compact('user', 'plans', 'hasApiToken'));
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'plan_id'  => ['nullable', 'integer', 'exists:plans,id'],
            'whatsapp_envelope_enabled' => ['nullable', 'boolean'],
            'default_envelope_channel' => ['nullable', 'in:email,whatsapp'],
        ]);

        $whatsappEnabled = $request->boolean('whatsapp_envelope_enabled');

        $user->update([
            'name'     => $request->name,
            'email'    => $request->email,
            'whatsapp' => $request->whatsapp ? preg_replace('/\D/', '', $request->whatsapp) : null,
            'plan_id'  => $request->plan_id ?: null,
            'whatsapp_envelope_enabled' => $whatsappEnabled,
            'default_envelope_channel' => $whatsappEnabled ? ($request->input('default_envelope_channel') ?: 'email') : 'email',
        ]);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'Usuário atualizado com sucesso.');
    }

    public function destroy(User $user)
    {
        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'Usuário removido.');
    }

    public function generateApiToken(User $user)
    {
        $user->tokens()->delete();
        $token = $user->createToken('api')->plainTextToken;

        return redirect()->route('admin.users.edit', $user)
            ->with('success', 'Token de API gerado com sucesso.')
            ->with('api_token', $token);
    }

    public function revokeApiToken(User $user)
    {
        $user->tokens()->delete();

        return redirect()->route('admin.users.edit', $user)
            ->with('success', 'Token de API revogado.');
    }
}
