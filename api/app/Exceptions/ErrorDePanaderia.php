<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Error del negocio de la panaderia: no hay insumos para la tanda, el lote ya
 * esta mermado, la venta quedo abierta demasiado tiempo.
 *
 * No es un error del sistema ni una falla de conexion: es una situacion que
 * la panaderia tiene que resolver. Por eso se devuelve 422 con un codigo
 * estable, para que la interfaz pueda reaccionar sin adivinar. Ver
 * docs/09-API.md.
 */
class ErrorDePanaderia extends RuntimeException
{
    public function __construct(
        string $mensaje,
        private readonly string $codigo = 'error_de_panaderia'
    ) {
        parent::__construct($mensaje);
    }

    /** Codigo estable que la interfaz usa, no el texto. */
    public function codigo(): string
    {
        return $this->codigo;
    }

    public static function sinInsumos(string $articulo): self
    {
        return new self(
            "No hay stock suficiente de {$articulo} para esa tanda.",
            'sin_insumos'
        );
    }

    public static function loteVencido(string $codigoLote): self
    {
        return new self(
            "El lote {$codigoLote} esta vencido y no se puede vender.",
            'lote_vencido'
        );
    }

    public static function loteAgotado(string $codigoLote): self
    {
        return new self(
            "El lote {$codigoLote} ya no tiene unidades disponibles.",
            'lote_agotado'
        );
    }

    public static function sinPrecio(string $articulo): self
    {
        return new self(
            "El articulo {$articulo} no tiene precio cargado. No se puede cobrar en el mostrador.",
            'sin_precio'
        );
    }

    public static function ventaAbiertaDemasiadoTiempo(string $minutos): self
    {
        return new self(
            "La venta lleva {$minutos} minutos abierta y el maximo son 10. Cerra la caja o anulala.",
            'venta_abierta_demasiado_tiempo'
        );
    }

    public static function mermaSinMotivo(): self
    {
        return new self(
            'Una merma tiene que decir por que se perdio la mercaderia.',
            'merma_sin_motivo'
        );
    }

    public static function estadoInvalido(string $estadoActual, string $estadoPedido): self
    {
        return new self(
            "No se puede pasar de {$estadoActual} a {$estadoPedido}.",
            'estado_invalido'
        );
    }

    public static function pedidoSinAnticipo(): self
    {
        return new self(
            'Este pedido se confirma con anticipo. Registra el anticipo antes de confirmarlo.',
            'pedido_sin_anticipo'
        );
    }

    public static function numeroTandasInvalido(): self
    {
        return new self(
            'El numero de tandas tiene que ser al menos 1.',
            'numero_tandas_invalido'
        );
    }

    public static function insumoInexistente(string $receta): self
    {
        return new self(
            "La receta {$receta} tiene un insumo que ya no existe.",
            'insumo_inexistente'
        );
    }

    public static function sinVigencia(string $articulo): self
    {
        return new self(
            "El producto {$articulo} no tiene regla de vencimiento configurada. El lote sale sin fecha de vencimiento.",
            'sin_regla_de_vencimiento'
        );
    }

    public static function recetaSinIngredientes(string $receta): self
    {
        return new self(
            "La receta {$receta} no tiene ingredientes cargados.",
            'receta_sin_ingredientes'
        );
    }
}