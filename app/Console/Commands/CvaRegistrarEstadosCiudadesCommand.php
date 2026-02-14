<?php

namespace App\Console\Commands;

use App\Services\ProviderCitiesLimit;
use App\Services\ProviderCountriesLimit;
use Illuminate\Console\Command;

class CvaRegistrarEstadosCiudadesCommand extends Command
{
    // Nombre del comando en la terminal
    protected $signature = 'cva:sync-catalogs';
    protected $description = 'Sincroniza el catálogo de estados y ciudades desde la API de CVA';

    public function handle(ProviderCitiesLimit $service)
    {
        $this->info("Iniciando sincronización para el proveedor cva...");

        try {
            $cantidad = $service->syncEstadosYCiudadesCVA();
            $this->info("Sincronización completada exitosamente. Se procesaron {$cantidad} estados.");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error durante la sincronización: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
