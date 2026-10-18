<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoLote;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de producto: mercaderia con una fecha y un origen identificables.
 *
 * POR QUE EXISTE EL LOTE
 * ----------------------
 * El pan que se vendio el martes y el pan que se quemo el martes salieron de
 * tandas distintas. Sin un lote, no hay forma de saber cuando caduca
 * lo que queda ni de que tanda vino lo que se perdio.
 *
 * `cantidad_disponible` NUNCA se edita: la cambia el servicio de movimientos
 * y un gatillo de la base revienta cualquier UPDATE que venga de otro lado.
 * Ver la migracion 000005.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $articulo_id
 * @property string $ubicacion_id
 * @property string|null $tanda_id
 * @property string $codigo
 * @property string $cantidad_inicial
 * @property string $cantidad_disponible
 * @property string $fecha_produccion
 * @property string|null $fecha_vencimiento
 * @property EstadoLote $estado
 */
class LoteProducto extends Model
{
    use HasUuids;

    protected $table = 'lote_productos';

    protected $fillable = [
        'tenant_id',
        'articulo_id',
        'ubicacion_id',
        'tanda_id',
        'codigo',
        'cantidad_inicial',
        'cantidad_disponible',
        'fecha_produccion',
        'fecha_vencimiento',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_inicial' => 'float',
            'cantidad_disponible' => 'float',
            'fecha_produccion' => 'date',
            'fecha_vencimiento' => 'date',
            'estado' => EstadoLote::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function tanda(): BelongsTo
    {
        return $this->belongsTo(Tanda::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    public function mermas(): HasMany
    {
        return $this->hasMany(Merma::class);
    }

    /** Sin fecha de vencimiento significa "no hay regla configurada". */
    public function tieneVigencia(): bool
    {
        return $this->fecha_vencimiento !== null;
    }

    public function estaVencido(?\Illuminate\Support\Carbon $hoy = null): bool
    {
        if ($this->fecha_vencimiento === null) {
            return false;
        }

        $hoy ??= \Illuminate\Support\Carbon::today();

        return $this->fecha_vencimiento->lessThanOrEqualTo($hoy);
    }

    public function diasParaVencer(?\Illuminate\Support\Carbon $hoy = null): ?int
    {
        if ($this->fecha_vencimiento === null) {
            return null;
        }

        $hoy ??= \Illuminate\Support\Carbon::today();

        return (int) $hoy->diffInDays($this->fecha_vencimiento, false);
    }

    public function estaAgotado(): bool
    {
        return $this->cantidad_disponible <= 0;
    }
}