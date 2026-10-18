<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Receta: como se hace un producto y CUANTO rinde.
 *
 * El rendimiento es la cifra que gobierna toda la panaderia. "Esta receta
 * rinde 24 unidades" es lo que permite saber cuanta harina gastar en dos
 * tandas y cuando vence lo que sale del horno.
 *
 * Una receta es de UNA VERSION. Cambiar la receta no reescribe la historia:
 * se crea la version 2 y las tandas viejas siguen apuntando a la version 1.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $articulo_id
 * @property string $nombre
 * @property int $version
 * @property string $rendimiento_cantidad
 * @property string $rendimiento_unidad_id
 * @property string|null $notas
 * @property bool $activa
 */
class Receta extends Model
{
    use HasUuids;

    protected $table = 'recetas';

    protected $fillable = [
        'tenant_id',
        'articulo_id',
        'nombre',
        'version',
        'rendimiento_cantidad',
        'rendimiento_unidad_id',
        'notas',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rendimiento_cantidad' => 'float',
            'activa' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** El producto que produce esta receta. */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function unidadRendimiento(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'rendimiento_unidad_id');
    }

    public function ingredientes(): HasMany
    {
        return $this->hasMany(RecetaIngrediente::class);
    }

    public function tandas(): HasMany
    {
        return $this->hasMany(Tanda::class);
    }

    public function esLaVersionVigente(): bool
    {
        return $this->activa;
    }
}