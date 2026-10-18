<template>
  <table class="tabla">
    <thead>
      <tr>
        <th>Numero</th>
        <th>Cliente</th>
        <th>Torta</th>
        <th class="numero">Cantidad</th>
        <th>Hora</th>
        <th>Estado</th>
        <th class="numero">Saldo</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="pedido in pedidos" :key="pedido.id">
        <td>{{ pedido.numero }}</td>
        <td>{{ pedido.cliente_nombre }}</td>
        <td>{{ pedido.articulo }}<span v-if="pedido.tamano" class="tenue"> ({{ pedido.tamano }})</span></td>
        <td class="numero">{{ pedido.cantidad }}</td>
        <td>{{ pedido.hora_entrega ?? '-' }}</td>
        <td>
          <span class="estado" :class="pedido.atrasado ? 'estado--atrasado' : `estado--${pedido.estado}`">
            {{ pedido.atrasado ? 'atrasado' : pedido.estado }}
          </span>
        </td>
        <td class="numero">
          <template v-if="pedido.saldo > 0">${{ pedido.saldo.toLocaleString('es-CL') }}</template>
          <span v-else class="tenue">pagado</span>
        </td>
      </tr>
    </tbody>
  </table>
</template>

<script setup lang="ts">
import type { FilaDeAgenda } from '~/tipos/api';

defineProps<{ pedidos: FilaDeAgenda[] }>();
</script>