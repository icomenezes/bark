<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BoasVindas;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        $recentLogs = $user->accessLogs()->limit(20)->get();

        return view('admin.users.show', compact('user', 'recentLogs'));
    }

    public function create()
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::min(8)],
            'role'     => ['required', 'in:admin,client'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
            'whatsapp' => $request->whatsapp ? preg_replace('/\D/', '', $request->whatsapp) : null,
        ]);

        Mail::to($user->email)->queue(new BoasVindas($user));
        $this->notify->boasVindas($user);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'Usuário criado com sucesso.');
    }

    public function destroy(User $user)
    {
        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'Usuário removido.');
    }
}
