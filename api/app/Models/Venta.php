<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoVenta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta de mostrador.
 *
 * Se abre antes de cobrar y se cierra con el pago. Mientras esta abierta, la
 * caja esta descuadrada: el pan ya salio del inventario pero el dinero no ha
 * entrado. Por eso `VentaController` revisa la hora de apertura y avisa.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $numero
 * @property string|null $lote_cierre_id
 * @property string $fecha
 * @property EstadoVenta $estado
 * @property string $bruto
 * @property string $descuento
 * @property string $total
 * @property \Illuminate\Support\Carbon $abierta_en
 * @property \Illuminate\Support\Carbon|null $cerrada_en
 * @property string $vendedor_id
 * @property string|null $notas
 */
class Venta extends Model
{
    use HasUuids;

    protected $table = 'ventas';

    protected $fillable = [
        'tenant_id',
        'numero',
        'lote_cierre_id',
        'fecha',
        'estado',
        'bruto',
        'descuento',
        'total',
        'abierta_en',
        'cerrada_en',
        'vendedor_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'estado' => EstadoVenta::class,
            'bruto' => 'float',
            'descuento' => 'float',
            'total' => 'float',
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function loteCierre(): BelongsTo
    {
        return $this->belongsTo(LoteCierre::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    public function minutosAbierta(): int
    {
        $fin = $this->cerrada_en ?? now();

        return (int) $this->abierta_en->diffInMinutes($fin);
    }
}