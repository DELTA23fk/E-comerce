<?php

use App\Models\Producto;
use App\Services\ProductService;
use App\Services\ProductFilterService;
use App\Services\ProductRelationService;
use App\Services\ProductRealtimeUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->filterService = Mockery::mock(ProductFilterService::class);
    $this->relationService = Mockery::mock(ProductRelationService::class);
    $this->realtimeUpdateService = Mockery::mock(ProductRealtimeUpdateService::class);

    // by default allow any query modifications and return the builder unchanged
    $this->filterService->allows('aplicarFiltros')->andReturnUsing(function ($query, $request) {
        return $query;
    });
    $this->relationService->allows('buildRelations')->andReturn([]);

    $this->service = new ProductService(
        $this->filterService,
        $this->relationService,
        $this->realtimeUpdateService
    );
});

it('returns null when there is no product for id', function () {
    $request = new Request();
    // ✅ OPTIMIZED: No product creation needed - direct query
    expect($this->service->getProductoPorId(999999999, $request))
        ->toBeNull();
});

it('can retrieve a product by its id', function () {
    $product = createProduct(['nombre' => 'foo', 'codigo_barras' => 'ABC123']);
    $request = new Request();

    $result = $this->service->getProductoPorId($product->id, $request);
    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe($product->id)
        ->and($result['codigo_barras'])->toBe('ABC123');
});

it('calls realtime updater when include_proveedores flag is true', function () {
    $product = createProduct(['nombre' => 'bar']);
    $request = Request::create('/', 'GET', ['include_proveedores' => '1']);

    // ✅ SECURE: Validate mock is called with correct params and returns expected data
    $this->realtimeUpdateService
        ->shouldReceive('actualizarTodosLosProveedoresConThrottling')
        ->once()  // Must be called exactly once (no duplicate calls)
        ->with($product->id)
        ->andReturn(['omitidos' => 0, 'actualizados' => 5]);  // ✅ Validate response structure

    $result = $this->service->getProductoPorId($product->id, $request);
    
    // ✅ Verify result contains the expected product
    expect($result['id'])->toBe($product->id);
    expect($result['nombre'])->toBe('bar');
});

it('searches by barcode and respects include_proveedores switch', function () {
    $product = createProduct(['nombre' => 'baz', 'codigo_barras' => 'XYZ789']);
    $request = Request::create('/', 'GET', ['include_proveedores' => '1']);

    $this->realtimeUpdateService
        ->shouldReceive('actualizarTodosLosProveedoresConThrottling')
        ->once()
        ->with($product->id)
        ->andReturn(['omitidos' => 0]);

    $result = $this->service->buscarPorCodigoBarras('XYZ789', $request);
    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe($product->id);
});

it('returns null when barcode not found', function () {
    $request = new Request();
    // ✅ SECURITY: Non-existent barcode returns null instead of exception
    $result = $this->service->buscarPorCodigoBarras('NOTEXIST', $request);
    expect($result)->toBeNull();
});