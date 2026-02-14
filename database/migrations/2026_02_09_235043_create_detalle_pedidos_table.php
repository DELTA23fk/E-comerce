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
        Schema::create('detalle_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('pedido_proveedor_id')->nullable()->constrained('pedido_proveedores')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('proveedor_producto_id')->constrained('proveedor_productos')->onDelete('restrict')->onUpdate('cascade');
            $table->string('clave_proveedor');
            $table->integer('cantidad');
            $table->decimal('precio_unitario',15,2)->unsigned();
            $table->decimal('subtotal',15,2)->unsigned();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detalle_pedidos');
    }
};
