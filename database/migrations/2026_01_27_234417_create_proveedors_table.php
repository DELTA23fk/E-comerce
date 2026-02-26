<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_proveedor',10)->unique();
            $table->string('nombre',50);
            $table->boolean('activo')->default(true);
            $table->integer('porcentaje_utilidad')->default(0)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('codigo_proveedor');
            $table->index('porcentaje_utilidad');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
