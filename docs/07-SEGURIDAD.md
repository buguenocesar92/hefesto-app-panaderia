# 07. Seguridad

## La idea

La aplicación **no maneja contrasenas**. La identidad, el segundo factor y la
recuperacion los resuelve Authentik, que es el proveedor de identidad de Hefesto
Studio. La API solo verifica que el token sea autentico y decide el rol.

Por que: una contrasena guardada es una contrasena que se puede filtrar. Si la
identidad vive en un solo lugar y ese lugar tiene segundo factor y registro
centralizado, hay una sola cosa que proteger en vez de varias.

## El acceso, paso a paso

```
 1. La pantalla de entrada pide la lista de panaderias
    GET /api/v1/panaderias
    -> lee vw_empresas_publicas (tres columnas, solo activas)

 2. La persona elige una. Se abre /api/v1/acceso?empresa=<slug>

 3. La API valida el slug CONTRA LA MISMA VISTA
    (si no aparece ahi, no existe o esta inactiva)
    guarda el slug en la sesion
    genera un `state` aleatorio de 40 caracteres
    redirige a Authentik

 4. Authentik autentica a la persona y vuelve con un `code`

 5. La API canjea el codigo por tokens
    COMPARA el `state` con el guardado
    si no calza: es un intento de CSRF y se corta

 6. Verifica la firma del token contra las claves publicas (jwks)
    COMPARA el emisor del token con el configurado
    un token valido de OTRA aplicacion de Authentik no pasa

 7. Busca a la persona con ese tenant_id y ese correo
    si no existe, o esta inactiva: se rechaza

 8. Inicia sesion y RENUEVA el identificador de sesion
```

## El detalle que hace el acceso seguro: el `state`

Es un valor aleatorio que viaja de ida y vuelta. Sin el, alguien podria
enganchar el navegador a una sesión ajena: le pasaria su propio `code` a una
victima y la dejaria entrando en la cuenta del atacante, o al reves.

`ClientDeAuthentik::canjearCodigo()` compara con `hash_equals()`, que no
devuelve antes de tiempo. Y borra el `state` de la sesión despues de usarlo.

## La sesión

| Aspecto | Decisión |
|---|---|
| Almacenamiento | Base de datos. Permite revocar una sesión desde el servidor si alguien pierde el equipo del mostrador. |
| Se renueva al entrar | `session()->regenerate()`. Sin esto, un identificador robado antes del acceso sirve despues. |
| Se invalida al salir | `invalidate()` + `regenerateToken()`. |
| Cookie `HttpOnly` | Si. JavaScript no la lee. |
| Cookie `Secure` | En producción si, via `SESSION_SECURE_COOKIE=true`. |
| `SameSite` | `Lax`. |

### Por que `Secure` esta apagado en desarrollo y encendido en producción

En desarrollo no hay HTTPS, y una cookie `Secure` sobre HTTP simplemente no se
envia: nadie podria entrar. En producción tiene que estar `true`, porque sin
eso la cookie viaja en claro por cualquier red WiFi de un local.

El valor sale de `SESSION_SECURE_COOKIE` y cada compose lo define distinto a
propósito.

## El token CSRF

Como las rutas viven en el grupo `web` de Laravel, el token CSRF se aplica. El
navegador lo manda en la cabecera `X-XSRF-TOKEN`.

Estan exceptuados solo dos caminos, y los dos tienen que estar:

| Ruta | Por que |
|---|---|
| `/api/v1/salud` | No cambia nada. Un chequeo de salud no necesita token. |
| `/api/v1/panaderias` | Es lo que la pantalla de entrada muestra antes de tener sesión. |

Si una ruta exceptuada llegara a tocar una tabla protegida, `app_tenant_id()`
lanzaria excepcion y la peticion fallaria: **el aislamiento falla cerrado,
nunca abierto**. Ver [03](03-MULTITENANCY-Y-ROLES.md).

## Por que una respuesta es 404 y no 403

Cuando una Policy rechaza, la respuesta es **404**.

Un 403 dice "esto existe pero no puedes verlo". Eso ya es filtrar información:
le confirma a quien hizo la peticion que el recurso existe en otra panaderia.
Con 404 no se puede distinguir "no existe" de "no puedes verlo", que es
justamente lo que hay que ocultar.

Configurado en `bootstrap/app.php`, para las rutas `api/v1/*`.

## Los puertos

**En producción, el único servicio que pública puertos es Caddy.**

| Servicio | Puerto interno | Puerto publicado |
|---|---|---|
| `caddy` | 80 | **80 y 443** |
| `api` | 9000 | ninguno |
| `web` | 3000 | ninguno |
| `postgres` | 5432 | **ninguno** |
| `redis` | 6379 | **ninguno** |

La razon: un puerto publicado es un puerto abierto a internet si el servidor
esta expuesto. Con la base de datos a la vista, cualquiera que encuentre el
puerto puede intentar conectarse.

La red de Docker también ayuda: la red pública solo la toca Caddy. La red
interna no tiene puerta a ninguna parte, y PostgreSQL y Redis viven ahi.

### En desarrollo si se publican

Porque si no, no hay forma de ver una respuesta de la API con `curl`. Pero van
atados a `127.0.0.1`:

```yaml
ports:
  - "127.0.0.1:5432:5432"
```

Nadie de la red local llega.

## El rol de la base de datos

El rol `panaderia_app` **no debe ser superusuario ni tener `BYPASSRLS`**.

Con cualquiera de las dos, PostgreSQL ignora **todas** las políticas de Row
Level Security. La RLS puede estar perfecta y no servir de nada.

Esto lo comprueba `panaderia:verificar-aislamiento` en cada despliegue, y lo
dice si esta mal.

## Lo que se registra

`AuditarOperacion` deja rastro de las operaciones que **cambian datos**:

| Se registra | No se registra |
|---|---|
| Tandas, mermas, ventas | Un listado, que no cambia nada |
| Lotes de cierre, pedidos, despachos | El cuerpo de la peticion |
| Cambio de rol de una persona | |

Lo que se guarda: el correo, el identificador de la panaderia, el metodo, la
ruta y el código de respuesta. **No el cuerpo de la peticion**, que podria
traer datos personales de clientes.

Un registro tan largo que nadie lo lee es lo mismo que no tener registro.

## Lo que la salud NO dice

`GET /api/v1/salud` es público y devuelve:

```json
{ "estado": "ok", "base": true }
```

No dice cuantos articulos hay, ni que panaderias estan cargadas, ni que
versión corre.

Eso último parece inofensivo y no lo es: un detalle de versión en un endpoint
público es la forma mas facil de que alguien sepa que versión sin parchear
esta corriendo y arme un ataque a medida.

Cuando la base no contesta, devuelve **503**, no 200. Un chequeo de salud que
dice "todo bien" con la base caida hace que el orquestador no reinicie el
contenedor.

## php-fpm en el loopback

`php-fpm` escucha en `127.0.0.1:9000`, nunca en `0.0.0.0`.

Si escuchara en todas las interfaces, el socket de FastCGI quedaria expuesto
dentro de la red de contenedores, y cualquiera que llegue a el podria ejecutar
código.

## Lo que nginx no sirve

| Ruta | Por que |
|---|---|
| `*.php` que no sea `index.php` | Ejecutar un archivo suelto es ejecutar código |
| `storage/` | Los registros y los archivos de sesión |
| `vendor/` | El código fuente de las dependencias |
| `bootstrap/` y `database/` | La configuración y las migraciones |
| Todo lo que empieza con `.` | Los archivos ocultos |

## Cabeceras

Se agregan en Caddy, para que apliquen también a las pantallas de Nuxt:

| Cabecera | Que hace |
|---|---|
| `Strict-Transport-Security` | Obliga a HTTPS |
| `X-Content-Type-Options: nosniff` | Que el navegador no adivine el tipo |
| `X-Frame-Options: SAMEORIGIN` | Que nadie meta la aplicación en un iframe |
| `Referrer-Policy: same-origin` | Que no se filtren URLs por el enlace |
| Se quita `Server` | No anuncia la versión de nginx ni de PHP |

## Lo que falta

| Pendiente | Por que |
|---|---|
| **Fijar las imagenes por resumen SHA256** | Las etiquetas pueden volver a apuntar a otra imagen. Es lo único pendiente real de seguridad en el despliegue. Ver [10](10-PRUEBAS-Y-ESTADO.md). |
| Limite de intentos de acceso | Authentik lo maneja por su cuenta. El de la API es un limite de peticiones, no de intentos. |
| Registro de la consulta CSRF fallida | Habria que decidir cuanto se guarda y cuanto se conserva. |

Nada de esto esta hecho, y nada de esto se presenta como hecho.