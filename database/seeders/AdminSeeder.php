<?php

namespace Database\Seeders;

use App\Enum\User\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::create([
            'name' => 'adminv1',
            'email' => 'admin@gmail.com',
            'password' => 'Arrice23#',
            'tipo'=> 1
        ]);

        $user->assignRole(UserRole::ADMIN->value);
    }
}
