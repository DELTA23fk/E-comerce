<?php

namespace App\Repository;

use App\Data\Cva\ArticuloData;
use App\Data\Request\RequestCotizarcionFleteCvaData;
use App\Data\Response\ApiCvaResponse;
use App\Exceptions\Cva\CvaApiException;
use App\Exceptions\Cva\CvaTokenException;
use App\Models\Proveedor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Repository de comunicación HTTP con la API de CVA.
 *
 * Los filtros son un detalle de implementación de cada caso de uso.
 * Los llamadores (CvaSyncService) solo indican QUÉ quieren obtener,
 * no CÓMO construir la query para CVA.
 *
 * ─── FILTROS BASE (siempre presentes) ────────────────────────────────────────
 *   MonedaPesos, upc, completos
 *
 * ─── FILTROS SYNC INICIAL (catálogo completo) ─────────────────────────────────
 *   + dt, dc, images, exist=2, promos
 *   Los filtros del Command se fusionan aquí y tienen prioridad para
 *   permitir sobreescribir valores (ej. --exist=1, --images=0)
 *
 * ─── FILTROS ACTUALIZACIÓN (precio + stock + promos) ─────────────────────────
 *   + exist=2, promos
 *   Sin imágenes ni descripciones — solo lo necesario para actualizar datos
 *
 * ─── FILTROS PRODUCTO INDIVIDUAL ─────────────────────────────────────────────
 *   + clave, promos
 */

class CvaRepository
{
   protected string $URLBASE;
    protected string $URLBASEFLETE;

    // ─── Filtros requeridos en todas las llamadas al catálogo ─────────────────
    private const FILTROS_BASE = [
        'MonedaPesos' => 'true',
        'upc'         => 'true',
        'completos'   => '1',
        'sucursales' => 'true', // incluye datos de sucursales para stock
    ];

    // ─── Filtros para sync inicial: catálogo completo con todos los datos ─────
    private const FILTROS_SYNC_INICIAL = [
        'dt'     => 'true',  // descripción técnica
        'dc'     => 'true',  // disponibilidad en CD
        'images' => '1',     // imágenes
        'exist'  => '2',     // todos los productos (con y sin stock)
        'promos' => 'true',  // promociones activas
    ];

    // ─── Filtros para actualizaciones: solo precio + stock + promos ───────────
    // Sin imágenes ni descripciones para reducir el tamaño de la respuesta
    private const FILTROS_ACTUALIZACION = [
        'exist'  => '2',     // todos (con y sin stock)
        'promos' => 'true',  // datos de promoción
    ];

    public function __construct()
    {
        $this->URLBASE      = config('services.cva.api_url');
        $this->URLBASEFLETE = config('services.cva.api_url_flete');
    }

    private function obtenerPorcentajeUtilidadDefault(): int
    {
        return cache()->remember('cva_porcentaje_utilidad_default', now()->addDay(), function () {
            $proveedor = Proveedor::where('activo', true)
                ->where('codigo_proveedor', 'cva')
                ->whereNotNull('porcentaje_utilidad')
                ->first();

            // Retornamos el valor, o un default (0) si no existe el proveedor
            return $proveedor ? (int) $proveedor->porcentaje_utilidad : 0;
        });
    }
    // =========================================================================
    // ENTRADA UNIFICADA
    // =========================================================================

    /**
     * Punto de entrada único para consultas al catálogo de CVA.
     *
     * Tipos disponibles:
     *   'general'      → catálogo completo con imágenes, dt, dc (sync inicial)
     *   'actualizacion'→ solo precio + stock + promos (actualizaciones periódicas)
     *
     * @throws \InvalidArgumentException Si el tipo no es reconocido
     */
    public function obtenerProductosPara(string $tipoConsulta, int $page = 1, array $filtros = []): ApiCvaResponse
    {
        return match ($tipoConsulta) {
            'general'       => $this->getProductsGeneral($filtros, $page),
            'actualizacion' => $this->getProductosParaActualizacion($page),
            default         => throw new \InvalidArgumentException(
                "Tipo de consulta desconocido: '{$tipoConsulta}'. Usa: general|actualizacion"
            ),
        };
    }

    // =========================================================================
    // SYNC INICIAL — filtros externos del Command fusionados con los base
    // =========================================================================

    /**
     * Catálogo completo paginado para sync inicial.
     *
     * Los filtros del Command (--exist, --images, --dt, etc.) se fusionan
     * con los filtros base y los de sync inicial. El Command tiene prioridad
     * para permitir sobreescribir valores según la necesidad.
     *
     * Uso: CvaSyncService::obtenerPaginaDeProductos($filtros, $pagina)
     */
    private function getProductsGeneral(array $filtros = [], int $page = 1): ApiCvaResponse
    {
        $params = array_merge(
            self::FILTROS_BASE,
            self::FILTROS_SYNC_INICIAL,
            $filtros,            // filtros del Command tienen prioridad
            ['porcentaje' => $this->obtenerPorcentajeUtilidadDefault() ?? 0, 'page' => $page]
        );

        return $this->ejecutarGet('catalogo_clientes/lista_precios', $params);
    }

    // =========================================================================
    // ACTUALIZACIONES — filtros encapsulados, sin parámetros externos
    // =========================================================================

    /**
     * Todos los productos con precio, stock y datos de promoción.
     *
     * CVA devuelve todo en el mismo endpoint. Los filtros están encapsulados
     * aquí — el orquestador y los jobs no necesitan saber qué parámetros
     * requiere CVA para este caso de uso.
     *
     * Uso: CvaSyncService::paginarActualizacion($pagina)
     */
    private function getProductosParaActualizacion(int $page = 1): ApiCvaResponse
    {
        $params = array_merge(
            self::FILTROS_BASE,
            self::FILTROS_ACTUALIZACION,
            ['porcentaje' => $this->obtenerPorcentajeUtilidadDefault() ?? 0, 'page' => $page]
        );

        return $this->ejecutarGet('catalogo_clientes/lista_precios', $params);
    }

    // =========================================================================
    // PRODUCTO INDIVIDUAL
    // =========================================================================

    /**
     * Un único producto por su clave/SKU en CVA.
     * Usado para webhooks y sincronización en tiempo real.
     *
     * Uso: CvaSyncService::obtenerProductoPorId($clave)
     */
    public function getSingleProductByClave(string $clave): ?ArticuloData
    {
        $params = array_merge(self::FILTROS_BASE, [
            'clave'  => $clave,
            'promos' => 'true',
            'porcentaje' => $this->obtenerPorcentajeUtilidadDefault() ?? 0,
        ]);

        $response = $this->ejecutarGetRaw('catalogo_clientes/lista_precios', $params);
        $data     = $response->json();

        if (empty($data) || !isset($data['id'])) {
            return null;
        }

        return ArticuloData::from($data);
    }

    // =========================================================================
    // PEDIDOS Y FLETE
    // =========================================================================

    public function cotizarPedido(array $data): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($this->URLBASEFLETE, $data);
        $jsonResponse = $response->json();

        if (isset($jsonResponse['result']) && $jsonResponse['result'] === 0) {
            throw new CvaApiException(
                $jsonResponse['message'] ?? 'Error en cotización de flete',
                $response->status(),
                $jsonResponse
            );
        }

        if ($response->failed()) {
            throw new CvaApiException("Error al cotizar flete con CVA", $response->status(), $jsonResponse);
        }

        return $jsonResponse;
    }

    public function getCatalogoEstados(): array
    {
        $response = Http::get("{$this->URLBASE}catalogo_clientes/ciudades");

        if ($response->failed()) {
            throw new CvaApiException("Error al obtener catálogo de estados con CVA", $response->status());
        }

        return $response->json();
    }

    public function crearOrden(array $data): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->obtenerTokenCva())
            ->post($this->URLBASE . 'pedidos_web/crear_orden', $data);

        if ($response->failed()) {
            throw new CvaApiException(
                "Error al registrar el pedido con CVA: " . $response->json('message'),
                $response->status()
            );
        }

        return $response->json();
    }

    // =========================================================================
    // HELPERS INTERNOS
    // =========================================================================

    /**
     * Ejecuta un GET autenticado y devuelve un ApiCvaResponse.
     */
    private function ejecutarGet(string $endpoint, array $params): ApiCvaResponse
    {
        return ApiCvaResponse::from($this->ejecutarGetRaw($endpoint, $params)->json());
    }

    /**
     * Ejecuta un GET autenticado y devuelve la Response cruda.
     * Maneja 401 (token expirado) y errores HTTP.
     */
    private function ejecutarGetRaw(string $endpoint, array $params): \Illuminate\Http\Client\Response
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->obtenerTokenCva())
            ->get($this->URLBASE . $endpoint, $params);

        if ($response->unauthorized()) {
            Cache::forget('cva_bearer_token');
            throw new CvaTokenException("Token expirado o inválido", 401);
        }

        if ($response->failed()) {
            throw new CvaApiException(
                "Error al obtener datos de CVA [{$endpoint}]",
                $response->status(),
                $response->json()
            );
        }

        if ($response->serverError()) {
            $message = $response->json('message') ?? 'Error del servidor CVA';
            throw new CvaApiException(
                "CVA server error [{$endpoint}]: {$message}",
                $response->status(),
                $response->json()
            );
        }

        return $response;
    }

    /**
     * Obtiene y cachea el token de CVA por 11 horas.
     */
    protected function obtenerTokenCva(): string
    {
        return Cache::remember('cva_bearer_token', now()->addHours(11), function () {
            $response = Http::post(rtrim($this->URLBASE, '/') . '/user/login', [
                'user'     => config('services.cva.client_id'),
                'password' => config('services.cva.client_secret'),
            ]);

            if ($response->failed()) {
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
}
