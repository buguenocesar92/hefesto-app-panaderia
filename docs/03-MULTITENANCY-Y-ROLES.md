# 03. Multiempresa (multi-tenant) y roles

## Que es "multiempresa" aca

Una instalación de Hefesto puede atender varias panaderias. Cada una es una
empresa (`tenants`) y tiene sus propios articulos, recetas, ventas y mermas.

El objetivo es simple de decir y dificil de cumplir: **una panaderia no puede
ver los datos de otra, ni por error, ni por una consulta mal escrita, ni
porque alguien cambie un identificador en la URL.**

## Las cinco piezas

### 1. `tenant_id` en toda tabla de negocio

18 tablas lo tienen. Es la columna que la base usa para filtrar.

### 2. La función `app_tenant_id()`

```
CREATE FUNCTION public.app_tenant_id() RETURNS uuid ...

    valor := current_setting('app.tenant_id', true);

    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION 'No hay empresa en el contexto...';
    END IF;

    RETURN valor::uuid;
```

**Falla cerrado.** Sin contexto no hay lectura, y el error explica por que.

La alternativa (devolver `NULL` y que `tenant_id = NULL` no casara con nada)
también cierra el aislamiento, pero devuelve un error de PostgreSQL que no
dice nada. El error tiene que DIR el motivo.

### 3. `set_config('app.tenant_id', ..., true)`

El tercer `true` es lo que hace segura la marca.

Sin el, `set_config` escribe en la **sesión** de PostgreSQL, que sobrevive al
`COMMIT`. Con una conexión reutilizada del pool, la peticion siguiente hereda
la empresa anterior y lee datos de otra panaderia sin ningun aviso.

Es el error mas grave posible en este sistema y el mas facil de introducir por
descuido: es un parámetro booleano en una llamada.

### 4. `USING` y `WITH CHECK`

```sql
CREATE POLICY articulos_aislamiento ON public.articulos
    FOR ALL
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

- `USING` filtra lo que ya esta escrito (lectura, actualización, borrado).
- `WITH CHECK` impide que se escriba de otra empresa.

Con solo `USING`, un `INSERT` con el `tenant_id` de otra panaderia pasaria sin
ninguna observacion. Las dos mitades hacen falta y las dos estan.

### 5. `FORCE ROW LEVEL SECURITY`

Sin esto, el DUENO de la tabla (el rol de la aplicación) tiene permiso de
saltarsela entera. Con `FORCE`, la política se aplica también a el.

## La tabla de empresas NO lleva RLS

`tenants` es la única tabla sin `tenant_id` y sin política.

La razon: se consulta en el **acceso**, cuando todavia no hay sesión y por lo
tanto no hay `app.tenant_id` que poner. Si llevara RLS, el `INSERT` inicial de
una panaderia y la lectura del selector fallarian.

### La compensacion: la vista `vw_empresas_publicas`

```sql
CREATE VIEW public.vw_empresas_publicas AS
    SELECT slug, nombre, activo
    FROM public.tenants
    WHERE activo = true;
```

Tres columnas. Ni el `id`, ni el `razon_social`, ni los sellos de tiempo. Y
solo panaderias activas.

**Y se usa de verdad.** Es lo que alimenta el selector de la pantalla de
entrada:

```
app/pages/entrar.vue
    -> GET /api/v1/panaderias
        -> AuthController::panaderias()
            -> Tenant::publicasActivas()
                -> SELECT ... FROM vw_empresas_publicas
```

El `slug` que devuelve ese endpoint viaja en la sesión hasta el callback, y es
lo que despues resuelve a que panaderia pertenece la cuenta. **Si la vista no
existiera, el acceso no podria funcionar.**

En siete aplicaciones anteriores se creo la vista y nunca se consulto, mientras
la documentacion afirmaba que si. Prometer una protección que no existe es
peor que no prometerla.

La compensacion tiene dos partes mas:

1. **El rol de la aplicación no es superusuario ni tiene `BYPASSRLS`.** Con
   cualquiera de las dos, PostgreSQL ignora todas las políticas.
2. **`panaderia:verificar-aislamiento`** comprueba las dos cosas en cada
   despliegue.

## Por que el slug de la pantalla decide la empresa

La identidad llega de Authentik con un correo. El dominio del correo no sirve:
con un correo compartido como `@gmail.com` no dice nada, y ademas seria
adivinar.

La empresa se decide por el `slug` que la persona eligio. Ese `slug`:

1. Se lee de `vw_empresas_publicas` al mostrar el selector.
2. Se valida contra la MISMA vista antes de partir al proveedor de identidad.
3. Viaja como `login_hint` y se guarda en la sesión.
4. En el callback, `VinculadorDeIdentidad` busca a la persona con ese
   `tenant_id` y ese correo.

Si el correo tiene cuentas en varias panaderias y la persona no eligio
ninguna, el acceso se rechaza y se le pide que elija. Nunca entra "en la
primera que se parezca".

## Los cinco roles

| Rol | Que puede | Que NO puede |
|---|---|---|
| `auditor` | Ver todo | Cambiar nada |
| `mostrador` | Vender en mostrador, ver catalogo, ver stock, ver la agenda | Producir, registrar merma, ver reportes, anular, cerrar el dia |
| `horno` | Producir, registrar tandas, registrar merma de su turno, vender | Anular, cerrar el dia, cambiar recetas, tocar configuración |
| `encargado` | Todo lo anterior, y anular, cerrar el dia, ver reportes, cambiar recetas | Tocar la configuración |
| `administrador` | Todo | Nada le esta prohibido: no hay acciones de "administración" que no pueda hacer |

### Las dos separaciones que importan

**1. El mostrador no registra merma.** Puede ver el stock, pero no escribir en
`mermas`. Si pudiera, el informe de merma seria el parte que el cajero quiere.
Ademas, `mostrador` no ve los reportes: dicen cuantos pesos perdio el negocio,
y no es un dato de todos.

**2. El horno no anula.** Registrar una tanda es su trabajo. Anularla devuelve
insumos al inventario y deja de existir la producción, y eso lo decide el
encargado.

### El rol no es una protección

El rol decide que se MUESTRA en el menu. **Lo que decide que se PUEDE hacer es
la Policy del servidor.**

Si el menu se equivoca, la persona ve un boton que al apretarlo recibe un 404.
Eso es una molestia, no una brecha. La autorización real esta en
`app/Policies/`.

## Por que una respuesta es 404 y no 403

Cuando una Policy rechaza, la respuesta es **404**, no 403.

Un 403 dice "esto existe pero no puedes verlo". Eso ya es filtrar
información: le confirma a quien hizo la peticion que el recurso existe en otra
panaderia. Con 404, no se puede distinguir "no existe" de "no puedes verlo",
que es justamente lo que hay que ocultar.

Esta configurado en `bootstrap/app.php`:

```php
$exceptions->render(function (AuthorizationException $e, $request) {
    if ($request->is('api/v1/*')) {
        return response()->json(['mensaje' => 'No encontrado.'], 404);
    }
    return null;
});
```

## La verificación del aislamiento

```
php artisan panaderia:verificar-aislamiento
```

Hace CUATRO comprobaciones reales, no mira el SQL:

| Que | Como |
|---|---|
| El rol no es superusuario | `pg_roles.rolsuper` |
| El rol no tiene `BYPASSRLS` | `pg_roles.rolbypassrls` |
| Cada tabla protegida tiene RLS activada Y forzada, con política de lectura Y de escritura | `pg_class` y `pg_policies` |
| **Leer sin contexto falla**, y dos panaderias de prueba no se ven entre si | Se intenta de verdad |

La cuarta es la que importa. Las otras tres pueden estar bien y que la política
este mal escrita, en cuyo caso las dos panaderias se verian igual. Por eso la
última comprobacion **intenta leer**: crea dos panaderias de prueba, lee cada
una en su contexto y compara.

Las tablas que se revisan se leen del catalogo de PostgreSQL, no de una lista
escrita en PHP. Si alguien agrega una tabla nueva sin RLS, el comando la
encuentra.

Sale con código distinto de cero si algo falla, y el despliegue se detiene.

En la interfaz hay un equivalente: `GET /api/v1/yo/diagnostico` dice si leer
sin contexto fue rechazado. Ese chequeo **borra la marca antes de preguntar**,
porque si la llamara con la empresa puesta devolveria el identificador y
diria "todo bien" sin haber comprobado nada: un falso positivo, que es peor
que no tener el diagnóstico.

## Donde esta cada cosa

| Pieza | Archivo |
|---|---|
| La función y la vista | `api/database/migrations/2026_10_18_000009_activar_row_level_security.php` |
| La marca y la transacción | `api/app/Services/Tenancy/ContextoTenant.php` |
| El middleware | `api/app/Http/Middleware/ResolverTenant.php` |
| Los roles | `api/app/Enums/RolOperador.php` |
| Los permisos | `api/app/Policies/` |
| El 404 | `api/bootstrap/app.php` |
| El verificador | `api/app/Console/Commands/PanaderiaVerificarAislamiento.php` |
| El uso de la vista | `api/app/Http/Controllers/AuthController.php`, metodo `panaderias()` |

---

## Los dos roles de PostgreSQL

La base de esta aplicación corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre panaderías funcione en vez
de quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `panaderia_admin` | `POSTGRES_USER` | Crear la base `panaderia` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `panaderia_app` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**. Por eso su contraseña va en `DB_ADMIN_PASSWORD`, separada de
`DB_PASSWORD`.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar: una
panadería habría visto los pedidos, los costos y los datos de contacto de las
demás, sin error y sin aviso. El aislamiento entre panaderías era decorativo.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE panaderia OWNER TO panaderia_app;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

### Cómo se comprueba

```
php artisan panaderia:verificar-aislamiento
```

El comando hace dos consultas separadas a `pg_roles`, una por atributo, y falla
con código 1 si el rol de la conexión es superusuario o si tiene `BYPASSRLS`.
Después revisa que toda tabla con `tenant_id` tenga RLS activada y forzada con
política de lectura y de escritura, y hace la prueba que más importa: leer con
el contexto de la panadería A y con el de la B, y confirmar que no se ven las
filas de la otra.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).