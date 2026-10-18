<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrigenMerma;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perdida de mercaderia, con su motivo.
 *
 * POR QUE NO BASTA EL MOVIMIENTO
 * -----------------------------
 * El movimiento ya dice "salieron 12 unidades". La merma dice POR QUE. Sin
 * el motivo, "merma: 300 unidades" no dice si se perdio pan quemado o si se
 * contou mal tres veces, y el informe de merma no sirve para nada.
 *
 * `motivo_merma_id` es OBLIGATORIO y no admite NULL: es la garantia de que
 * la perdida queda explicada. Ver la migracion 000006.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $articulo_id
 * @property string|null $lote_producto_id
 * @property string $motivo_merma_id
 * @property string $cantidad
 * @property string $fecha
 * @property OrigenMerma $origen
 * @property string|null $usuario_id
 * @property string|null $notas
 */
class Merma extends Model
{
    use HasUuids;

    protected $table = 'mermas';

    protected $fillable = [
        'tenant_id',
        'articulo_id',
        'lote_producto_id',
        'motivo_merma_id',
        'cantidad',
        'fecha',
        'origen',
        'usuario_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'float',
            'fecha' => 'date',
            'origen' => OrigenMerma::class,
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

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(MotivoMerma::class, 'motivo_merma_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** El valor perdido, para el informe. NULL si el articulo no tiene precio. */
    public function valorPerdido(): ?float
    {
        $precio = $this->articulo?->precio_venta;

        if ($precio === null) {
            return null;
        }

        return round($this->cantidad * $precio, 2);
    }
}