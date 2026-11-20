<template>
  <div class="trend-chart" :class="{ 'is-compact': isCompact }" ref="wrapperRef">
    <svg
      :viewBox="`0 0 ${width} ${height}`"
      :preserveAspectRatio="isCompact ? 'xMidYMid meet' : 'none'"
      class="trend-chart-svg"
      role="img"
      :aria-label="ariaLabel"
    >
      <!-- gridlines + y-axis labels (full variant only -- the compact variant
           lives inside a summary card where the threshold line on its own is
           reference enough, and gridlines would out-shout the trend itself) -->
      <g v-if="!isCompact" class="trend-grid">
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
        <text
          v-if="isCompact"
          :x="width - padding.right + 6"
          :y="yFor(threshold)"
          class="trend-threshold-label"
          text-anchor="start"
          dominant-baseline="middle"
        >
          {{ threshold }}{{ unit }}
        </text>
        <text v-else :x="width - padding.right" :y="yFor(threshold) - 6" class="trend-threshold-label" text-anchor="end">
          Passing — {{ unit ? `${threshold}${unit}` : `grade ${threshold}` }}
        </text>
      </g>

      <!-- trend line -->
      <path v-if="points.length > 1" :d="linePath" class="trend-line-path" fill="none" />
      <path v-if="points.length > 1 && !isCompact" :d="areaPath" class="trend-area-path" />

      <!-- points -->
      <g v-for="(point, index) in plotted" :key="point.id ?? index">
        <circle
          :cx="point.x"
          :cy="point.y"
          :r="pointRadius(index)"
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
      <span>{{ unit ? `${plotted[hoveredIndex].value.toFixed(1)}${unit}` : `Grade ${plotted[hoveredIndex].value.toFixed(1)}` }}</span>
      <small v-if="plotted[hoveredIndex].sublabel">{{ plotted[hoveredIndex].sublabel }}</small>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useElementWidth } from '@/views/dashboard/composables/useViewport'

const props = defineProps({
  points: { type: Array, default: () => [] }, // [{ id, label, value, sublabel, tone }]
  threshold: { type: Number, default: 75 },
  // '%' for scores; '' when a teacher's page plots grades (utils/passingMark.js).
  unit: { type: String, default: '%' },
  // 'full' = standalone chart card (own gridlines/axis). 'compact' = inline
  // inside a summary card: line, points, threshold marker and x labels only.
  variant: { type: String, default: 'full' },
})

const isCompact = computed(() => props.variant === 'compact')

const hoveredIndex = ref(null)
const wrapperRef = ref(null)
const wrapperWidth = useElementWidth(wrapperRef)

// The full variant keeps preserveAspectRatio="none" (its historical look);
// the compact one scales uniformly instead, so its small labels are never
// stretched by whatever width the surrounding card happens to give it --
// hence a viewBox whose ratio is already close to the box it renders into.
//
// Scaling uniformly also shrinks: in a box narrower than the 360px drawing
// the whole chart was scaled down to fit, text included, and on a phone its
// 9px labels came out under 6px. So in a narrow box the drawing itself gets
// narrower instead, and one unit stays one pixel.
const COMPACT_WIDTH = 360
const COMPACT_MIN_WIDTH = 200

const width = computed(() => {
  if (!isCompact.value) return 600
  if (wrapperWidth.value <= 0) return COMPACT_WIDTH

  return Math.round(Math.min(COMPACT_WIDTH, Math.max(COMPACT_MIN_WIDTH, wrapperWidth.value)))
})
const height = computed(() => (isCompact.value ? 150 : 220))
const padding = computed(() => (isCompact.value
  ? { top: 14, right: 40, bottom: 22, left: 10 }
  : { top: 18, right: 12, bottom: 26, left: 34 }))

const yTicks = [0, 25, 50, 75, 100]

function yFor(value) {
  const usable = height.value - padding.value.top - padding.value.bottom
  return padding.value.top + usable * (1 - Math.max(0, Math.min(100, value)) / 100)
}

const plotted = computed(() => {
  const list = props.points
  if (list.length === 0) return []

  const usableWidth = width.value - padding.value.left - padding.value.right
  const step = list.length > 1 ? usableWidth / (list.length - 1) : 0

  return list.map((point, index) => ({
    ...point,
    x: list.length === 1 ? padding.value.left + usableWidth / 2 : padding.value.left + step * index,
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

function pointRadius(index) {
  const isHovered = hoveredIndex.value === index
  if (isCompact.value) return isHovered ? 5.5 : 3.8
  return isHovered ? 8 : 6
}

function shouldShowXLabel(index) {
  const total = plotted.value.length
  if (total <= 7) return true
  // The compact variant plots a short, fixed window (the last 8 exams), so
  // every label still fits -- thinning them out there would drop half the
  // A1..A8 sequence the axis is meant to spell out.
  if (isCompact.value && total <= 12) return true
  const everyNth = Math.ceil(total / 6)
  return index % everyNth === 0 || index === total - 1
}

const tooltipStyle = computed(() => {
  if (hoveredIndex.value === null) return {}
  const point = plotted.value[hoveredIndex.value]
  return {
    left: `${(point.x / width.value) * 100}%`,
    top: `${(point.y / height.value) * 100}%`,
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
  stroke: var(--dk-auto-0d1547-a18, rgba(13, 21, 71, 0.18));
  stroke-width: 1;
}

.trend-grid-label {
  font-size: 11px;
  fill: var(--lnu-text-muted);
}

.trend-threshold-line {
  stroke: var(--dk-auto-cd972b, #cd972b);
  stroke-width: 2.5;
  stroke-dasharray: 6 4;
}

.trend-threshold-label {
  font-size: 11px;
  font-weight: 700;
  fill: var(--dk-auto-9d6110, #9d6110);
}

.trend-line-path {
  stroke: var(--dk-auto-1a3a8c, #1a3a8c);
  stroke-width: 3.5;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.trend-area-path {
  fill: var(--dk-auto-315cbf-a16, rgba(49, 92, 191, 0.16));
  stroke: none;
}

.trend-point {
  stroke: var(--dk-auto-ffffff, #ffffff);
  stroke-width: 2;
  cursor: pointer;
  transition: r 0.15s ease;
}

.trend-point.tone-success { fill: var(--dk-auto-3f925e, #3f925e); }
.trend-point.tone-navy { fill: var(--dk-auto-315cbf, #315cbf); }
.trend-point.tone-gold { fill: var(--dk-auto-cd972b, #cd972b); }
.trend-point.tone-danger { fill: var(--dk-auto-c24b4b, #c24b4b); }

.trend-x-label {
  font-size: 11px;
  fill: var(--lnu-text-muted);
}

/* ---- Compact variant ---- */
.trend-chart.is-compact .trend-chart-svg {
  height: 150px;
}

.trend-chart.is-compact .trend-line-path {
  stroke-width: 2.5;
}

.trend-chart.is-compact .trend-threshold-line {
  stroke-width: 1.5;
  stroke-dasharray: 4 4;
}

.trend-chart.is-compact .trend-threshold-label {
  font-size: 11px;
  font-weight: 800;
}

/* One accent for every point: at this size a four-colour scale reads as
   noise, and each point's own score is a hover away in the tooltip. */
.trend-chart.is-compact .trend-point {
  fill: var(--dk-auto-1a3a8c, #1a3a8c);
  stroke-width: 1.6;
}

.trend-chart.is-compact .trend-x-label {
  font-size: 11px;
  font-weight: 700;
}

.trend-tooltip {
  position: absolute;
  transform: translate(-50%, -115%);
  background: var(--dk-bg-0d1547, #0d1547);
  color: var(--dk-fg-ffffff, #ffffff);
  padding: 8px 10px;
  border-radius: 10px;
  font-size: 12px;
  line-height: 1.4;
  white-space: nowrap;
  pointer-events: none;
  box-shadow: 0 8px 20px var(--dk-shadow-0d1547-a28, rgba(13, 21, 71, 0.28));
  z-index: 5;
  display: grid;
  gap: 1px;
}

.trend-tooltip strong {
  font-size: 12px;
}

.trend-tooltip span {
  font-weight: 700;
  color: var(--dk-fg-f0d080, #f0d080);
}

.trend-tooltip small {
  color: var(--dk-fg-ffffff-a72, rgba(255, 255, 255, 0.72));
  font-size: 11px;
}
</style>
