<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regla de vencimiento de UN producto.
 *
 * "El pan caduca hoy, el sándwich mañana" no es una regla general: es una
 * regla por producto, y la fija la panaderia.
 *
 * `dias_vigencia` en NULL significa que el producto no caduca. Si no hay
 * fila en esta tabla, o la fila tiene el campo en NULL, la tanda produce el
 * lote SIN fecha de vencimiento. Es preferible un hueco visible a un plazo
 * inventado. Ver docs/05-CONFIGURACION.md.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $articulo_id
 * @property int|null $dias_vigencia
 * @property bool $merma_automatica
 * @property string|null $notas
 */
class Vigencia extends Model
{
    use HasUuids;

    protected $table = 'vigencias';

    protected $fillable = [
        'tenant_id',
        'articulo_id',
        'dias_vigencia',
        'merma_automatica',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'dias_vigencia' => 'integer',
            'merma_automatica' => 'boolean',
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

    /** El producto no tiene regla: el lote sale sin fecha de vencimiento. */
    public function sinVigencia(): bool
    {
        return $this->dias_vigencia === null;
    }
}