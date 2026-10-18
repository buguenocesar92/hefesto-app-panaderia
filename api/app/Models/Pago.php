<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodoPago;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago de una venta.
 *
 * Es una tabla aparte y no una columna en `ventas` porque una venta se puede
 * pagar en parte: un billete y una tarjeta, o dos tarjetas. El vuelto solo
 * existe en efectivo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $venta_id
 * @property MetodoPago $metodo
 * @property string $monto
 * @property string|null $recibido
 * @property string|null $vuelto
 */
class Pago extends Model
{
    use HasUuids;

    protected $table = 'pagos';

    protected $fillable = [
        'tenant_id',
        'venta_id',
        'metodo',
        'monto',
        'recibido',
        'vuelto',
    ];

    protected function casts(): array
    {
        return [
            'metodo' => MetodoPago::class,
            'monto' => 'float',
            'recibido' => 'float',
            'vuelto' => 'float',
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

    public function necesitaVuelto(): bool
    {
        return $this->metodo->necesitaVuelto();
    }
}