<template>
  <div class="radial-score" :style="{ width: `${size}px`, height: `${size}px` }">
    <svg :viewBox="`0 0 ${box} ${box}`" class="radial-score-svg">
      <circle
        :cx="box / 2"
        :cy="box / 2"
        :r="radius"
        class="radial-track"
        fill="none"
        :stroke-width="strokeWidth"
      />
      <circle
        v-if="hasValue"
        :cx="box / 2"
        :cy="box / 2"
        :r="radius"
        class="radial-fill"
        :class="`tone-${tone}`"
        fill="none"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        :stroke-dasharray="circumference"
        :stroke-dashoffset="dashOffset"
        :transform="`rotate(-90 ${box / 2} ${box / 2})`"
      />
      <!-- passing-threshold tick -->
      <line
        v-if="hasValue && threshold !== null"
        :x1="thresholdTick.x1"
        :y1="thresholdTick.y1"
        :x2="thresholdTick.x2"
        :y2="thresholdTick.y2"
        class="radial-threshold-tick"
      />
    </svg>
    <div class="radial-center" :style="{ fontSize: `${size * 0.15}px` }">
      <strong>{{ hasValue ? `${Math.round(value)}%` : '--' }}</strong>
      <small v-if="label">{{ label }}</small>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  value: { type: [Number, null], default: null },
  threshold: { type: [Number, null], default: 75 },
  size: { type: Number, default: 96 },
  label: { type: String, default: '' },
})

const box = 100
const strokeWidth = 13
const radius = (box - strokeWidth) / 2
const circumference = 2 * Math.PI * radius
// A real arc under ~5% of the ring is nearly invisible even though the
// number is accurate — floor the drawn arc so a low score still reads as
// "a ring with color in it," never a hairline. The center text always
// shows the true value.
const MIN_VISIBLE_PERCENT = 6

const hasValue = computed(() => props.value !== null && props.value !== undefined && !Number.isNaN(Number(props.value)))

const tone = computed(() => {
  const v = Number(props.value ?? 0)
  if (v >= 85) return 'success'
  if (v >= 75) return 'navy'
  if (v >= 60) return 'gold'
  return 'danger'
})

const dashOffset = computed(() => {
  const pct = Math.max(MIN_VISIBLE_PERCENT, Math.min(100, Number(props.value ?? 0)))
  return circumference * (1 - pct / 100)
})

const thresholdTick = computed(() => {
  const pct = Math.max(0, Math.min(100, Number(props.threshold ?? 0)))
  const angle = (pct / 100) * 2 * Math.PI - Math.PI / 2
  const cx = box / 2
  const cy = box / 2
  const innerR = radius - strokeWidth / 2 - 1
  const outerR = radius + strokeWidth / 2 + 1
  return {
    x1: cx + innerR * Math.cos(angle),
    y1: cy + innerR * Math.sin(angle),
    x2: cx + outerR * Math.cos(angle),
    y2: cy + outerR * Math.sin(angle),
  }
})
</script>

<style scoped>
.radial-score {
  position: relative;
  flex-shrink: 0;
}

.radial-score-svg {
  width: 100%;
  height: 100%;
  display: block;
}

.radial-track {
  stroke: rgba(13, 21, 71, 0.16);
}

.radial-fill {
  transition: stroke-dashoffset 0.5s ease;
}

.radial-fill.tone-success { stroke: #3f925e; }
.radial-fill.tone-navy { stroke: #315cbf; }
.radial-fill.tone-gold { stroke: #cd972b; }
.radial-fill.tone-danger { stroke: #c24b4b; }

.radial-threshold-tick {
  stroke: #9d6110;
  stroke-width: 3;
}

.radial-center {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  text-align: center;
  line-height: 1.15;
}

.radial-center strong {
  font-size: 1em;
  font-weight: 800;
  color: var(--lnu-text);
}

.radial-center small {
  font-size: 0.5em;
  color: var(--lnu-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
</style>
