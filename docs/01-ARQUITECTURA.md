# 01. Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

## Las capas

El sistema tiene cuatro piezas. Ninguna habla con la base de datos salvo la
API.

```
   Navegador
       |
       |  HTTPS (puerto 80 y 443)
       v
   +---------------------------+
   |  Caddy                    |  el UNICO proxy que publica puertos
   +---------------------------+
       |            |
       | /api/*     | resto
       v            v
  +---------+   +---------+
  |   API   |   |   Web   |   Nuxt, modo servidor
  |  (PHP)  |   | (Node)  |
  +---------+   +---------+
       |            |
       |            | reenvia a la API
       |            +----> (vuelve por el mismo camino)
       |
       v
  +---------+        +---------+
  |PostgreSQL|       |  Redis  |
  +---------+        +---------+
```

### Por que el navegador no habla nunca con la API

El navegador pide a `web`, y `web` reenvia a `api`. Se llama servidor
intermedio, y trae tres cosas que de otro modo hay que resolver a mano:

1. **La cookie de sesión viaja en el mismo origen.** No hay peticiones
   cruzadas, no hay que configurar CORS para el navegador y la cookie de
   sesión no necesita `SameSite=None`.
2. **La URL interna de la API no se pública.** El navegador ve `web`; la API
   vive en la red privada de Docker.
3. **El token CSRF se reenvia como cabecera `X-XSRF-TOKEN`**, que es como
   Laravel lo espera cuando la peticion viene de otro servidor.

El reenvio esta en `web/server/api/[...ruta].ts`. No valida nada: validar en
los dos lados seria tener las reglas en dos lugares, que se desincronizan
siempre. Quien valida es la API.

## El flujo de una peticion con sesión

Este es el camino que hace que una panaderia no vea los datos de otra. La
parte importante es que la base de datos hace el filtrado, no la aplicación.

```
  1. Peticion entra por Caddy
                |
  2. Middleware `web` de Laravel
       - cookie de sesion
       - token CSRF
       - `ResolverTenant`
                |
  3. `ResolverTenant` resuelve la persona con el guard `web`
     (todavia NO usa $request->user(): corre antes que `auth`)
                |
  4. `ContextoTenant::ejecutarAislado`
       - abre transaccion
       - set_config('app.tenant_id', <id>, true)
         el `true` = alcance de TRANSACCION
                |
  5. Middleware `auth` de la ruta
     (si no hay sesion, responde 401 y no se llega a la base)
                |
  6. Controller
     - FormRequest valida
     - Policy autoriza (si rechaza: 404, no 403)
                |
  7. PostgreSQL
     - cada politica RLS compara tenant_id con app_tenant_id()
     - sin contexto, la funcion LANZA excepcion
                |
  8. Respuesta sale, transaccion cierra, la marca desaparece
```

El paso 8 es el que importa. La marca se puso con alcance de transacción, así
que desaparece sola al hacer `COMMIT`. No puede quedar pegada a una conexión
reutilizable del pool. Ver [03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

## La API

Laravel 13 sobre PHP 8.4. Por que PHP y no otra cosa: es lo que el resto de
las aplicaciones de Hefesto usan, y una aplicación que comparte convenciones
con las otras se mantiene mas barato.

```
api/
  app/
    Console/Commands/   los comandos de consola
    Enums/              los estados y roles
    Exceptions/         ErrorDePanaderia
    Http/
      Controllers/      los endpoints
      Middleware/       ResolverTenant, AuditarOperacion
      Requests/         validacion, en espanol
    Jobs/               MermarVencidosJob
    Models/             los modelos
    Policies/           los permisos por rol
    Services/
      Auth/             Authentik
      Caducidad/        vencimientos
      Inventario/       movimientos: el unico que toca el saldo
      Merma/            registro de perdidas
      Pedidos/          pedidos de torta
      Produccion/       tandas, rendimiento, sobrante
      Tenancy/          ContextoTenant
      Ventas/           venta de mostrador
  database/
    migrations/         9 migraciones
    seeders/            datos de ejemplo (precios INVENTADOS)
    factories/          para las pruebas
  routes/               web.php, console.php
  tests/                Feature y Unit
```

### La regla de oro del inventario

**El saldo de un lote no se edita: se mueve.**

`lote_productos.cantidad_disponible` solo cambia cuando hay un movimiento en
`movimientos_inventario`, y eso lo garantiza la BASE, no la aplicación:

| Que | Donde | Que hace |
|---|---|---|
| `trg_lote_productos_solo_por_movimiento` | `000005` | Un `UPDATE` de la cantidad, el estado o el vencimiento sin la marca `app.movimiento_inventario` falla. |
| `trg_movimientos_inmutables` | `000005` | Un `UPDATE` o un `DELETE` de un movimiento falla. |
| `trg_lote_sin_saldo_negativo` | `000005` | El saldo nunca queda por debajo de cero. |
| `app_hay_movimiento()` | `000005` | Dice si hay un movimiento en curso. |

La marca se pone con `set_config(..., true)`, o sea con alcance de
TRANSACCIÓN, y solo dentro de `RegistroDeMovimiento`. Por eso no se puede
inventar un saldo: o hay un movimiento escrito, o el `UPDATE` revienta.

**El estado del saldo se lee del libro de movimientos**, no de una columna
`stock_actual`. Una columna puede desincronizarse del libro sin que nadie lo
note; leyendo la suma de los movimientos, el saldo ES la historia por
definicion. Hay una prueba que compara las dos cuentas y exige que den lo
mismo: `MermaTest::test_el_saldo_del_libro_siempre_cuadra_con_la_suma_de_los_lotes`.

### Por que los insumos a granel no tienen lote

Un insumo a granel (harina, mantequilla, azucar) no caduca por el dia ni tiene
una tanda de origen: no es "el pan de hoy", es harina del patrocinador. Su
salida de una tanda se registra en el libro SIN lote.

El producto horneado SI lleva lote: es el que tiene fecha de vencimiento, y por
eso necesita saber de que corrida salio para no vender pan vencido.

La regla: **el saldo de un articulo es la suma de sus movimientos, tenga lote
o no.** Por eso la cuenta nunca se parte en dos.

## La web

Nuxt 4 en modo servidor. Sin dependencias de interfaz: los estilos estan
escritos en `main.css` y no hay mas de seis componentes.

Las decisiones de los estilos no son esteticas. Una panaderia se usa de pie,
con las manos ocupadas y a veces con guantes:

- Los botones miden 2,5 rem de alto: se aprietan con una mano.
- Los números van alineados a la derecha y con cifras decimales fijas. Un
  total que cambia de ancho mientras se mira es una forma de equivocarse.
- Cada estado tiene color Y texto. El color solo no sirve para quien no
  distingue colores.

## Los trabajos programados

La caducidad no puede depender de que alguien se acuerde. El pan que paso de
fecha y sigue en el mostrador es merma que nadie registro.

| Trabajo | Cada cuando | Que hace |
|---|---|---|
| `panaderia:revisa-caducidad` | Todos los dias a las 07:00 | Avisa por pantalla. No cambia nada. |
| `panaderia:merma-vencidos` | Cada hora | Marca los lotes vencidos y, si la panaderia lo pidio, registra su merma. |
| `MermarVencidosJob` | Cada hora, en la cola | Lo mismo, pero sobrevive a que el contenedor se reinicie a mitad. |
| `panaderia:verifica-aislamiento` | Cada hora | Comprueba que la RLS siga puesta. Sale con código distinto de cero si falla. |

Los corren `schedule:work` y `queue:work`, que arranca el `entrypoint.sh`
dentro del contenedor de la API. Si alguno de los dos procesos se cae, el
contenedor se cae: un contenedor vivo sin trabajo programado es una panaderia
cuyo pan vencido nadie revisa, y eso parece exactamente que todo esta bien.

## Lo que hay en los contenedores

| Contenedor | Puerto interno | Puerto publicado |
|---|---|---|
| `caddy` | 80 | **80 y 443** |
| `api` | 9000 (php-fpm) | ninguno |
| `web` | 3000 | ninguno |
| `postgres` | 5432 | ninguno |
| `redis` | 6379 | ninguno |

En desarrollo si se publican, pero atados a `127.0.0.1`: nadie de la red local
llega. Ver [07-SEGURIDAD.md](07-SEGURIDAD.md).