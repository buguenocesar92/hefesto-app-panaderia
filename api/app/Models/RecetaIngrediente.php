<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un insumo dentro de una receta.
 *
 * `insumo_id` apunta a `articulos`, NO a otra tabla: el insumo es el mismo
 * articulo que se puede vender en el mostrador. Esa es la razon de que la
 * bolsa de harina que se vende y la harina del pan salgan del mismo
 * inventario.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $receta_id
 * @property string $insumo_id
 * @property string $cantidad
 * @property string $unidad_id
 * @property string $merma_porcentaje
 */
class RecetaIngrediente extends Model
{
    use HasUuids;

    protected $table = 'receta_ingredientes';

    protected $fillable = [
        'tenant_id',
        'receta_id',
        'insumo_id',
        'cantidad',
        'unidad_id',
        'merma_porcentaje',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'float',
            'merma_porcentaje' => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'insumo_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_id');
    }

    /**
     * Cuanto hay que RETIRAR del almacen para que la receta salga.
     *
     * No es lo mismo lo que dice la receta que lo que sale del saco: si la
     * receta pide 1000 gramos de harina y la merma estimada es 5 %, hay que
     * sacar 1050, no 1000.
     */
    public function cantidadTotal(): float
    {
        return $this->cantidad * (1 + ($this->merma_porcentaje / 100));
    }
}