<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Articulo del inventario.
 *
 * UN MISMO ARTICULO SE VENDE Y SE CONSUME
 * ---------------------------------------
 * La bolsa de harina que se vende en el mostrador y la harina que se usa en
 * el pan son el MISMO registro, con el mismo stock. Por eso este modelo tiene
 * los dos interruptores:
 *
 *  - `se_vende`: aparece en el mostrador.
 *  - `es_insumo`: se puede consumir en una receta.
 *
 * Si se guardaran en dos tablas, vender una bolsa restaria de un inventario y
 * gastar harina en una tanda restaria de otro, y la merma seria invisible.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sku
 * @property string $nombre
 * @property string|null $descripcion
 * @property string $unidad_base_id
 * @property string $unidad_compra_id
 * @property string $unidad_venta_id
 * @property string|null $precio_venta
 * @property string|null $stock_minimo
 * @property bool $se_vende
 * @property bool $es_insumo
 * @property bool $activo
 */
class Articulo extends Model
{
    use HasUuids;

    protected $table = 'articulos';

    protected $fillable = [
        'tenant_id',
        'sku',
        'nombre',
        'descripcion',
        'unidad_base_id',
        'unidad_compra_id',
        'unidad_venta_id',
        'precio_venta',
        'stock_minimo',
        'se_vende',
        'es_insumo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'float',
            'stock_minimo' => 'float',
            'se_vende' => 'boolean',
            'es_insumo' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unidadBase(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_base_id');
    }

    public function unidadCompra(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_compra_id');
    }

    public function unidadVenta(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_venta_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(LoteProducto::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    public function vigencia(): BelongsTo
    {
        return $this->belongsTo(Vigencia::class);
    }

    public function recetas(): HasMany
    {
        return $this->hasMany(Receta::class);
    }

    public function mermas(): HasMany
    {
        return $this->hasMany(Merma::class);
    }

    /** Sin precio no se puede cobrar. El mostrador lo avisa antes de vender. */
    public function tienePrecio(): bool
    {
        return $this->precio_venta !== null;
    }
}