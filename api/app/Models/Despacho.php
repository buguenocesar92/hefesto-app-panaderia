<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Despacho de un pedido de torta.
 *
 * Hay UN despacho por pedido: si la torta se entrega en dos tramos, es otro
 * pedido. La restriccion `unique(tenant_id, pedido_torta_id)` lo garantiza en
 * la base, no solo en la aplicacion.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pedido_torta_id
 * @property string $fecha
 * @property string|null $hora_entrega
 * @property string|null $direccion
 * @property string|null $entregado_a
 * @property string|null $lote_producto_id
 * @property string|null $recibido_por_id
 * @property string|null $notas
 */
class Despacho extends Model
{
    use HasUuids;

    protected $table = 'despachos';

    protected $fillable = [
        'tenant_id',
        'pedido_torta_id',
        'fecha',
        'hora_entrega',
        'direccion',
        'entregado_a',
        'lote_producto_id',
        'recibido_por_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoTorta::class, 'pedido_torta_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteProducto::class, 'lote_producto_id');
    }

    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'recibido_por_id');
    }
}