<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Registro contable inmutable de cada evento de pago/reembolso.
 *
 * @property int         $id
 * @property int         $pedido_id
 * @property int|null    $pedido_proveedor_id
 * @property string      $tipo                 pago|reembolso|reembolso_parcial|contracargo|ajuste
 * @property string      $gateway              mercadopago|paypal
 * @property string|null $gateway_order_id     preference_id (MP) | order_id (PayPal)
 * @property string      $gateway_payment_id   payment_id (MP) | capture_id (PayPal)
 * @property string      $status               pending|approved|rejected|cancelled|refunded|...
 * @property float       $monto
 * @property string      $moneda
 * @property array|null  $metadata
 * @property array|null  $response_raw
 * @property string|null $motivo
 * @property string|null $ip_cliente
 * @property string|null $user_agent
 */
class TransaccionPago extends Model
{
    use SoftDeletes;

    protected $table = 'transacciones_pagos';

    protected $fillable = [
        'pedido_id',
        'pedido_proveedor_id',
        'tipo',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'status',
        'monto',
        'moneda',
        'metadata',
        'response_raw',
        'motivo',
        'ip_cliente',
        'user_agent',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'response_raw' => 'array',
        'monto'        => 'float',
    ];

    // ── Relaciones ───────────────────────────────────────────────────────────

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function pedidoProveedor(): BelongsTo
    {
        return $this->belongsTo(PedidoProveedor::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopePagos($query)
    {
        return $query->where('tipo', 'pago');
    }

    public function scopeReembolsos($query)
    {
        return $query->whereIn('tipo', ['reembolso', 'reembolso_parcial']);
    }

    public function scopeAprobados($query)
    {
        return $query->where('status', 'approved');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function aprobado(): bool
    {
        return $this->status === 'approved';
    }

    public function esReembolso(): bool
    {
        return in_array($this->tipo, ['reembolso', 'reembolso_parcial'], true);
    }
}