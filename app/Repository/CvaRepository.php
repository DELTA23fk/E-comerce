<?php

namespace App\Repository;

use App\Data\Cva\ArticuloData;
use App\Data\Response\ApiCvaResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CvaRepository
{
    protected string $URLBASE ;

    public function __construct() {
        $this->URLBASE = config('services.cva.api_url');
    }

    /**
     * Obtiene token de CVA
     */

    protected function getCvaToken(): string
    {
        return Cache::remember('cva_bearer_token', now()->addHours(11), function () {
            $response = Http::post(rtrim($this->URLBASE, '/') . '/user/login', [
                'user' => config('services.cva.client_id'),
                'password' => config('services.cva.client_secret'),
            ])->throw();

            return $response->json('token') ?? $response->json('access_token');
        });
    }
    /*     
    @return ApiCvaResponse Datos completos de articulos de cva, no apto para actualizaciones sin los filtros adecuados
    */    
    public function getProductsGeneral(array $filters,?int $page = 1):ApiCvaResponse{
        $queryParams = array_merge($filters,['page' => $page]);

        $response = Http::withToken($this->getCvaToken())
            ->get($this->URLBASE . 'catalogo_clientes/lista_precios', $queryParams);
        if ($response->unauthorized()) {
            Cache::forget('cva_bearer_token');
            throw new \Exception("Token expirado. Intenta de nuevo.");
        }
        if ($response->failed()) {
            throw new \Exception("CVA API Error: " . $response->status());
        }

        return ApiCvaResponse::from($response->json());
    }

    public function getSingleProductByClave(array $filters):?ArticuloData
    {

        $response = Http::withToken($this->getCvaToken())
            ->get($this->URLBASE . 'catalogo_clientes/lista_precios', $filters);

        
        if ($response->unauthorized()) {
            Cache::forget('cva_bearer_token');
            throw new \Exception("Token expirado. Intenta de nuevo.");
        }

        if ($response->failed()) {
            throw new \Exception("CVA API Error: " . $response->status());
        }
        $data = $response->json();

        if(empty($data) || !isset($data['id'])){
            return null;
        }

        return ArticuloData::from($data);
    }

    
}
