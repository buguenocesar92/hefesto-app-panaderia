<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persona que opera la panaderia.
 *
 * La contrasena y el segundo factor los resuelve Authentik. Aqui solo esta
 * el correo con que se cruza y el rol con que se autoriza.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $nombre
 * @property RolOperador $rol
 * @property bool $activo
 */
class Usuario extends Model
{
    use HasUuids;

    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'email',
        'nombre',
        'rol',
        'activo',
    ];

    protected $hidden = [
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolOperador::class,
            'activo' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tandas(): HasMany
    {
        return $this->hasMany(Tanda::class, 'hornero_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'vendedor_id');
    }

    public function mermas(): HasMany
    {
        return $this->hasMany(Merma::class);
    }

    /** Lo que la interfaz necesita para mostrar el menu y los botones. */
    public function aResumen(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'nombre' => $this->nombre,
            'rol' => $this->rol->value,
            'puede_producir' => $this->rol->puedeProducir(),
            'puede_vender' => $this->rol->puedeVender(),
            'puede_registrar_merma' => $this->rol->puedeRegistrarMerma(),
            'puede_ver_reportes_merma' => $this->rol->puedeVerReportesDeMerma(),
            'puede_anular' => $this->rol->puedeAnular(),
            'puede_cerrar_dia' => $this->rol->puedeCerrarDia(),
            'puede_administrar' => $this->rol->puedeAdministrarConfiguracion(),
        ];
    }
}