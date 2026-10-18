<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unidad de medida.
 *
 * El factor de conversion vive aqui y no en el articulo: por eso el
 * kilogramo y la bolsa pesan distinto segun el pan, y dos articulos que se
 * venden en bolsas distintas pueden usar dos unidades distintas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 * @property string $unidades_por_base
 */
class UnidadMedida extends Model
{
    use HasUuids;

    protected $table = 'unidades_medida';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'unidades_por_base',
    ];

    protected function casts(): array
    {
        return [
            'unidades_por_base' => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}