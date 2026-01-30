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
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo_fabricante')->nullable();
            $table->string('codigo_barras')->nullable()->unique();
            $table->string('upc')->nullable()->unique();
            $table->text('descripcion');
            $table->text('descripcion_tecnica')->nullable();
            $table->foreignId('marca_id')->constrained('marcas')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('categoria_id')->constrained('categorias')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('sub_categoria_id')->constrained('sub_categorias')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('familia_id')->constrained('familias')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('grupo_id')->constrained('grupos')->onDelete('restrict')->onUpdate('cascade');
            $table->softDeletes();
            $table->timestamps();

            $table->index('codigo_fabricante');
            $table->index('codigo_barras');
            $table->index('upc');
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
