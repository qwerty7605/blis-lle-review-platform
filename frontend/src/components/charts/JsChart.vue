<template>
  <div class="js-chart" :style="{ height: `${height}px` }">
    <canvas ref="canvas" role="img" :aria-label="ariaLabel"></canvas>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  Filler,
  LineController,
  LineElement,
  LinearScale,
  PointElement,
  Tooltip,
} from 'chart.js'

/*
 * A thin wrapper around Chart.js for the bar and line charts on the teacher
 * dashboard. It owns the look every chart shares (font, grid, tooltip, the
 * pass-mark line, value labels) so a page only supplies labels and numbers.
 *
 * Only the pieces these charts use are registered, which keeps the rest of
 * Chart.js out of the bundle.
 */
Chart.register(
  BarController,
  BarElement,
  CategoryScale,
  Filler,
  LineController,
  LineElement,
  LinearScale,
  PointElement,
  Tooltip,
)

const props = defineProps({
  // 'bar' | 'line'
  type: { type: String, required: true },
  labels: { type: Array, default: () => [] },
  // [{ label, data: number[], colors?: string[] | string }]
  datasets: { type: Array, default: () => [] },
  // Bars run left to right instead of bottom to top.
  horizontal: { type: Boolean, default: false },
  // Draws a dashed line at this value on the value axis, e.g. the pass mark.
  threshold: { type: Number, default: null },
  thresholdLabel: { type: String, default: '' },
  // Upper end of the value axis; left to Chart.js when null.
  max: { type: Number, default: null },
  // Appended to tick and tooltip values, e.g. '%'.
  unit: { type: String, default: '' },
  // Prints each bar's value at its end.
  valueLabels: { type: Boolean, default: false },
  // Longer text per point for the tooltip title, when labels are short codes.
  tooltipTitles: { type: Array, default: () => [] },
  height: { type: Number, default: 240 },
  // What the chart shows, for screen readers.
  ariaLabel: { type: String, default: 'Chart' },
})

const INK = '#0b0d17'
const MUTED = '#607080'
const GRID = 'rgba(13, 21, 71, 0.08)'
const NAVY = '#283593'
const THRESHOLD = '#9a6a0c'

const canvas = ref(null)
let chart = null

function fontFamily() {
  return getComputedStyle(document.body).fontFamily || 'sans-serif'
}

const TICK_FONT_SIZE = 11.5
// The most of a sideways bar chart's width its category names may take.
const CATEGORY_WIDTH_SHARE = 0.4

/*
 * A category name broken into lines no wider than maxWidth, at word breaks.
 *
 * Chart.js draws a name on one line however long it is, and gives the names
 * at most half the chart. On a phone "Cataloging and Classification" did not
 * fit in that half: it was cut off at its start ("ing and Classification"),
 * and at 320px the names took the whole chart and left no bars.
 */
function wrapCategory(ctx, text, maxWidth, family) {
  ctx.save()
  ctx.font = `${TICK_FONT_SIZE}px ${family}`

  const lines = []
  let line = ''

  for (const word of String(text ?? '').split(/\s+/).filter(Boolean)) {
    const candidate = line ? `${line} ${word}` : word

    if (line && ctx.measureText(candidate).width > maxWidth) {
      lines.push(line)
      line = word
    } else {
      line = candidate
    }
  }

  if (line) lines.push(line)
  ctx.restore()

  return lines.length > 1 ? lines : (lines[0] ?? '')
}

// The dashed pass-mark line across the plot, labelled at its end.
const thresholdPlugin = {
  id: 'thresholdLine',
  afterDatasetsDraw(instance) {
    const value = instance.options.plugins.thresholdLine?.value
    if (value === null || value === undefined) return

    const { ctx, chartArea } = instance
    const horizontal = instance.options.indexAxis === 'y'
    const scale = horizontal ? instance.scales.x : instance.scales.y
    const position = scale.getPixelForValue(value)
    const label = instance.options.plugins.thresholdLine.label

    ctx.save()
    ctx.strokeStyle = THRESHOLD
    ctx.lineWidth = 1.5
    ctx.setLineDash([5, 4])
    ctx.beginPath()

    if (horizontal) {
      ctx.moveTo(position, chartArea.top)
      ctx.lineTo(position, chartArea.bottom)
    } else {
      ctx.moveTo(chartArea.left, position)
      ctx.lineTo(chartArea.right, position)
    }

    ctx.stroke()

    if (label) {
      ctx.setLineDash([])
      ctx.fillStyle = THRESHOLD
      ctx.font = `600 11px ${fontFamily()}`

      if (horizontal) {
        ctx.textAlign = 'center'
        ctx.textBaseline = 'bottom'
        ctx.fillText(label, position, chartArea.top - 4)
      } else {
        ctx.textAlign = 'right'
        ctx.textBaseline = 'bottom'
        ctx.fillText(label, chartArea.right, position - 4)
      }
    }

    ctx.restore()
  },
}

// Each bar's value printed at its end, in ink rather than the bar's colour.
const valueLabelPlugin = {
  id: 'valueLabels',
  afterDatasetsDraw(instance) {
    const settings = instance.options.plugins.valueLabels
    if (!settings?.enabled) return

    const { ctx } = instance
    const horizontal = instance.options.indexAxis === 'y'

    ctx.save()
    ctx.fillStyle = INK
    ctx.font = `600 12px ${fontFamily()}`

    instance.data.datasets.forEach((dataset, datasetIndex) => {
      instance.getDatasetMeta(datasetIndex).data.forEach((bar, index) => {
        const value = dataset.data[index]
        if (value === null || value === undefined) return

        const text = `${value}${settings.unit ?? ''}`

        if (horizontal) {
          // A surface-coloured backing, so a label that lands on the
          // pass-mark line stays readable instead of being struck through.
          const width = ctx.measureText(text).width
          ctx.fillStyle = '#ffffff'
          ctx.fillRect(bar.x + 5, bar.y - 9, width + 6, 18)
          ctx.fillStyle = INK
          ctx.textAlign = 'left'
          ctx.textBaseline = 'middle'
          ctx.fillText(text, bar.x + 8, bar.y)
        } else {
          ctx.textAlign = 'center'
          ctx.textBaseline = 'bottom'
          ctx.fillText(text, bar.x, bar.y - 6)
        }
      })
    })

    ctx.restore()
  },
}

function buildConfig() {
  const isLine = props.type === 'line'
  const indexAxis = props.horizontal ? 'y' : 'x'
  const valueAxis = props.horizontal ? 'x' : 'y'
  const family = fontFamily()

  const datasets = props.datasets.map((dataset) => (
    isLine
      ? {
          label: dataset.label,
          data: dataset.data,
          borderColor: dataset.colors ?? NAVY,
          borderWidth: 2,
          tension: 0.3,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointBackgroundColor: '#ffffff',
          pointBorderColor: dataset.colors ?? NAVY,
          pointBorderWidth: 2,
          fill: true,
          backgroundColor: 'rgba(40, 53, 147, 0.07)',
        }
      : {
          label: dataset.label,
          data: dataset.data,
          backgroundColor: dataset.colors ?? NAVY,
          borderRadius: 6,
          borderSkipped: false,
          maxBarThickness: props.horizontal ? 18 : 56,
        }
  ))

  const ticks = { color: MUTED, font: { family, size: TICK_FONT_SIZE } }

  // Sideways bars name their categories down the left edge; those names
  // wrap to the room they are allowed. Asked again whenever the chart is
  // resized, so the breaks follow its width.
  const categoryTicks = props.horizontal
    ? {
        ...ticks,
        autoSkip: false,
        callback(value) {
          return wrapCategory(
            this.chart.ctx,
            this.getLabelForValue(value),
            this.chart.width * CATEGORY_WIDTH_SHARE,
            family,
          )
        },
      }
    : ticks

  return {
    type: props.type,
    data: { labels: props.labels, datasets },
    plugins: [thresholdPlugin, valueLabelPlugin],
    options: {
      indexAxis,
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 700, easing: 'easeOutCubic' },
      interaction: { mode: 'index', intersect: false, axis: indexAxis },
      // Room for the value labels and the pass-mark label at the edges.
      layout: {
        padding: {
          top: 20,
          right: props.horizontal && props.valueLabels ? 44 : 8,
        },
      },
      scales: {
        [indexAxis]: {
          grid: { display: false },
          border: { color: GRID },
          ticks: categoryTicks,
        },
        [valueAxis]: {
          beginAtZero: true,
          max: props.max ?? undefined,
          grid: { color: GRID },
          border: { display: false },
          ticks: {
            ...ticks,
            maxTicksLimit: 6,
            callback: (value) => `${value}${props.unit}`,
          },
        },
      },
      plugins: {
        legend: { display: false },
        thresholdLine: { value: props.threshold, label: props.thresholdLabel },
        valueLabels: { enabled: props.valueLabels, unit: props.unit },
        tooltip: {
          backgroundColor: '#0d1547',
          titleColor: '#ffffff',
          bodyColor: 'rgba(255, 255, 255, 0.88)',
          titleFont: { family, size: 12.5, weight: 600 },
          bodyFont: { family, size: 12.5 },
          padding: 10,
          cornerRadius: 10,
          displayColors: false,
          callbacks: {
            title: (items) => {
              const index = items[0]?.dataIndex ?? 0
              return props.tooltipTitles[index] ?? items[0]?.label ?? ''
            },
            label: (item) => {
              const value = props.horizontal ? item.parsed.x : item.parsed.y
              return `${item.dataset.label}: ${value}${props.unit}`
            },
          },
        },
      },
    },
  }
}

function render() {
  if (!canvas.value) return

  chart?.destroy()
  chart = new Chart(canvas.value, buildConfig())
}

onMounted(() => {
  render()

  // Chart.js measures text when it first draws; redraw once the web font has
  // loaded so labels are not laid out with the fallback font's widths.
  document.fonts?.ready.then(() => chart?.update('none'))
})

// Rebuilt rather than patched: these charts are small, and it guarantees the
// colours, labels and plugins never drift out of step with the props.
watch(
  () => [props.type, props.labels, props.datasets, props.horizontal, props.threshold, props.max],
  render,
  { deep: true },
)

onBeforeUnmount(() => {
  chart?.destroy()
  chart = null
})
</script>

<style scoped>
.js-chart {
  position: relative;
  width: 100%;
  min-width: 0;
}
</style>
