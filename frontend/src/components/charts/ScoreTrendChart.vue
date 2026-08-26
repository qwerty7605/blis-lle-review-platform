<template>
  <div class="trend-chart" ref="wrapperRef">
    <svg
      :viewBox="`0 0 ${width} ${height}`"
      preserveAspectRatio="none"
      class="trend-chart-svg"
      role="img"
      :aria-label="ariaLabel"
    >
      <!-- gridlines + y-axis labels -->
      <g class="trend-grid">
        <template v-for="tick in yTicks" :key="`grid-${tick}`">
          <line
            :x1="padding.left"
            :x2="width - padding.right"
            :y1="yFor(tick)"
            :y2="yFor(tick)"
            class="trend-grid-line"
          />
          <text :x="padding.left - 8" :y="yFor(tick)" class="trend-grid-label" text-anchor="end" dominant-baseline="middle">
            {{ tick }}
          </text>
        </template>
      </g>

      <!-- passing threshold reference line -->
      <g v-if="threshold !== null">
        <line
          :x1="padding.left"
          :x2="width - padding.right"
          :y1="yFor(threshold)"
          :y2="yFor(threshold)"
          class="trend-threshold-line"
        />
        <text :x="width - padding.right" :y="yFor(threshold) - 6" class="trend-threshold-label" text-anchor="end">
          Passing — {{ threshold }}%
        </text>
      </g>

      <!-- trend line -->
      <path v-if="points.length > 1" :d="linePath" class="trend-line-path" fill="none" />
      <path v-if="points.length > 1" :d="areaPath" class="trend-area-path" />

      <!-- points -->
      <g v-for="(point, index) in plotted" :key="point.id ?? index">
        <circle
          :cx="point.x"
          :cy="point.y"
          :r="hoveredIndex === index ? 8 : 6"
          class="trend-point"
          :class="`tone-${point.tone}`"
          @mouseenter="hoveredIndex = index"
          @mouseleave="hoveredIndex = null"
        />
      </g>

      <!-- x-axis labels (sparse, to avoid crowding) -->
      <text
        v-for="(point, index) in plotted"
        v-show="shouldShowXLabel(index)"
        :key="`x-${index}`"
        :x="point.x"
        :y="height - 6"
        class="trend-x-label"
        text-anchor="middle"
      >
        {{ point.sublabel }}
      </text>
    </svg>

    <div
      v-if="hoveredIndex !== null"
      class="trend-tooltip"
      :style="tooltipStyle"
    >
      <strong>{{ plotted[hoveredIndex].label }}</strong>
      <span>{{ plotted[hoveredIndex].value.toFixed(1) }}%</span>
      <small v-if="plotted[hoveredIndex].sublabel">{{ plotted[hoveredIndex].sublabel }}</small>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  points: { type: Array, default: () => [] }, // [{ id, label, value, sublabel, tone }]
  threshold: { type: Number, default: 75 },
})

const width = 600
const height = 220
const padding = { top: 18, right: 12, bottom: 26, left: 34 }
const yTicks = [0, 25, 50, 75, 100]

const hoveredIndex = ref(null)
const wrapperRef = ref(null)

function yFor(value) {
  const usable = height - padding.top - padding.bottom
  return padding.top + usable * (1 - Math.max(0, Math.min(100, value)) / 100)
}

const plotted = computed(() => {
  const list = props.points
  if (list.length === 0) return []

  const usableWidth = width - padding.left - padding.right
  const step = list.length > 1 ? usableWidth / (list.length - 1) : 0

  return list.map((point, index) => ({
    ...point,
    x: list.length === 1 ? padding.left + usableWidth / 2 : padding.left + step * index,
    y: yFor(Number(point.value ?? 0)),
  }))
})

const linePath = computed(() => (
  plotted.value.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`).join(' ')
))

const areaPath = computed(() => {
  if (plotted.value.length < 2) return ''
  const baseline = yFor(0)
  const first = plotted.value[0]
  const last = plotted.value[plotted.value.length - 1]
  return `${linePath.value} L${last.x},${baseline} L${first.x},${baseline} Z`
})

const ariaLabel = computed(() => `Score trend across ${props.points.length} exam${props.points.length === 1 ? '' : 's'}`)

function shouldShowXLabel(index) {
  const total = plotted.value.length
  if (total <= 7) return true
  const everyNth = Math.ceil(total / 6)
  return index % everyNth === 0 || index === total - 1
}

const tooltipStyle = computed(() => {
  if (hoveredIndex.value === null) return {}
  const point = plotted.value[hoveredIndex.value]
  return {
    left: `${(point.x / width) * 100}%`,
    top: `${(point.y / height) * 100}%`,
  }
})
</script>

<style scoped>
.trend-chart {
  position: relative;
  width: 100%;
}

.trend-chart-svg {
  width: 100%;
  height: 220px;
  display: block;
  overflow: visible;
}

.trend-grid-line {
  stroke: rgba(13, 21, 71, 0.18);
  stroke-width: 1;
}

.trend-grid-label {
  font-size: 10px;
  fill: var(--lnu-text-muted);
}

.trend-threshold-line {
  stroke: #cd972b;
  stroke-width: 2.5;
  stroke-dasharray: 6 4;
}

.trend-threshold-label {
  font-size: 10px;
  font-weight: 700;
  fill: #9d6110;
}

.trend-line-path {
  stroke: #1a3a8c;
  stroke-width: 3.5;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.trend-area-path {
  fill: rgba(49, 92, 191, 0.16);
  stroke: none;
}

.trend-point {
  stroke: #ffffff;
  stroke-width: 2;
  cursor: pointer;
  transition: r 0.15s ease;
}

.trend-point.tone-success { fill: #3f925e; }
.trend-point.tone-navy { fill: #315cbf; }
.trend-point.tone-gold { fill: #cd972b; }
.trend-point.tone-danger { fill: #c24b4b; }

.trend-x-label {
  font-size: 10px;
  fill: var(--lnu-text-muted);
}

.trend-tooltip {
  position: absolute;
  transform: translate(-50%, -115%);
  background: #0d1547;
  color: #ffffff;
  padding: 8px 10px;
  border-radius: 10px;
  font-size: 12px;
  line-height: 1.4;
  white-space: nowrap;
  pointer-events: none;
  box-shadow: 0 8px 20px rgba(13, 21, 71, 0.28);
  z-index: 5;
  display: grid;
  gap: 1px;
}

.trend-tooltip strong {
  font-size: 12px;
}

.trend-tooltip span {
  font-weight: 700;
  color: #f0d080;
}

.trend-tooltip small {
  color: rgba(255, 255, 255, 0.72);
  font-size: 10px;
}
</style>
