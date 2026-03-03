<?php

use App\Http\Controllers\Api\V1\Product\ProductoController;
use App\Services\ProductService;
use App\Services\ProductResponseService;
use Tests\TestCase;

class ProductControllerUnitTest extends TestCase {
    private $productService;
    private $responseService;
    private $controller;

    protected function setUp(): void {
        parent::setUp();
        
        $this->productService = \Mockery::mock(ProductService::class);
        $this->responseService = \Mockery::mock(ProductResponseService::class);
        $this->controller = new ProductoController(
            $this->productService,
            $this->responseService
        );
    }

    protected function tearDown(): void {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     *  Test: show - Retorna producto cuando existe
     */
    public function test_returns_product_when_id_exists(): void {
        $productData = [
            'id' => 1,
            'nombre' => 'Laptop HP',
            'codigo_barras' => 'BAR123',
            'precio_base' => 599.99,
        ];

        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('getProductoPorId')
            ->with(1, $request)
            ->once()
            ->andReturn($productData);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with($productData)
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Producto obtenido',
                'data' => $productData,
            ]);

        $response = $this->controller->show($request, 1);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertEquals(1, $response->getData(true)['data']['id']);
        $this->assertEquals('Laptop HP', $response->getData(true)['data']['nombre']);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     *  Test: show - Retorna 404 cuando no existe
     */
    public function test_returns_404_when_product_not_found(): void {
        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('getProductoPorId')
            ->with(999, $request)
            ->once()
            ->andReturn(null);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with(null)
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Producto no encontrado',
            ]);

        $response = $this->controller->show($request, 999);

        $this->assertFalse($response->getData(true)['success']);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     *  Test: porCodigoBarras - Encuentra producto por barcode
     */
    public function test_finds_product_by_barcode(): void {
        $productData = [
            'id' => 5,
            'nombre' => 'Mouse',
            'codigo_barras' => 'BAR456',
            'precio_base' => 25.50,
        ];

        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('buscarPorCodigoBarras')
            ->with('BAR456', $request)
            ->once()
            ->andReturn($productData);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with($productData)
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Producto obtenido',
                'data' => $productData,
            ]);

        $response = $this->controller->porCodigoBarras($request, 'BAR456');

        $this->assertTrue($response->getData(true)['success']);
        $this->assertEquals('Mouse', $response->getData(true)['data']['nombre']);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     *  Test: porCodigoBarras - No encuentra barcode
     */
    public function test_returns_404_when_barcode_not_found(): void {
        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('buscarPorCodigoBarras')
            ->with('NOTEXIST', $request)
            ->once()
            ->andReturn(null);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with(null)
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Producto no encontrado',
            ]);

        $response = $this->controller->porCodigoBarras($request, 'NOTEXIST');

        $this->assertFalse($response->getData(true)['success']);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     *  Test: index - Lista productos con filtros
     */
    public function test_lists_products_with_filters(): void {
        $paginatedData = \Mockery::mock(\Illuminate\Pagination\LengthAwarePaginator::class);
        $paginatedData->shouldReceive('items')->andReturn([
            ['id' => 1, 'nombre' => 'Producto 1'],
            ['id' => 2, 'nombre' => 'Producto 2'],
        ]);

        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('getProductosConFiltros')
            ->with($request)
            ->once()
            ->andReturn($paginatedData);

        $this->responseService
            ->shouldReceive('formatearListadoPaginado')
            ->with($paginatedData, $request)
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Productos obtenidos',
                'data' => [
                    ['id' => 1, 'nombre' => 'Producto 1'],
                    ['id' => 2, 'nombre' => 'Producto 2'],
                ],
            ]);

        $response = $this->controller->index($request);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertCount(2, $response->getData(true)['data']);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * 🔒 SECURITY: SQL injection attempt
     */
    public function test_handles_malicious_sql_in_barcode(): void {
        $maliciousInput = "'; DROP TABLE productos--";
        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('buscarPorCodigoBarras')
            ->with($maliciousInput, $request)
            ->once()
            ->andReturn(null);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with(null)
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Producto no encontrado',
            ]);

        $response = $this->controller->porCodigoBarras($request, $maliciousInput);

        // No debería ejecutar SQL, solo retornar 404
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * 🔒 SECURITY: XSS data en respuesta
     */
    public function test_returns_xss_data_safely_in_json_response(): void {
        $xssData = [
            'id' => 1,
            'nombre' => '<img src=x onerror="alert(1)">',
            'codigo_barras' => '<script>alert("xss")</script>',
        ];

        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('getProductoPorId')
            ->with(1, $request)
            ->once()
            ->andReturn($xssData);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with($xssData)
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Producto obtenido',
                'data' => $xssData,
            ]);

        $response = $this->controller->show($request, 1);
        $json = $response->getData(true);

        // XSS data está en JSON pero no ejecutado
        $this->assertStringContainsString('<img', $json['data']['nombre']);
        $this->assertStringContainsString('<script>', $json['data']['codigo_barras']);
    }

    /**
     * 🔒 SECURITY: Very long barcode input
     */
    public function test_handles_very_long_barcode_gracefully(): void {
        $longBarcode = str_repeat('X', 500);
        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('buscarPorCodigoBarras')
            ->with($longBarcode, $request)
            ->once()
            ->andReturn(null);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with(null)
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Producto no encontrado',
            ]);

        $response = $this->controller->porCodigoBarras($request, $longBarcode);

        // Debería responder normalmente sin errores
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     *  Test: porCodigo - Búsqueda por código de fabricante
     */
    public function test_searches_product_by_manufacturer_code(): void {
        $productData = collect([
            'id' => 3,
            'nombre' => 'Monitor',
            'codigo_fabricante' => 'MFG_123',
        ]);

        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('buscarPorCodigoFabricante')
            ->with('MFG_123', $request)
            ->once()
            ->andReturn($productData);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with($productData)
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Producto obtenido',
                'data' => $productData,
            ]);

        $response = $this->controller->porCodigo($request, 'MFG_123');

        $this->assertTrue($response->getData(true)['success']);
        $this->assertEquals('Monitor', $response->getData(true)['data']['nombre']);
    }

    /**
     *  Test: porUPC - Búsqueda por UPC
     */
    public function test_searches_product_by_upc(): void {
        $productData = [
            'id' => 7,
            'nombre' => 'Teclado',
            'upc' => 'UPC_789',
        ];

        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('buscarPorUPC')
            ->with('UPC_789', $request)
            ->once()
            ->andReturn($productData);

        $this->responseService
            ->shouldReceive('formatearProductoUnico')
            ->with($productData)
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Producto obtenido',
                'data' => $productData,
            ]);

        $response = $this->controller->porUPC($request, 'UPC_789');

        $this->assertTrue($response->getData(true)['success']);
        $this->assertEquals('Teclado', $response->getData(true)['data']['nombre']);
    }

    /**
     *  Test: Controlador maneja excepciones
     */
    public function test_handles_service_exceptions_gracefully(): void {
        $request = \Mockery::mock(\Illuminate\Http\Request::class);

        $this->productService
            ->shouldReceive('getProductoPorId')
            ->with(1, $request)
            ->once()
            ->andThrow(new \Exception('Database connection error'));

        $response = $this->controller->show($request, 1);

        // Debería tener error status
        $this->assertEquals(500, $response->getStatusCode());
    }
}