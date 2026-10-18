<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPedidoTorta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pedido de torta: el encargo con fecha que hay que agendar.
 *
 * POR QUE ESTA SEPARADO DE LAS VENTAS DE MOSTRADOR
 * -----------------------------------------------
 * Una venta de mostrador es pan que sale ahora. Esto es un encargo que se
 * toma hoy, se entrega en tres dias y a veces hay que producirlo el dia
 * anterior porque la torta del cliente se terminaba temprano.
 *
 * Mezclarlos obligaria a llenar de NULL las columnas de cliente, fecha y
 * anticipo en cada venta de pan, y el problema real es otro: un pedido que no
 * esta agendado no existe, y una torta perdida es la perdida mas cara del
 * negocio.
 *
 * El estado NO se deduce de la fecha: un pedido que paso la fecha sin
 * despacharse sigue `confirmado` y aparece como atrasado. Marcarlo perdido
 * solo seria inventar un hecho.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $numero
 * @property string $cliente_nombre
 * @property string|null $cliente_contacto
 * @property string $articulo_id
 * @property int $cantidad
 * @property string|null $tamano
 * @property string|null $sabor
 * @property string|null $diseno
 * @property string|null $mensaje_personalizado
 * @property string $fecha_entrega
 * @property string|null $hora_entrega
 * @property EstadoPedidoTorta $estado
 * @property string $monto_total
 * @property string $anticipo
 * @property string $saldo
 * @property string $creado_por_id
 * @property \Illuminate\Support\Carbon|null $cerrado_en
 * @property string|null $notas
 */
class PedidoTorta extends Model
{
    use HasUuids;

    protected $table = 'pedidos_torta';

    protected $fillable = [
        'tenant_id',
        'numero',
        'cliente_nombre',
        'cliente_contacto',
        'articulo_id',
        'cantidad',
        'tamano',
        'sabor',
        'diseno',
        'mensaje_personalizado',
        'fecha_entrega',
        'hora_entrega',
        'estado',
        'monto_total',
        'anticipo',
        'saldo',
        'creado_por_id',
        'cerrado_en',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'fecha_entrega' => 'date',
            'estado' => EstadoPedidoTorta::class,
            'monto_total' => 'float',
            'anticipo' => 'float',
            'saldo' => 'float',
            'cerrado_en' => 'datetime',
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

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por_id');
    }

    public function despacho(): HasOne
    {
        return $this->hasOne(Despacho::class);
    }

    public function estaAtrasado(?\Illuminate\Support\Carbon $hoy = null): bool
    {
        if ($this->estado->esFinal()) {
            return false;
        }

        $hoy ??= \Illuminate\Support\Carbon::today();

        return $this->fecha_entrega->lessThan($hoy);
    }

    public function estaPagado(): bool
    {
        return $this->saldo <= 0;
    }

    public function recalculaSaldo(): void
    {
        $this->saldo = round($this->monto_total - $this->anticipo, 2);
    }
}