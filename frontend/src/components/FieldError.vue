<template>
  <span v-if="message" :id="checks.errorId(field)" class="field-error-text" role="alert">{{ message }}</span>
</template>

<script setup>
import { computed } from 'vue'

// What a field will not accept, under it: see utils/fieldChecks.js.
const props = defineProps({
  checks: { type: Object, required: true },
  field: { type: String, required: true },
})

const message = computed(() => props.checks.error(props.field))
</script>
