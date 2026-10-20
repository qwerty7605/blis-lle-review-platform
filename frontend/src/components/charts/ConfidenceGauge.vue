<template>
  <figure
    class="gauge"
    role="img"
    :aria-label="hasValue ? `${label}: ${Math.round(value)} percent` : `${label}: not available yet`"
  >
    <svg viewBox="0 0 200 122" class="gauge-svg" aria-hidden="true">
      <defs>
        <linearGradient :id="gradientId" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0%" :stop-color="palette.from" />
          <stop offset="100%" :stop-color="palette.to" />
        </linearGradient>
      </defs>

      <path :d="ARC" class="gauge-track" pathLength="100" />
      <path
        v-if="shown > 0"
        :d="ARC"
        class="gauge-fill"
        pathLength="100"
        :stroke="`url(#${gradientId})`"
        :stroke-dasharray="`${shown} 100`"
      />
      <!-- A surface-coloured ring around the end of the arc, so the value's
           position reads as a point rather than a blunt end. -->
      <circle v-if="shown > 0" :cx="tip.x" :cy="tip.y" r="5" class="gauge-tip" :stroke="palette.to" />

      <text x="100" y="88" class="gauge-value">{{ hasValue ? `${Math.round(shown)}%` : '--' }}</text>
      <text x="100" y="107" class="gauge-label">{{ label }}</text>
      <text x="16" y="120" class="gauge-end">0</text>
      <text x="184" y="120" class="gauge-end">100</text>
    </svg>
  </figure>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  // 0-100, or null while there is nothing to show yet.
  value: { type: [Number, null], default: null },
  label: { type: String, default: 'Confidence' },
  // success | danger | neutral -- the verdict the gauge sits beside.
  tone: { type: String, default: 'neutral' },
})

// Half circle, centre (100, 100), radius 84, drawn left to right.
const ARC = 'M 16 100 A 84 84 0 0 1 184 100'
const DURATION_MS = 900

// Amber rather than red for the at-risk verdict, matching the label next to it.
const PALETTES = {
  success: { from: '#7cc08a', to: '#2e7d32' },
  danger: { from: '#e3bd62', to: '#b7791f' },
  neutral: { from: '#8b97d8', to: '#283593' },
}

const gradientId = `gauge-gradient-${Math.random().toString(36).slice(2, 9)}`
const palette = computed(() => PALETTES[props.tone] ?? PALETTES.neutral)

const hasValue = computed(() => props.value !== null && props.value !== undefined && !Number.isNaN(Number(props.value)))
const target = computed(() => (hasValue.value ? Math.max(0, Math.min(100, Number(props.value))) : 0))

// The number on screen, swept from its previous value to the new one.
const shown = ref(0)
let frame = 0

const tip = computed(() => {
  const angle = Math.PI * (1 - shown.value / 100)
  return { x: 100 + 84 * Math.cos(angle), y: 100 - 84 * Math.sin(angle) }
})

function animateTo(next) {
  cancelAnimationFrame(frame)

  const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
  if (reduceMotion) {
    shown.value = next
    return
  }

  const from = shown.value
  const start = performance.now()

  const step = (now) => {
    const t = Math.min(1, (now - start) / DURATION_MS)
    const eased = 1 - Math.pow(1 - t, 3)
    shown.value = from + (next - from) * eased

    if (t < 1) frame = requestAnimationFrame(step)
  }

  frame = requestAnimationFrame(step)
}

onMounted(() => animateTo(target.value))
watch(target, (next) => animateTo(next))
onBeforeUnmount(() => cancelAnimationFrame(frame))
</script>

<style scoped>
.gauge {
  margin: 0;
  width: 100%;
  /* A parent can let the gauge grow by setting --gauge-max. */
  max-width: var(--gauge-max, 230px);
}

.gauge-svg {
  display: block;
  width: 100%;
  height: auto;
  overflow: visible;
}

.gauge-track,
.gauge-fill {
  fill: none;
  stroke-width: 16;
  stroke-linecap: round;
}

.gauge-track {
  stroke: var(--dk-auto-0d1547-a09, rgba(13, 21, 71, 0.09));
}

.gauge-tip {
  fill: var(--surface, var(--dk-auto-ffffff, #ffffff));
  stroke-width: 3;
}

.gauge-value,
.gauge-label,
.gauge-end {
  text-anchor: middle;
  font-family: inherit;
}

.gauge-value {
  font-size: 36px;
  font-weight: 600;
  fill: var(--dk-auto-0b0d17, #0b0d17);
}

.gauge-label {
  font-size: 11.5px;
  font-weight: 500;
  fill: var(--lnu-text-muted);
}

.gauge-end {
  font-size: 10px;
  fill: var(--lnu-text-muted);
}
</style>
