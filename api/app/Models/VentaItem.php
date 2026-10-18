<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una linea de venta.
 *
 * `lote_producto_id` NO es un detalle: sin el lote no se sabe que pan se
 * vendio ni cuando caduca lo que queda. Es lo que permite responder "de los
 * 30 panes del martes, cuantos eran del lote que se quemo".
 *
 * El precio se COPIA al vender. Si manana suben los precios, la venta de ayer
 * tiene que seguir cuadrando con el precio de ayer.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $venta_id
 * @property string $articulo_id
 * @property string $lote_producto_id
 * @property string $cantidad
 * @property string $precio_unitario
 * @property string $descuento_unitario
 * @property string $subtotal
 */
class VentaItem extends Model
{
    use HasUuids;

    protected $table = 'venta_items';

    protected $fillable = [
        'tenant_id',
        'venta_id',
        'articulo_id',
        'lote_producto_id',
        'cantidad',
        'precio_unitario',
        'descuento_unitario',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'float',
            'precio_unitario' => 'float',
            'descuento_unitario' => 'float',
            'subtotal' => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteProducto::class, 'lote_producto_id');
    }

    /** El precio cobrado por unidad, ya con el descuento del lote de cierre. */
    public function precioEfectivo(): float
    {
        return $this->precio_unitario - $this->descuento_unitario;
    }
}