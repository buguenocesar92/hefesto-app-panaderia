<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Niveles de autorizacion de la panaderia.
 *
 * LA SEPARACION QUE IMPORTA
 * -------------------------
 * Un `hornero` produce y registra merma, pero no puede anular una tanda ni
 * cerrar el dia. Un `mostrador` vende, pero no toca la merma de otro turno:
 * si pudiera, el informe de merma seria el parte que el cajero quiere.
 *
 * `ENCARGADO` es el unico rol que puede anular y cerrar el dia, y
 * `ADMINISTRADOR` el unico que toca el catalogo y las reglas (vigencias,
 * motivos, personas).
 *
 * El rol decide que se MUESTRA en el menu; la autorizacion real la hace la
 * Policy del servidor. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
enum RolOperador: string
{
    /** Ve todo y no cambia nada. Sirve para auditar. */
    case AUDITOR = 'auditor';

    /** Vende en el mostrador y cobra. No toca produccion ni merma ajena. */
    case MOSTRADOR = 'mostrador';

    /** Hornea, registra tandas y merma de su turno. */
    case HORNO = 'horno';

    /** Ademas de lo anterior: anula, cierra el dia y ve los reportes. */
    case ENCARGADO = 'encargado';

    /** Configura catalogo, vigencias, motivos y personas con su rol. */
    case ADMINISTRADOR = 'administrador';

    public function puedeProducir(): bool
    {
        return in_array($this, [self::HORNO, self::ENCARGADO, self::ADMINISTRADOR], true);
    }

    public function puedeVender(): bool
    {
        return in_array(
            $this,
            [self::MOSTRADOR, self::HORNO, self::ENCARGADO, self::ADMINISTRADOR],
            true
        );
    }

    /** Registrar merma: el hornero lo hace todos los dias. */
    public function puedeRegistrarMerma(): bool
    {
        return in_array(
            $this,
            [self::HORNO, self::ENCARGADO, self::ADMINISTRADOR],
            true
        );
    }

    /**
     * Ver el informe de merma.
     *
     * `MOSTRADOR` queda afuera a proposito: el informe muestra cuantos
     * pesos perdio la panaderia y en que. No es un dato de todos.
     */
    public function puedeVerReportesDeMerma(): bool
    {
        return in_array($this, [self::ENCARGADO, self::ADMINISTRADOR], true);
    }

    /** Anular una venta, una tanda o un pedido. */
    public function puedeAnular(): bool
    {
        return in_array($this, [self::ENCARGADO, self::ADMINISTRADOR], true);
    }

    /** Cerrar el lote de cierre: es lo que cierra la caja del dia. */
    public function puedeCerrarDia(): bool
    {
        return in_array($this, [self::ENCARGADO, self::ADMINISTRADOR], true);
    }

    /** Tocar el catalogo, las vigencias y los motivos. */
    public function puedeAdministrarConfiguracion(): bool
    {
        return $this === self::ADMINISTRADOR;
    }

    public function puedeAdministrarPersonas(): bool
    {
        return $this === self::ADMINISTRADOR;
    }
}