<?php

use App\Http\Controllers\Api\V1\Admin\ManagerUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController as ApiAuthController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Client\ClientController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Product\ProductCatalogController;
use App\Http\Controllers\Api\V1\Product\ProductoController;
use App\Http\Controllers\Api\V1\Product\ProductOfferController;
use App\Http\Controllers\Api\V1\Product\ProductProviderController;
use App\Http\Controllers\Api\V1\Product\ProductSearchController;
use App\Http\Controllers\Api\V1\Product\ProductStockController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\Seller\SellerController;
use App\Http\Controllers\Spa\Auth\AuthController as SpaAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    //public routes here ------------------------
        //token
        Route::post('/auth/register', [ApiAuthController::class, 'register'])->name('auth.register')->middleware('guest');
        Route::post('/auth/token', [ApiAuthController::class, 'generateToken'])->name('auth.token')->middleware('guest');

        //cookie
        Route::prefix('spa')->group(function () {
            Route::post('/auth/register', [SpaAuthController::class, 'register'])->name('spa.auth.register')->middleware('guest');
            Route::post('/auth/login', [SpaAuthController::class, 'login'])->name('spa.auth.login')->middleware('guest');

        });
    //-------------------------------------------------------------

    //protected routes here
    Route::middleware('auth:sanctum')->group(function () {
        //API Routes---------------------------
        Route::get('/auth/profile', [ApiAuthController::class, 'profile'])->middleware(['permission:view profile'])->name('auth.profile');

        Route::post('/auth/revoke-tokens', [ApiAuthController::class, 'revokeTokens'])->name('auth.revoke.tokens');

        Route::put('/auth/profile/update', [ApiAuthController::class, 'updateProfile'])->middleware(['permission:edit profile'])->name('auth.profile.update');

        //SPA Routes - COOKIES ----------------------
        Route::prefix('spa')->group(function () {

            Route::post('/auth/logout', [SpaAuthController::class, 'logout'])->name('spa.auth.logout');

            Route::put('/auth/profile/update', [SpaAuthController::class, 'updateProfile'])->middleware(['permission:edit profile'])->name('spa.auth.profile.update');
        });
        //-------------------------------------------------

        Route::middleware('role:customer')->group(function(){
            //GENERAL ROUTES FOR AUTHENTICATED USERS HERE --------------------------
            Route::post('/user/client/register',[ClientController::class,'registerClientByAuthUser'])->name('user.client.register');
            Route::get('/user/client/my',[ClientController::class,'getClientAuth'])->name('user.client.my');
            Route::put('/user/client/update/my',[ClientController::class,'update'])->name('user.client.update.my');
    
        });

        Route::prefix('categorias')->controller(CategoryController::class)->group(function () {
            // --- CRUD principal -------------------------------------------
            Route::get('/','index');                          
            Route::get('/{id}','show');                           
            // Route::post('/',   'store')->middleware('role:admin');                          
            // Route::match(['put','patch'], '/{categoria}', 'update')->middleware('role:admin');
            // Route::delete('/{categoria}', 'destroy')->middleware('role:admin');             

            // --- Gestión de subcategorías -----------------------
            // Route::prefix('/{categoria}/subcategorias')->group(function () {

            //     // Agrega subcategorías sin quitar las que ya existen
            //     Route::post('/attach',  'attachSubcategorias');

            //     // Quita subcategorías específicas
            //     Route::delete('/detach','detachSubcategorias');

            //     // Reemplaza TODAS las subcategorías con el nuevo conjunto
            //     Route::put('/sync','syncSubcategorias');
            // })->middleware('role:admin');

            // --- Caché ---------------------------------------------------
            Route::post('/cache/refresh', 'refreshCache');        // POST   /api/categorias/cache/refresh
        });

        Route::prefix('familias')->controller(FamilyController::class)->group(function () {
            Route::get('/','index');    
            Route::get('/{id}','show');     
            Route::post('/','store')->middleware('role:admin');    
            // Route::match(['put','patch'],'/{familia}','update')->middleware('role:admin');  
            // Route::delete('/{familia}',              'destroy')->middleware('role:admin');  
            Route::post('/cache/refresh','refreshCache');
        });

        Route::prefix('grupos')->controller(GroupController::class)->group(function () {
            Route::get('/','index');      
            Route::get('/{id}','show');      
            // Route::post('/',                       'store')->middleware('role:admin');     
            // Route::match(['put','patch'],'/{grupo}','update')->middleware('role:admin');    
            // Route::delete('/{grupo}',              'destroy')->middleware('role:admin');   
            Route::post('/cache/refresh','refreshCache');
        });

        Route::prefix('marcas')->controller(BrandController::class)->group(function () {
            Route::get('/','index');
            Route::get('/{id}','show');
            // Route::post('/',                       'store')->middleware('role:admin'); 
            // Route::match(['put','patch'],'/{marca}','update')->middleware('role:admin');
            // Route::delete('/{marca}',              'destroy')->middleware('role:admin');
            Route::post('/cache/refresh','refreshCache');
        });

        Route::prefix('proveedores')->controller(ProviderController::class)->group(function () {
            Route::get('/','index');        
            Route::get('/codigo/{codigo}','showByCodigo');
            Route::get('/{id}','show');
            // Route::post('/','store')->middleware('role:admin');
            // Route::match(['put','patch'],'/{proveedor}', 'update')->middleware('role:admin');
            Route::patch('/{proveedor}/toggle-activo','toggleActivo')->middleware('role:admin');
            // Route::delete('/{proveedor}',                'destroy')->middleware('role:admin'); 
            Route::post('/cache/refresh','refreshCache');
        });

        Route::prefix('productos')->group(function(){
            // Listado general con filtros básicos
            Route::get('/', [ProductoController::class, 'index'])->name('productos');
            
            // Detalle de un producto
            Route::get('/{id}', [ProductoController::class, 'show']);
            
            // Producto por código
            Route::get('/codigo/{codigo}', [ProductoController::class, 'porCodigo']);
            
            // Producto por código de barras
            Route::get('/barras/{codigoBarras}', [ProductoController::class, 'porCodigoBarras']);
            
            // Producto por UPC
            Route::get('/upc/{upc}', [ProductoController::class, 'porUPC']);
        });

        Route::prefix('productos/busqueda')->group(function () {
            // Búsqueda general
            Route::get('/general', [ProductSearchController::class, 'busquedaGeneral']);
            
            // Búsqueda por texto (nombre, descripción)
            Route::get('/texto', [ProductSearchController::class, 'porTexto']);
            
            // Búsqueda avanzada con múltiples criterios
            Route::post('/avanzada', [ProductSearchController::class, 'busquedaAvanzada']);
            
            // Sugerencias/autocompletado
            Route::get('/sugerencias', [ProductSearchController::class, 'sugerencias']);
        });

        Route::prefix('productos/catalogo')->group(function () {
            // Por categoría
            Route::get('/categoria/{categoriaId}', [ProductCatalogController::class, 'porCategoria']);
            
            // Por sub-categoría
            Route::get('/sub-categoria/{subCategoriaId}', [ProductCatalogController::class, 'porSubCategoria']);
            
            // Por familia
            Route::get('/familia/{familiaId}', [ProductCatalogController::class, 'porFamilia']);
            
            // Por grupo
            Route::get('/grupo/{grupoId}', [ProductCatalogController::class, 'porGrupo']);
            
            // Por marca
            Route::get('/marca/{marcaId}', [ProductCatalogController::class, 'porMarca']);
            
            // Productos relacionados
            Route::get('/{id}/relacionados', [ProductCatalogController::class, 'productosRelacionados']);
        });

        Route::prefix('productos/proveedores')->group(function () {
            // Productos de un proveedor específico
            Route::get('/{proveedorId}', [ProductProviderController::class, 'porProveedor']);
            
            // Comparar precios entre proveedores
            Route::get('/{productoId}/comparar-precios', [ProductProviderController::class, 'compararPrecios']);
            
            // Mejor precio disponible
            Route::get('/{productoId}/mejor-precio', [ProductProviderController::class, 'mejorPrecio']);
            
            // Historial de precios
            Route::get('/{productoId}/historial-precios', [ProductProviderController::class, 'historialPrecios']);
            
            // Proveedores por producto
            Route::get('/{productoId}/listado', [ProductProviderController::class, 'proveedoresPorProducto']);

        });

        Route::prefix('productos/stock')->group(function () {
            // Productos con stock disponible
            Route::get('/disponibles', [ProductStockController::class, 'disponibles']);
            
            // Productos sin stock
            Route::get('/agotados', [ProductStockController::class, 'agotados']);
            
            // Stock por producto
            Route::get('/{productoId}', [ProductStockController::class, 'stockPorProducto']);
            
            // Stock bajo (próximos a agotarse)
            Route::get('/bajo-stock', [ProductStockController::class, 'stockBajo']);
            
            // Verificar disponibilidad
            Route::post('/verificar', [ProductStockController::class, 'verificarDisponibilidad']);
        });

        Route::prefix('productos/ofertas')->group(function () {
            // Todos los productos en oferta
            Route::get('/', [ProductOfferController::class, 'productosEnOferta']);
            
            // Ofertas por categoría
            Route::get('/categoria/{categoriaId}', [ProductOfferController::class, 'ofertasPorCategoria']);
            
            // Promociones activas
            Route::get('/promociones', [ProductOfferController::class, 'promocionesActivas']);
            
            // Descuentos mayores a X%
            Route::get('/descuentos/{porcentaje}', [ProductOfferController::class, 'descuentosMayoresA']);
            
            // Ofertas del día
            Route::get('/del-dia', [ProductOfferController::class, 'ofertasDelDia']);
            
            // Ofertas por vencer
            Route::get('/por-vencer', [ProductOfferController::class, 'ofertasPorVencer']);
        });

        Route::prefix('productos/filtros')->group(function () {
            // Por rango de precios
            Route::get('/precio', [ProductoController::class, 'porRangoPrecio']);
            
            // Productos más recientes
            Route::get('/recientes', [ProductoController::class, 'recientes']);
            
            // Productos más vendidos (si tienes esta data)
            Route::get('/populares', [ProductoController::class, 'populares']);
            
            // Productos destacados
            Route::get('/destacados', [ProductoController::class, 'destacados']);
        });

        Route::prefix('productos/{productoId}/imagenes')->group(function () {
            // Todas las imágenes de un producto
            Route::get('/', [ProductoController::class, 'imagenes']);
            
            // Imagen principal
            Route::get('/principal', [ProductoController::class, 'imagenPrincipal']);
        });

        Route::prefix('productos/estadisticas')->group(function () {
            // Resumen general
            Route::get('/resumen', [ProductoController::class, 'resumenEstadisticas']);
            
            // Conteo por categoría
            Route::get('/por-categoria', [ProductoController::class, 'conteoPorCategoria']);
            
            // Conteo por marca
            Route::get('/por-marca', [ProductoController::class, 'conteoPorMarca']);
        });

        //----------Admin
        Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function(){
            // Consulta de tipos de usuario
            Route::get('tipos-usuario', [ManagerUserController::class, 'getTypesUser'])
                ->name('tipos-usuario');
            
            // Gestión de usuarios
            Route::post('usuarios', [ManagerUserController::class, 'registerUserCustom'])
                ->name('usuarios.store');
            Route::put('usuarios/{usuarioId}', [ManagerUserController::class, 'updateUser'])
                ->name('usuarios.update');
            
            // Asignación de roles (recurso anidado)
            Route::put('usuarios/{usuarioId}/rol', [ManagerUserController::class, 'setRole'])
                ->name('usuarios.rol.update');
            Route::delete('usuarios/{usuarioId}/rol', [ManagerUserController::class, 'removeRole'])
                ->name('usuarios.rol.destroy');
        });

        //vendedor
        Route::prefix('vendedor')->controller(SellerController::class)->group(function(){
            Route::post('registrar/cliente','RegistrarCliente');
            Route::get('/obtener/cliente/telefono','obtenerClientePorTelefono');
            Route::get('/obtener/cliente/rfc','obtenerClientePorRfc');
        });

        //COTIZAR PEDIDOS
        Route::prefix('pedidos')->group(function(){
            Route::post('cotizar/envios/productos',[OrderController::class,'cotizarEnvio']);
            Route::post('generar',[OrderController::class,'store']);
            Route::post('{pedido}/confirmar',[OrderController::class,'confirmarPedido']);

        });
        //pedido

    });
});

