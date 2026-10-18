# 10. Pruebas y estado

## LA VERDAD, PRIMERO

**En la maquina donde se escribio esta aplicación no hay PHP, ni Composer, ni
Node, ni Docker, ni PostgreSQL.**

Por lo tanto:

| | |
|---|---|
| Migraciones aplicadas | **0** |
| Pruebas corridas | **0** |
| Imagenes construidas | **0** |
| Contenedores levantados | **0** |
| Respaldo hecho | **0** |
| Restauración probada | **0** |
| Lineas de código verificadas por un compilador | **0** |

**Nada de lo que hay en este repositorio se ha ejecutado ni verificado.** Todo
lo demas de esta documentacion describe lo que el código *deberia* hacer.

Este documento existe para que nadie tenga que suponer lo contrario. Presentar
como probado algo que no se probo es peor que no presentarlo.

## Que se escribio

### La API

| | Cantidad |
|---|---|
| Migraciones | 9 |
| Modelos | 19 |
| Enums | 9 |
| Policies | 7 |
| Controllers | 12 |
| FormRequests | 5 + 1 base |
| Servicios | 11 |
| Jobs | 1 |
| Comandos de consola | 4 |
| Factories | 8 |
| Seeders | 2 |
| Suites de pruebas | 5 |

### La web

| | |
|---|---|
| Paginas | 8 |
| Composables | 1 |
| Middleware | 1 |
| Componentes | 1 |
| Tipos | 18 |

### La documentacion

11 documentos en `docs/`, mas el `README.md`.

### El despliegue

13 archivos en `deploy/`.

## Las pruebas escritas

### Aislamiento

`tests/Feature/Aislamiento/AislamientoDeDatosTest.php`

| Prueba | Que comprueba |
|---|---|
| `una_panaderia_no_ve_los_articulos_de_otra` | Cada una ve solo lo suyo |
| `no_se_puede_leer_una_fila_de_otra_panaderia_por_identificador` | Ni por `id` |
| `no_se_puede_insertar_una_fila_de_otra_panaderia` | El `WITH CHECK` |
| `no_se_puede_mover_una_fila_entre_panaderias` | El `WITH CHECK` también al actualizar |
| `leer_sin_contexto_falla_cerrado` | `app_tenant_id()` lanza excepcion |
| `una_venta_de_otra_panaderia_no_se_ve` | También las transacciones |
| `los_lotes_de_otra_panaderia_no_se_ven` | Y los lotes |
| `una_peticion_sin_sesion_responde_401` | Sin sesión no hay datos |

### Merma

`tests/Feature/Merma/MermaTest.php`

| Prueba | Que comprueba |
|---|---|
| `una_merma_descuenta_el_stock_del_lote` | Resta stock de verdad |
| `el_motivo_de_la_merma_es_obligatorio` | Sin motivo, falla |
| `no_se_puede_mermar_mas_que_lo_que_hay_en_el_lote` | No se merma en negativo |
| `la_merma_deja_una_fila_con_motivo` | Queda explicada |
| **`el_saldo_del_libro_siempre_cuadra_con_la_suma_de_los_lotes`** | **La garantia central** |
| `un_lote_que_se_queda_sin_stock_pasa_a_agotado` | El estado se recalcula |
| `no_se_puede_editar_el_saldo_de_un_lote_a_mano` | El gatillo de PostgreSQL |
| `un_movimiento_no_se_puede_modificar_ni_borrar` | La inmutabilidad |

### Caducidad

`tests/Feature/Caducidad/CaducidadTest.php`

| Prueba | Que comprueba |
|---|---|
| `la_vigencia_configurada_cuadra_una_tanda` | `fecha + dias` |
| `sin_regla_el_lote_no_tiene_fecha_de_vencimiento` | **La regla mas importante** |
| `una_vigencia_de_cero_significa_que_caduca_el_mismo_dia` | `0` es distinto de `NULL` |
| `una_vigencia_nula_dentro_de_la_fila_significa_que_no_caduca` | La fila gana al valor por omision |
| `un_lote_vencido_se_marca_y_ya_no_se_puede_vender` | No se vende pan caducado |
| `un_lote_sin_vencimiento_no_caduca_solo` | Sin regla, no hay caducidad inventada |
| `la_merma_automatica_solo_corre_si_la_panaderia_la_pidio` | Respetar la decisión |
| `sin_merma_automatica_el_lote_vencido_espera_a_una_persona` | La mercaderia sigue ahi |
| `la_merma_de_un_lote_vencido_lleva_el_motivo_de_vencimiento` | Con su motivo |
| `la_revision_avisa_lo_que_esta_por_vencer` | La ventana de aviso |
| `los_lotes_sin_vencimiento_van_al_final_del_orden` | El `NULLS LAST` |

### Recetas

`tests/Feature/Recetas/RendimientoDeRecetaTest.php`

| Prueba | Que comprueba |
|---|---|
| `el_rendimiento_multiplica_por_el_numero_de_tandas` | Lo que sale |
| `el_consumo_de_insumos_incluye_la_merma_estimada` | Lo que entra, con merma |
| `el_consumo_de_insumos_multiplica_por_el_numero_de_tandas` | Las dos cuentas |
| `avisa_cuando_no_alcanza_el_stock` | Los faltantes |
| `alcanza_si_hay_suficiente_stock` | El caso normal |
| `una_receta_sin_ingredientes_no_produce_nada` | Una receta vacia falla |
| `no_se_peden_cero_tandas` | No hay medias tandas |
| `registrar_una_tanda_descuenta_insumos_y_crea_el_lote` | El flujo completo |
| `una_tanda_sin_insumos_no_toca_nada` | **No se descuenta a medias** |

### Sobrante del turno

`tests/Feature/Produccion/SobranteDelTurnoTest.php`

| Prueba | Que comprueba |
|---|---|
| `el_lote_vencido_se_merma_y_no_pasa_a_la_bodega` | Vencido se merma |
| `el_lote_que_todavia_sirve_pasa_a_la_bodega` | Vigente se traspasa |
| `el_sobrante_queda_en_el_inventario_del_dia_siguiente` | **Es visible manana** |
| `la_merma_del_sobrante_lleva_su_motivo_y_descuenta_el_stock` | Con motivo y con resta |
| `un_lote_sin_vencimiento_no_se_merma_nunca_por_sobrante` | Sin regla, no se pierde sola |
| `un_traslado_no_engaña_al_libro_de_movimientos` | El saldo no se dobla |

### Como correrlas

```bash
php artisan test
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Merma
php artisan test --testsuite=Caducidad
php artisan test --testsuite=Recetas
```

## Por que las pruebas usan PostgreSQL y no SQLite

`phpunit.xml` fija `DB_CONNECTION=pgsql`.

Las cuatro garantias centrales de esta aplicación son:

| garantia | Como se implementa | En SQLite |
|---|---|---|
| Aislamiento entre panaderias | Row Level Security | **No existe. Pasaria sin comprobar nada.** |
| El saldo no se inventa | Gatillo de PostgreSQL | **No existe.** |
| Los movimientos son inmutables | Gatillo de PostgreSQL | **No existe.** |
| El saldo nunca queda negativo | Gatillo de PostgreSQL | **No existe.** |

Con SQLite, las cuatro pruebas de aislamiento darian verde sin haber
comprobado una sola linea del aislamiento. Por eso la conexión es fija.

## El verificador de aislamiento

```bash
php artisan panaderia:verificar-aislamiento
```

Cuatro comprobaciones reales, y la cuarta **intenta leer de verdad**:
crea dos panaderias de prueba, lee cada una en su contexto y compara. Las
tablas a revisar se leen del catalogo de PostgreSQL, no de una lista escrita en
PHP, así que una tabla nueva sin RLS la encuentra.

Salida con código distinto de cero si algo falla.

## Que falta verificar, en orden de importancia

### 1. Que el código compile

Nada se paso por un compilador. **Lo mas probable es que haya errores.** Al
menos estos puntos son los mas dudosos:

| Riesgo | Donde |
|---|---|
| Los tipos de retorno y las firmas | Toda la API |
| Los `use` que faltan | Todos los archivos |
| El `Composer` que resuelve las dependencias | `composer.json` |
| Que las rutas apunten a metodos que existen | `routes/web.php` |

### 2. Las migraciones

No se aplico ninguna. En particular, **la migración 000005 tiene SQL en crudo**
(`CREATE FUNCTION`, `CREATE TRIGGER`, `CREATE VIEW`). El SQL no se valido
contra un PostgreSQL real, solo se escribio con cuidado.

### 3. Los gatillos

`trg_lote_productos_solo_por_movimiento` y `trg_lote_sin_saldo_negativo`
dependen de que `app_hay_movimiento()` se resuelva en el momento correcto. Es
la parte mas sutil del sistema y **no se probo**.

En particular hay una interaccion que no se pudo verificar: `set_config` con
alcance de transacción tiene que estar visible para el gatillo que corre
dentro de la misma transacción. **Eso deberia funcionar, pero no se comprobó.**

### 4. El flujo de Authentik

No se probo ni un solo paso del acceso. Depende de una instancia de Authentik
que no existe en la maquina donde se escribio.

### 5. El calculo de rendimiento en la interfaz

Los tipos de `web/app/tipos/api.ts` son una copia escrita a mano de lo que
devuelve la API. Si en algun punto no calzan, el error aparece en tiempo de
ejecución del navegador, no en el chequeo de tipos.

### 6. El script de respaldo

No se ejecuto. En particular no se pudo comprobar que el grep del nombre del
contenedor funcione con el nombre que realmente genera Compose.

## Pendientes de definicion

No son problemas técnicos: son preguntas que **la panaderia tiene que
contestar** antes de usar el sistema.

| Pregunta | Que bloquea | Valor actual |
|---|---|---|
| ¿Cuantos dias de aviso de caducidad? | La ventana del informe | 1 dia. **Sin confirmar.** |
| ¿Cuantos minutos puede quedar abierta una venta? | El rechazo de una venta tardia | 10 minutos. **Sin confirmar.** |
| ¿Como se lleva el costo de un insumo? | El costo de producción y el valor de la merma | **No existe.** |
| ¿Cuanto dura un pan? | La vigencia de cada producto | **Sin regla por omision.** |
| ¿Que imagenes del diseno? | El campo `diseno` | Hoy es texto. |
| ¿Que dias se guarda el sobrante? | Si se traspasa o se merma | Hoy, hasta la fecha de vencimiento. |

## Pendientes técnicos

| Pendiente | Por que |
|---|---|
| Fijar las imagenes por resumen SHA256 | Una etiqueta puede volver a apuntar a otra imagen |
| No hay nada que automatice el respaldo | Un respaldo que depende de acordarse no es un respaldo |
| No hay copia de respaldo fuera del servidor | Un respaldo en el mismo servidor no sobrevive a perder el servidor |
| `php-fpm` y nginx: hay que elegir uno | El `Dockerfile` usa el `entrypoint.sh`; `supervisord.conf` es la alternativa. **No se usan los dos.** |
| No hay documentacion OpenAPI | Los tipos de la web estan escritos a mano |
| No hay retencion de respaldos | Se acumulan sin limite |

## La única validación que SI se corrio

En la maquina donde se escribio esto habia Python, y se corrieron las dos
herramientas del repositorio:

| Herramienta | Resultado |
|---|---|
| `python tools/acentuar.py --aplicar apps/panaderia` | Correcciones aplicadas hasta llegar a 0 |
| `python tools/validar_apps.py apps/panaderia` | Ver el resultado al final de la entrega |

Esas herramientas revisan **documentos, estructura de archivos, YAML, shell y
caracteres**, no el comportamiento del código.

Que la documentacion este bien escrita y los archivos ordenados **no dice nada**
sobre si las migraciones corren, si el aislamiento funciona o si el calculo de
rendimiento da el número correcto. Eso sigue sin verificarse.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`panaderia_admin`) y el rol de la
aplicación (`panaderia_app`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

Lo que dice la tabla de arriba sigue siendo cierto y se aplica a este cambio
también: las herramientas del repositorio revisan **documentos, estructura de
archivos, YAML, shell y caracteres**, no el comportamiento del código. Queda
pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `panaderia_app` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS.
3. Correr `php artisan panaderia:verificar-aislamiento` y confirmar que sale con
   código 0.
4. Probar el escenario que motiva todo el cambio: una panadería intentando leer
   pedidos de otra.

Lo único verificado es estático: `bash -n` pasa sobre el script y el `compose`
es YAML válido, ambas cosas comprobadas por `tools/validar_apps.py`. El
razonamiento se apoya en la documentación oficial de PostgreSQL 18, no en una
ejecución.