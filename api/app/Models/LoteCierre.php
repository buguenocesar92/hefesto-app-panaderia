<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoLoteCierre;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de cierre: el grupo de ventas que comparten descuento.
 *
 * Es como una panaderia liquida de verdad la mercaderia que se acerca a su
 * vencimiento: no se le pone descuento producto por producto, se abre un lote
 * de cierre con el descuento del dia y se vende todo lo que esta en el.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombre
 * @property string $fecha
 * @property string $descuento_porcentaje
 * @property EstadoLoteCierre $estado
 * @property string $abierto_por_id
 * @property \Illuminate\Support\Carbon|null $cerrado_en
 * @property string|null $notas
 */
class LoteCierre extends Model
{
    use HasUuids;

    protected $table = 'lotes_cierre';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'fecha',
        'descuento_porcentaje',
        'estado',
        'abierto_por_id',
        'cerrado_en',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'descuento_porcentaje' => 'float',
            'estado' => EstadoLoteCierre::class,
            'cerrado_en' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function abiertoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'abierto_por_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function admiteVentas(): bool
    {
        return $this->estado->admiteVentas();
    }

    /**
     * El precio de un articulo con este lote de cierre aplicado.
     *
     * Se redondea a dos decimales porque el precio se cobra en pesos enteros
     * o en centavos: un descuento de 33,333 % no puede dar un precio con seis
     * decimales.
     */
    public function precioConDescuento(float $precio): float
    {
        $factor = 1 - ($this->descuento_porcentaje / 100);

        return round($precio * $factor, 2);
    }
}