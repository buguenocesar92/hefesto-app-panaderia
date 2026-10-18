# 04. Instalación

## Que se necesita

| Herramienta | Versión | Para que |
|---|---|---|
| Docker | con Compose v2 | Todo corre en contenedores |
| Git | cualquiera | Traer el código |

**PHP, Composer, Node y PostgreSQL NO se necesitan en la maquina.** Todo
corre dentro de los contenedores. Es a propósito: la versión que se instala
es la versión del `Dockerfile`, y no la que estaba en el portatil de quien la
instala.

**En la maquina donde se escribio esta aplicación no habia ninguna de estas
herramientas.** Por eso nada se ejecuto y nada se comprobo. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

## Instalación en desarrollo

### 1. Entrar al directorio

```bash
cd apps/panaderia/deploy
```

### 2. Levantar

```bash
docker compose -f docker-compose.dev.yml up -d --build
```

La primera vez tarda: se baja la imagen de PHP, la de Node y la de
PostgreSQL, y se instalan las dependencias.

### 3. Entrar al contenedor de la API y aplicar las migraciones

En desarrollo, el `entrypoint.sh` las corre solo
(`PANADERIA_CORRER_MIGRACIONES=true`). Para verlas:

```bash
docker compose -f docker-compose.dev.yml exec api php artisan migrate:status
```

### 4. Cargar datos de ejemplo (opcional)

```bash
docker compose -f docker-compose.dev.yml exec api php artisan db:seed
```

**Los datos de ejemplo tienen precios INVENTADOS.** No son los precios de
ninguna panaderia. Sirven para ver la aplicación andando, no para tomar
decisiones.

El seeding crea la panaderia `panaderia-ejemplo` con tres personas:

| Correo | Rol |
|---|---|
| `admin@panaderia-ejemplo.cl` | administrador |
| `horno@panaderia-ejemplo.cl` | horno |
| `mostrador@panaderia-ejemplo.cl` | mostrador |

Para entrar hacen falta también en Authentik. Ver el paso siguiente.

### 5. Abrir

| Direccion | Que es |
|---|---|
| `http://localhost:3000` | La interfaz |
| `http://localhost:8000/api/v1/salud` | La salud de la API |

## Instalación en producción

### 1. Preparar el archivo de entorno

```bash
cd apps/panaderia/deploy
cp .env.example .env
```

Y reemplazar **todo**. El `docker-compose.prod.yml` se niega a arrancar si
falta alguno: las variables usan `?` a propósito, para que el error diga cual
falta y no algo como "la contrasena es invalida".

### 2. Generar la clave de la aplicación

```bash
docker compose -f docker-compose.prod.yml run --rm api php artisan key:generate --show
```

Copiar el resultado a `APP_KEY` en el `.env`.

### 3. Levantar

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

**OJO:** en producción `PANADERIA_CORRER_MIGRACIONES` es `false`. Las
migraciones NO se aplican al arrancar. Un despliegue que cambia el esquema sin
que nadie lo decida es un despliegue que puede cortar el servicio a mitad de la
operación de una panaderia.

### 4. Aplicar las migraciones, a mano

```bash
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force
```

### 5. Verificar el aislamiento. ANTES de abrir al público

```bash
docker compose -f docker-compose.prod.yml exec api php artisan panaderia:verificar-aislamiento
```

Si falla, **no seguir**. Es la linea entre una panaderia y los datos de otra.
Ver [03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

### 6. Crear la primera panaderia y la primera persona

No hay comando para crear la panaderia. Es deliberado: la primera se crea a
mano en la base, y es una decisión que alguien tiene que tomar a la vista.

```sql
INSERT INTO tenants (id, slug, nombre, activo, created_at, updated_at)
VALUES (
  '00000000-0000-4000-8000-000000000001',
  'mi-panaderia',
  'Mi Panaderia',
  true,
  now(), now()
);
```

Despues, la persona:

```bash
docker compose -f docker-compose.prod.yml exec api \
  php artisan panaderia:crear-persona \
    --empresa=mi-panaderia \
    --email=duena@mi-panaderia.cl \
    --nombre="Duena" \
    --rol=administrador
```

**El comando avisa lo que falta:** la fila en `usuarios` no es dar acceso. La
persona tiene que existir también en Authentik, con ese mismo correo y en el
grupo de operadores. Si no esta ahi, el acceso se rechaza aunque la fila
exista.

### 7. El dominio

Por omision el `Caddyfile` sirve en el puerto 80 sin certificado. Para un
dominio real, hay que descomentar el bloque de dominio y poner el de verdad:

```
panaderia.midominio.cl {
    ...
}
```

Y sacar `auto_https off` del bloque de arriba, para que Caddy pida el
certificado. Ver `deploy/caddy/Caddyfile`.

## La lista de control antes de abrir al público

Ninguno de estos puntos se ejecuto: son controles para hacer, no cosas que ya
estaban hechas.

| | Control | Como se comprueba |
|---|---|---|
| [ ] | Las migraciones aplicadas | `migrate:status` |
| [ ] | El aislamiento verificado | `panaderia:verificar-aislamiento` |
| [ ] | El rol de la base no es superusuario | El verificador lo dice |
| [ ] | `SESSION_SECURE_COOKIE=true` en el `.env` de producción | Revisar `docker-compose.prod.yml` |
| [ ] | `APP_DEBUG=false` | Revisar el `.env` |
| [ ] | Las variables secretas cambiadas | Comparar con `.env.example` |
| [ ] | La panaderia creada con su `slug` | `GET /api/v1/panaderias` la muestra |
| [ ] | Las personas existen en Authentik con el mismo correo | Entrar de verdad |
| [ ] | Los motivos de merma creados, incluido `vencido` | `GET /api/v1/configuracion/motivos-merma` |
| [ ] | Las vigencias configuradas por producto | `GET /api/v1/configuracion/vigencias` |
| [ ] | Las ubicaciones creadas | `GET /api/v1/configuracion/ubicaciones` |
| [ ] | El primer respaldo hecho | `ls deploy/respaldos/` |
| [ ] | La restauración probada en otro lado | Ver [08-RESPALDOS.md](08-RESPALDOS.md) |

## Dos cosas que se dejan pendientes a propósito

### Las imagenes fijadas por etiqueta, no por resumen

El `Dockerfile` fija `php:8.4-fpm-alpine` y `node:24.22.0-alpine3.24`. Una
etiqueta puede volver a apuntar a otra imagen. Fijar también por el resumen
SHA256 lo evita. **Pendiente.**

### `php-fpm` y nginx: no los dos

Hay dos maneras de levantar los procesos del contenedor de la API:

| | Quien arranca | Como |
|---|---|---|
| Con `entrypoint.sh` | El script arranca php-fpm, el planificador y la cola | El `CMD` es `php-fpm --nodaemonize` |
| Con `supervisord.conf` | Supervisor arranca php-fpm y nginx | El `CMD` es `supervisord -c ...` |

El `Dockerfile` usa la primera. `supervisord.conf` esta para cuando se quiera
nginx también en el mismo contenedor, y **no se usan los dos a la vez**: si los
dos arrancaran php-fpm, el segundo fallaria por el puerto 9000 ya ocupado.

**Esto hay que decidirlo antes de construir la imagen de producción.** Queda
como pendiente en [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

## Como se diagnostica un problema

| Sintoma | Por donde mirar |
|---|---|
| La API no responde | `docker compose ps` y `docker compose logs api` |
| El contenedor de la API se reinicia solo | El `entrypoint.sh` espera 30 intentos a la base y se muere si no responde |
| "No hay empresa en el contexto" | El middleware `ResolverTenant` no corrio. Casi siempre es una ruta que se salta el grupo `web` |
| La pantalla queda cargando | `docker compose logs web`, y revisar `NUXT_API_BASE` |
| El acceso vuelve a la pantalla de entrada | La cookie no viaja. Revisar `SESSION_SECURE_COOKIE` y el dominio |
| El pan vencido no se marca | Revisar que el planificador corra: `docker compose exec api php artisan schedule:list` |