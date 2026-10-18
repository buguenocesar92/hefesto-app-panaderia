<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SentidoMovimiento;
use App\Enums\TipoMovimiento;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento de inventario. ES HISTORICO.
 *
 * Un movimiento no se modifica ni se borra: hay un gatillo en la base que lo
 * prohibe. Si algo quedo mal escrito, la forma de arreglarlo es registrar el
 * movimiento contrario, que queda a la vista. Borrar el error seria borrar la
 * evidencia, y el informe de merma se apoya justamente en esta tabla.
 *
 * La `cantidad` es SIEMPRE positiva. El signo lo pone el `sentido`: con un
 * signo escondido en la cantidad, un movimiento mal escrito dejaria el saldo
 * bien y el inventario mal.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $articulo_id
 * @property string|null $lote_producto_id
 * @property string|null $ubicacion_id
 * @property TipoMovimiento $tipo
 * @property SentidoMovimiento $sentido
 * @property string $cantidad
 * @property string|null $motivo_merma_id
 * @property string|null $referencia
 * @property string|null $usuario_id
 * @property string|null $notas
 */
class MovimientoInventario extends Model
{
    use HasUuids;

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'tenant_id',
        'articulo_id',
        'lote_producto_id',
        'ubicacion_id',
        'tipo',
        'sentido',
        'cantidad',
        'motivo_merma_id',
        'referencia',
        'usuario_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'sentido' => SentidoMovimiento::class,
            'cantidad' => 'float',
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

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteProducto::class, 'lote_producto_id');
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function motivoMerma(): BelongsTo
    {
        return $this->belongsTo(MotivoMerma::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** La cantidad con el signo que aporta al saldo. */
    public function cantidadConSigno(): float
    {
        return $this->cantidad * $this->sentido->suma();
    }
}