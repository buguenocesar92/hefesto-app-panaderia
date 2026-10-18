<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Motivo de merma.
 *
 * Es lo que hace que el informe signifique algo. Sin motivo, "merma: 300
 * unidades" no dice si se perdio pan quemado o si se contou mal tres veces.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $merma_por_vencimiento
 * @property bool $activo
 */
class MotivoMerma extends Model
{
    use HasUuids;

    protected $table = 'motivos_merma';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'descripcion',
        'merma_por_vencimiento',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'merma_por_vencimiento' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function mermas(): HasMany
    {
        return $this->hasMany(Merma::class);
    }

    /** El motivo que usa el trabajo programado cuando un lote vence. */
    public const CODIGO_VENCIMIENTO = 'vencido';
}