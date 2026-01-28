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
        Schema::create('proveedor_producto_promocions', function (Blueprint $table) {
            $table->id();
            $table->decimal('total_descuento',15,2)->nullable()->unsigned();
            $table->string('moneda_descuento',10)->nullable();
            $table->decimal('precio_con_descuento',15,2)->nullable()->unsigned();
            $table->string('clave_promocion')->nullable();
            $table->text('descripcion_promocion')->nullable();
            $table->string('expiracion')->nullable();
            $table->integer('disponible_en_promocion')->nullable();
            $table->decimal('precio_oferta',15,2)->nullable()->unsigned();
            $table->decimal('precio_regular',15,2)->nullable()->unsigned();
            $table->foreignId('proveedor_producto_id')->constrained('proveedor_productos')->onDelete('restrict')->onUpdate('cascade');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proveedor_producto_promocions');
    }
};
