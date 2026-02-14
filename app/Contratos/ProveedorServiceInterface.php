<?php

namespace App\Contratos;

use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Data\Pedidos\PedidoProveedorResponseData;
use App\Models\Cliente;

interface ProveedorServiceInterface
{
    public function crearPedido(PedidoProveedorRequestData $request,Cliente $cliente):array;
    
    public function validarDisponibilidad(array $productos): bool;
    
    public function obtenerEstatus(string $folioPedido): string;
    
    public function soporta(int $proveedorId): bool;

    public function cotizarEnvio(array $productos,Cliente $cliente);
}
