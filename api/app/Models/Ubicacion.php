<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoUbicacion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Donde esta guardada la mercaderia: la bodega, el mostrador o el
 * refrigerador.
 *
 * Sin esto, la pregunta "donde quedo esta bolsa de pan" no tiene respuesta y
 * el sobrante termina mermado por no encontrarlo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 * @property TipoUbicacion $tipo
 * @property bool $activo
 */
class Ubicacion extends Model
{
    use HasUuids;

    protected $table = 'ubicaciones';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'tipo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoUbicacion::class,
            'activo' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(LoteProducto::class);
    }

    public function esDeVenta(): bool
    {
        return $this->tipo->esDeVenta();
    }
}