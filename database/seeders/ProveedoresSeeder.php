<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProveedoresSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Proveedor::insert([
            [
                'nombre' => 'CVA',
                'codigo_proveedor' => 'cva',
            ],
            [
                'nombre' => 'Exel',
                'codigo_proveedor' => 'exel',
            ],
        ]);
    }
}
