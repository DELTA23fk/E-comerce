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
            'name' => 'nxtItAdmin',
            'email' => 'jlarregui@gmail.com',
            'password' => 'nxtItAdmin123432',
            'tipo'=> 1
        ]);

        $user->assignRole(UserRole::ADMIN->value);
    }
}
