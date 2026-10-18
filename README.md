# Gestión de Panaderia y Pasteleria

Aplicación de Hefesto Studio para una panaderia pequena: producción por tanda,
merma con motivo, caducidad por fecha, venta de mostrador y pedidos de torta con
despacho.

**Estado: NO EJECUTADA.** No hay migraciones aplicadas, ni pruebas corridas, ni
imagenes construidas. Ver
[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md).

## Que resuelve de verdad

No es un restaurante con caja. Es una panaderia que produce, pierde mercaderia, y
tiene que saber cuanto perdio.

| | |
|---|---|
| **Producción por tanda** | Cada corrida dice una receta y rinde una cantidad conocida. El pan que sale del horno es un lote, con su fecha y su origen. |
| **Merma con motivo** | Lo quemado, lo caido y lo vencido se registra con su motivo, obligatoriamente. |
| **Sobrante del turno anterior** | Lo que queda de ayer o se merma con su motivo, o pasa a la bodega y aparece en el inventario de hoy. No hay tercera salida. |
| **Caducidad por fecha** | La vigencia se configura por producto. El sistema no inventa plazos. |
| **Insumos que también se venden** | La harina del pan y la bolsa de harina del mostrador son el mismo articulo del mismo inventario. |
| **Mostrador** | Venta rapida con pago y descuento por lote de cierre. |
| **Pedidos de torta** | El encargo con fecha, diseno y anticipo. |

## Estructura

```
apps/panaderia/
  api/        Laravel 13, PHP 8.4
  web/        Nuxt 4, TypeScript
  deploy/     Docker, Caddy, respaldos
  docs/       11 documentos numerados
```

## Donde empezar

| Quiero saber... | Abrir |
|---|---|
| Como esta armado el sistema | [docs/01-ARQUITECTURA.md](docs/01-ARQUITECTURA.md) |
| Que tablas hay y por que | [docs/02-MODELO-DE-DATOS.md](docs/02-MODELO-DE-DATOS.md) |
| Como una panaderia no ve los datos de otra | [docs/03-MULTITENANCY-Y-ROLES.md](docs/03-MULTITENANCY-Y-ROLES.md) |
| Como se levanta | [docs/04-INSTALACION.md](docs/04-INSTALACION.md) |
| Que reglas decide la panaderia | [docs/05-CONFIGURACION.md](docs/05-CONFIGURACION.md) |
| Como se produce por primera vez | [docs/06-PRIMERA-PRODUCCION.md](docs/06-PRIMERA-PRODUCCION.md) |
| Como se entra y que se pública | [docs/07-SEGURIDAD.md](docs/07-SEGURIDAD.md) |
| Como se respalda y se restaura | [docs/08-RESPALDOS.md](docs/08-RESPALDOS.md) |
| Que endpoints hay | [docs/09-API.md](docs/09-API.md) |
| **Que NO se ha probado** | [docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md) |

## Levantarlo

```bash
cd apps/panaderia/deploy
docker compose -f docker-compose.dev.yml up -d --build
```

| | |
|---|---|
| Interfaz | `http://localhost:3000` |
| Salud de la API | `http://localhost:8000/api/v1/salud` |

Requiere Docker. PHP, Composer, Node y PostgreSQL no se necesitan en la
maquina: todo corre en contenedores.

En producción, el único servicio que pública puertos es Caddy. Ver
[docs/07-SEGURIDAD.md](docs/07-SEGURIDAD.md).

## Verificar el aislamiento

Antes de abrir al público, siempre:

```bash
docker compose -f docker-compose.prod.yml exec api php artisan panaderia:verificar-aislamiento
```

Comprueba que el rol de la base no es superusuario, que todas las tablas
protegidas tienen Row Level Security con política de lectura y escritura, que
leer sin contexto falla, y que dos panaderias de prueba no se ven entre si.

## Advertencia sobre los datos de ejemplo

El seeding crea una panaderia con **precios inventados**. No son los precios de
ninguna panaderia real y no sirven para tomar decisiones.

Las personas que crea tampoco tienen acceso: ademas de la fila en la base,
tienen que existir en Authentik con el mismo correo.