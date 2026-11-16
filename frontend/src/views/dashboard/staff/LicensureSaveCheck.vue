<template>
  <!-- Asked once per save, and only when something is flagged: results
       that can be right but are usually a slip (lleResultChecks.js). One
       dialog for the whole list, not one per student, so it is read rather
       than clicked through. -->
  <Teleport to="body">
    <div v-if="open" class="modal-backdrop" @click.self="emit('back')">
      <div
        class="modal-card lle-check-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="lle-check-title"
        aria-describedby="lle-check-intro"
      >
        <header class="modal-head">
          <h4 id="lle-check-title">Check before saving</h4>
          <button type="button" class="modal-close" aria-label="Go back" @click="emit('back')">
            <X :size="16" />
          </button>
        </header>

        <p id="lle-check-intro" class="lle-check-intro">{{ intro }}</p>

        <ul class="lle-check-list">
          <li v-for="item in items" :key="item.id">
            <strong>{{ item.name }}</strong>
            <span v-for="reason in item.reasons" :key="reason">{{ reason }}</span>
          </li>
        </ul>

        <div class="modal-actions">
          <button ref="backButton" type="button" class="ghost-btn" @click="emit('back')">Go Back</button>
          <button type="button" class="primary-btn" @click="emit('confirm')">
            <Save :size="14" />
            Save Anyway
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { Save, X } from 'lucide-vue-next'

const props = defineProps({
  open: { type: Boolean, default: false },
  // [{ id, name, reasons: [sentence] }]
  items: { type: Array, default: () => [] },
})

const emit = defineEmits(['back', 'confirm'])

const backButton = ref(null)

const intro = computed(() => (props.items.length === 1
  ? 'This result can be right, but it is usually a slip. Check it against the PRC results before saving.'
  : `These ${props.items.length} results can be right, but each is usually a slip. Check them against the PRC results before saving.`))

function onKeydown(event) {
  if (event.key === 'Escape') emit('back')
}

// Going back is the safe answer, so it is the one Enter presses.
watch(() => props.open, (open) => {
  if (open) {
    document.addEventListener('keydown', onKeydown)
    nextTick(() => backButton.value?.focus())
  } else {
    document.removeEventListener('keydown', onKeydown)
  }
}, { immediate: true })

onUnmounted(() => document.removeEventListener('keydown', onKeydown))
</script>

<style scoped>
.lle-check-card {
  max-width: 560px;
}

.lle-check-intro {
  margin: 0;
  font-size: 14px;
  line-height: 1.5;
  color: var(--ink-muted);
}

.lle-check-list {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.lle-check-list li {
  display: grid;
  gap: 3px;
  padding: 10px 12px;
  border-left: 3px solid var(--dk-line-c98a1e-a55, rgba(201, 138, 30, 0.55));
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  background: var(--dk-bg-c98a1e-a07, rgba(201, 138, 30, 0.07));
}

.lle-check-list strong {
  font-size: 14px;
  color: var(--ink);
  overflow-wrap: anywhere;
}

.lle-check-list span {
  font-size: 13px;
  line-height: 1.45;
  color: var(--ink-muted);
}
</style>
