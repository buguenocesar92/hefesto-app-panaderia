# 08. Respaldos

## Las seis reglas

No son preferencias de estilo. Son reglas que se aprendieron de errores reales
en proyectos anteriores, y las dos que mas dolieron fueron no aplicar las dos
ultimas.

| | Regla | Que evita |
|---|---|---|
| 1 | **Manifiesto con SHA-256 de cada archivo** | No saber si un respaldo esta roto. Un `.dump` corrupto se ve perfecto hasta que se necesita. |
| 2 | **Marcar el respaldo como completo SOLO al final** | Confiar en un respaldo que se cayo a mitad y creerse completo. |
| 3 | **Validar rutas y espacio antes de escribir** | Escribir un respaldo a medias por falta de espacio, o en un directorio equivocado. |
| 4 | **Rechazar destino ocupado salvo `--force`** | Sobrescribir un respaldo bueno sin querer, y descubrir que no quedaba ninguno. |
| 5 | **Nunca `DROP`, nunca sobrescritura automática. `TRUNCATE` si hay que limpiar** | Dejar la base sin propietario ni permisos, que es donde aparecen los problemas de permisos despues de restaurar. |
| 6 | **Dejar el servicio DETENIDO tras restaurar** | Una aplicación escribiendo sobre una base a medio restaurar. El problema original se duplica y ahora hay dos. |

## Respaldar

```bash
cd deploy/respaldos
./respaldo.sh
```

O con opciones:

```bash
./respaldo.sh --etiqueta previa-semanal
./respaldo.sh --destino /mnt/otro-disco/respaldos
```

### Que hace, en orden

```
 1. Resuelve la ruta del destino a ABSOLUTA
    (sin esto, un destino como ../../etc pasaria el chequeo de texto)

 2. Comprueba que el destino NO este dentro de la aplicacion
    un respaldo escribiendose adentro mismo termina en un disco lleno
    en el peor momento

 3. Mide el espacio libre
    Si hay menos de 10 240 KB (10 MB), no arranca.
    Se dice el numero exacto que hay y el que se necesita.

 4. Si el destino ya existe:
      sin --force  ->  se niega y termina
      con --force  ->  NO borra: lo aparta con otro nombre

 5. pg_dump en formato propio (comprimido) dentro del contenedor

 6. Copia el archivo al host y lo borra del contenedor

 7. Verifica que el archivo NO este vacio

 8. Escribe el manifiesto con el SHA-256
      # Respaldo de Gestion de Panaderia
      # Base de datos: panaderia
      # Fecha: 2026-03-10T07:00:00-03:00
      # Formato: pg_dump custom (pg_restore)
      <SHA-256>  panaderia-20260310-070000.dump

 9. Solo ahora escribe la marca de completo
```

### La marca de completo es lo importante

```
panaderia-20260310-070000/
  panaderia-20260310-070000.dump
  manifiesto.txt
  .completo
```

Si falta `.completo`, **el respaldo esta incompleto**, aunque tenga todos los
archivos. Significa que el proceso se cayo entre el volcado y el final.

Por eso la marca se escribe al final y no al principio. Al principio seria
trivial que un respaldo a medias pareciera completo.

### Verificar un respaldo

```bash
cd /respaldos/panaderia-20260310-070000
sha256sum -c manifiesto.txt
```

Sale `panaderia-...dump: OK`.

## Restaurar

```bash
cd deploy/respaldos
./restauracion.sh /respaldos/panaderia-20260310-070000
```

O con `--force` cuando la base tiene datos:

```bash
./restauracion.sh --force /respaldos/panaderia-20260310-070000
```

### Que hace, en orden

```
 1. Resuelve la ruta del respaldo a absoluta

 2. Si NO existe la marca .completo -> se niega
    "El proceso se cayo antes de terminar. No se restaura."

 3. Verifica los SHA-256 con sha256sum --check
    si no calza -> "el archivo esta corrupto o cambiado"

 4. Busca el archivo .dump y confirma que existe

 5. Cuenta cuantas tablas tiene la base AHORA
    y avisa, antes de borrar nada

 6. DETIENE la API y la interfaz
    <- ESTA ES LA REGLA 6, y va antes de tocar la base

 7. Si la base tiene datos, la vacia con TRUNCATE
    NUNCA con DROP

 8. pg_restore con --single-transaction y --exit-on-error

 9. Comprueba que la base quedo con tablas
    si quedo vacia, avisa: el respaldo probablemente no era el correcto

10. Deja el servicio DETENIDO
```

### Por que `TRUNCATE` y no `DROP`

`TRUNCATE` vacia las tablas y deja intacta la estructura, el propietario y los
permisos.

`DROP` eliminaria las tablas. Habria que volver a crearlas, y las nuevas no
tendrian el propietario ni los permisos que tenia la base. Ese es el momento en
que la aplicación deja de poder escribir, justo despues de una restauración,
que es el peor momento posible.

Las tablas de migraciones se excluyen del `TRUNCATE`: la estructura se
conserva y solo se vacian los datos.

### Por que `--single-transaction`

Si la restauración falla a mitad, `pg_restore` revierte **todo**. Con una base
a medio restaurar, no hay versión anterior a la que volver: el problema
original sigue ahi y ahora hay una base rota encima.

### Por que el servicio queda detenido

Porque una aplicación escribiendo sobre una base a medio restaurar produce
datos que no existen en ningun lado. No hay versión anterior a la que volver.

Antes de volver a levantar, hay que revisar:

```bash
cd /respaldos/panaderia-20260310-070000
sha256sum -c manifiesto.txt
docker compose -f docker-compose.prod.yml up -d
```

Si algo no cuadra, el respaldo sigue en su sitio y el respaldo anterior también.
**No se borro nada.**

## Cuando probar la restauración

**En otro lado, no en producción.**

Probar una restauración en la base de producción es probar un incendio con el
edificio lleno de gente. La forma correcta:

1. Levantar un entorno de pruebas con su propia base.
2. Restaurar ahi un respaldo de producción.
3. Comprobar que la aplicación arranca y que los datos cuadran.
4. Recién ahi, confiar en que la restauración de emergencia funcionara.

**Ninguno de estos respaldos ni esta restauración se ejecuto.** No hay base de
datos, ni contenedor, ni nada. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

## Lo que falta

| Pendiente | Por que |
|---|---|
| **Nada automatiza el respaldo.** | `respaldo.sh` existe y hay que correrlo a mano. Un respaldo que depende de que alguien se acuerde no es un respaldo. Habria que decidir un horario y donde vive la planificacion. |
| **No hay copia en otro lugar.** | Un respaldo en el mismo servidor no sobrevive a que se pierden el servidor. |
| **No hay retencion.** | Los respaldos se acumulan sin limite. |
| **No hay verificación periodica.** | Un respaldo que nunca se restauró es una suposicion de que sirve. |

Las cuatro son pendientes conocidos. Se preferio dejarlo así y decirlo en voz
alta antes que montar una planificacion a medias.

## Una nota sobre el contenido de los respaldos

Un respaldo contiene **toda** la información de la panaderia: ventas, pedidos
con nombre y contacto del cliente, mermas.

Por eso:

| Medida | |
|---|---|
| Permisos del directorio | `700`, solo el propietario. |
| El directorio queda de solo lectura | `chmod -R a-w` al terminar. |
| `.gitignore` los excluye | Un respaldo jamai va al repositorio. |
| El volumen es local | No es un volumen compartido con nadie mas. |