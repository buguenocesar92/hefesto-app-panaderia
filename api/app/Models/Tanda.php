<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoTanda;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tanda: una corrida de una receta, en un momento.
 *
 * LA DIFERENCIA ENTRE PLAN Y REALIDAD
 * ----------------------------------
 * `cantidad_planificada` es lo que se quiso hacer; `cantidad_producida` es lo
 * que salio del horno. Que sean dos columnas y no una es a proposito: la
 * diferencia entre las dos es informacion de la panaderia, y sobrescribir una
 * con la otra seria perderla.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $receta_id
 * @property string $numero
 * @property string $fecha
 * @property string|null $cantidad_planificada
 * @property string|null $cantidad_producida
 * @property EstadoTanda $estado
 * @property string $hornero_id
 * @property \Illuminate\Support\Carbon|null $cerrada_en
 * @property string|null $notas
 */
class Tanda extends Model
{
    use HasUuids;

    protected $table = 'tandas';

    protected $fillable = [
        'tenant_id',
        'receta_id',
        'numero',
        'fecha',
        'cantidad_planificada',
        'cantidad_producida',
        'estado',
        'hornero_id',
        'cerrada_en',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'cantidad_planificada' => 'float',
            'cantidad_producida' => 'float',
            'estado' => EstadoTanda::class,
            'cerrada_en' => 'datetime',
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

    public function hornero(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'hornero_id');
    }

    /** Los lotes que salieron de esta corrida. */
    public function lotes(): HasMany
    {
        return $this->hasMany(LoteProducto::class);
    }

    public function estaCerrada(): bool
    {
        return $this->estado->estaCerrada();
    }
}