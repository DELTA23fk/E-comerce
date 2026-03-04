<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/


/**
 * 🔐 Helper to create an authenticated test user
 * Returns user with sanctum token capability
 */
function createTestUser(array $attributes = []) {
    $defaults = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ];
    
    return App\Models\User::firstOrCreate(
        ['email' => $attributes['email'] ?? $defaults['email']],
        array_merge($defaults, $attributes)
    );
}

/**
 * Helper to create a Producto record along with required foreign models.
 * Accepts the same attributes you would pass to Producto::create().
 */
function createProduct(array $attributes = []) {
    $marca = App\Models\Marca::firstOrCreate(['nombre' => 'M']);
    $categoria = App\Models\Categoria::firstOrCreate(['nombre' => 'C']);
    $sub = App\Models\SubCategoria::firstOrCreate(['nombre' => 'SC']);
    $familia = App\Models\Familia::firstOrCreate(['nombre' => 'F']);
    $grupo = App\Models\Grupo::firstOrCreate(['nombre' => 'G']);

    $defaults = [
        'nombre' => 'default',
        'descripcion' => 'desc',
        'marca_id' => $marca->id,
        'categoria_id' => $categoria->id,
        'sub_categoria_id' => $sub->id,
        'familia_id' => $familia->id,
        'grupo_id' => $grupo->id,
    ];

    return App\Models\Producto::create(array_merge($defaults, $attributes));
}