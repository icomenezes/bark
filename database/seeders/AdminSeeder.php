<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('ADMIN_EMAIL', 'admin@example.com');
        $name     = env('ADMIN_NAME', 'Administrador');
        $password = env('ADMIN_PASSWORD', 'Senha123');

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'name'     => $name,
                'password' => Hash::make($password),
                'role'     => 'admin',
            ]);
        } else {
            User::create([
                'name'     => $name,
                'email'    => $email,
                'password' => Hash::make($password),
                'role'     => 'admin',
            ]);
        }
    }
}
