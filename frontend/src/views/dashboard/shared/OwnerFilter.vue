<template>
  <!-- An administrator's pages list every teacher's work; this narrows them
       to one person's. Not rendered for a teacher, who sees only their own. -->
  <label v-if="owners.length > 1" class="owner-filter">
    <UserRound :size="15" aria-hidden="true" />
    <span class="owner-filter-label">{{ label }}</span>
    <select class="text-input owner-filter-select" :value="modelValue" :aria-label="label" @change="$emit('update:modelValue', $event.target.value)">
      <option value="">All teachers</option>
      <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
    </select>
  </label>
</template>

<script setup>
import { UserRound } from 'lucide-vue-next'

defineProps({
  modelValue: { type: String, default: '' },
  owners: { type: Array, default: () => [] },
  label: { type: String, default: 'Made by' },
})

defineEmits(['update:modelValue'])
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.owner-filter {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
  font-size: 13px;
  color: var(--ink-soft);
}

.owner-filter-label {
  flex: none;
  font-weight: 600;
  white-space: nowrap;
}

.owner-filter-select {
  flex: 1 1 auto;
  min-width: 0;
  width: auto;
  max-width: 240px;
}

@media (max-width: 640px) {
  .owner-filter {
    display: flex;
    width: 100%;
  }

  .owner-filter-select {
    max-width: none;
  }
}
</style>
