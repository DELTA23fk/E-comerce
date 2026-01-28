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
        Schema::create('proveedor_productos', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor_producto_id')->unique();
            $table->string('codigo_proveedor')->unique();
            $table->integer('stock');
            $table->integer('stock_cd');
            $table->string('moneda');
            $table->string('garantia');
            $table->boolean('en_oferta')->default(false);
            $table->dateTime('ultima_actualizacion');
            $table->foreignId('proveedor_id')->constrained('proveedores')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('producto_id')->constrained('productos')->onDelete('restrict')->onUpdate('cascade');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proveedor_productos');
    }
};
