<?php

namespace App\Console\Commands;

use App\Services\ProductoSyncService;
use Illuminate\Console\Command;

class GetProductByClaveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:product-by-clave
                            {filtro : nombre del filtro a buscar (ej. clave)}
                            {valor : La clave del producto a buscar}
                            {--upc=true}
                            {--promos=true}
                            {--MonedaPesos=true}
                            {--exist=2}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Busca un producto específico por su clave en CVA';

    /**
     * Execute the console command.
     */
    public function handle(ProductoSyncService $serviceapi)
    {
       // 1. Obtener argumentos
        $filtroNombre = $this->argument('filtro');
        $valor = $this->argument('valor');

        // 2. Validaciones (Nota: si están en el signature sin "?", Laravel ya obliga a que existan)
        if (empty($valor)) {
            $this->error('❌ Debes proporcionar un valor');
            return Command::FAILURE;
        }

        // 3. Construir filtros
        $filters = [
            $filtroNombre => $valor,
            'exist'       => $this->option('exist'),
            'MonedaPesos' => $this->option('MonedaPesos'),
            'promos'      => $this->option('promos'),
            'upc'         => $this->option('upc'),
        ];

        // Limpiar nulos
        $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');

        // Corregido: Usamos $filtroNombre en lugar de $filtro
        $this->info("🔍 Buscando producto con filtro: {$filtroNombre}, valor: {$valor}");

        $startTime = microtime(true);
        
        try {
            // ✅ CORRECCIÓN 3: Pasar solo la clave, no los filtros completos
            $producto = $serviceapi->getOneByClave($filters);
            
            $duration = round(microtime(true) - $startTime, 2);

            if (!$producto) {
                $this->warn("⚠️  Producto no encontrado");
                return Command::FAILURE;
            }

            $this->info("✅ Producto encontrado en {$duration}s");
            $this->newLine();

            // ✅ CORRECCIÓN 4: Mostrar información del producto correctamente
            $this->table(
                ['Campo', 'Valor'],
                [
                    ['ID', $producto->id ?? 'N/A'],
                    ['Clave', $producto->clave ?? 'N/A'],
                    ['Descripción', $producto->descripcion ?? 'N/A'],
                    ['UPC', $producto->upc ?? 'N/A'],
                    ['Precio', $producto->precio ?? 'N/A'],
                    ['Marca', $producto->marca ?? 'N/A'],
                    ['Disponible', $producto->disponible ?? 'N/A'],
                    ['Disponible CD', $producto->disponibleCD ?? 'N/A'],
                ]
            );

            // Mostrar promociones si existen
            if ($producto->promociones && $producto->promociones->count() > 0) {
                $this->newLine();
                $this->info("🎁 Promociones activas:");
                
                foreach ($producto->promociones as $promo) {
                    $this->table(
                        ['Campo', 'Valor'],
                        [
                            ['Clave', $promo->clave_promocion ?? 'N/A'],
                            ['Descripción', $promo->descripcion_promocion ?? 'N/A'],
                            ['Precio Descuento', $promo->precio_descuento ?? 'N/A'],
                            ['Moneda', $promo->moneda_descuento ?? 'N/A'],
                            ['Vencimiento', $promo->promocion_vencimiento ?? 'N/A'],
                        ]
                    );
                }
            }

        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}