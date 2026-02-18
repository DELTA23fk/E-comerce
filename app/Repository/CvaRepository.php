<?php

namespace App\Repository;

use App\Data\Cva\ArticuloData;
use App\Data\Request\RequestCotizarcionFleteCvaData;
use App\Data\Response\ApiCvaResponse;
use App\Exceptions\Cva\CvaApiException;
use App\Exceptions\Cva\CvaTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CvaRepository
{
    protected string $URLBASE ;
    protected string $URLBASEFLETE ;

    public function __construct() {
        $this->URLBASE = config('services.cva.api_url');
        $this->URLBASEFLETE = config('services.cva.api_url_flete');
    }

    /**
     * Obtiene token de CVA
     */

    protected function obtenerTokenCva(): string
    {
        return Cache::remember('cva_bearer_token', now()->addHours(11), function () {
            $response = Http::post(rtrim($this->URLBASE, '/') . '/user/login', [
                'user' => config('services.cva.client_id'),
                'password' => config('services.cva.client_secret'),
            ])->throw();
            
            if($response->failed()){
                throw new CvaTokenException(
                    "No se pudo obtener el token de CVA",
                    $response->status(),
                    $response->json()
                );
            }
            $token = $response->json('token') ?? $response->json('access_token');
            
            if (!$token) {
                throw new CvaTokenException("CVA no retornó un token válido");
            }

            return $token;
        });

    }

    /*     
    @return ApiCvaResponse Datos completos de articulos de cva, no apto para actualizaciones sin los filtros adecuados
    */    
    public function getProductsGeneral(array $filters,?int $page = 1):ApiCvaResponse{
        $queryParams = array_merge($filters,['page' => $page]);
        
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->obtenerTokenCva())->get($this->URLBASE . 'catalogo_clientes/lista_precios', $queryParams);


        if ($response->unauthorized()) {
            Cache::forget('cva_bearer_token');
            throw new CvaTokenException("Token expirado o inválido", 401);
        }

        if ($response->failed()) {
            throw new CvaApiException(
                "Error al obtener productos de CVA",
                $response->status(),
                $response->json()
            );
        }
        
        return ApiCvaResponse::from($response->json());
    }

    public function getSingleProductByClave(array $filters):?ArticuloData
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->obtenerTokenCva())
            ->get($this->URLBASE . 'catalogo_clientes/lista_precios', $filters);

        
         if ($response->unauthorized()) {
            Cache::forget('cva_bearer_token');
            throw new CvaTokenException("Token expirado o inválido", 401);
        }

        if ($response->failed()) {
            throw new CvaApiException(
                "Error al obtener producto de CVA",
                $response->status(),
                $response->json()
            );
        }

        $data = $response->json();

        if(empty($data) || !isset($data['id'])){
            return null;
        }

        return ArticuloData::from($data);
    }

    public function cotizarPedido(array $data)
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withHeaders([
            'Content-Type' => 'application/json'
        ])->post($this->URLBASEFLETE, $data);

        $jsonResponse = $response->json();

        // CVA retorna result:0 cuando hay error
        if (isset($jsonResponse['result']) && $jsonResponse['result'] === 0) {
            throw new CvaApiException(
                $jsonResponse['message'] ?? 'Error en cotización de flete',
                $response->status(),
                $jsonResponse
            );
        }

        if ($response->failed()) {
            throw new CvaApiException(
                "Error al cotizar flete con CVA",
                $response->status(),
                $jsonResponse
            );
        }

        return $jsonResponse;
    
    }
    public function getCatalogoEstados()
    {
        // Nota: Aquí podrías añadir headers de autenticación si CVA te los pide
        $response = Http::get("{$this->URLBASE}catalogo_clientes/ciudades");


        if ($response->failed()) {
            throw new CvaApiException(
                "Error al cotizar flete con CVA",
                $response->status()
            );
        }

        if ($response->successful()) {
            return $response->json();
        }

         throw new CvaApiException(
                "Error al conectar con CVA",
                $response->status()
        );
    }

    public function crearOrden(array $data){
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->obtenerTokenCva())
            ->post($this->URLBASE.'pedidos_web/crear_orden',$data);

        if ($response->failed()) {
            $errorEspecifico = $response->json('message');
            throw new CvaApiException(
                "Error al registrar el pedido con CVA: {$errorEspecifico}",
                $response->status()
            );
        }

       

        if ($response->successful()) {
            return $response->json();
        }

         throw new CvaApiException(
                "Error al conectar con CVA",
                $response->status()
        );
        
    }

    
}
