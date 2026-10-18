<template>
  <span class="info-tip" :class="{ 'on-dark': onDark }">
    <button
      ref="buttonRef"
      type="button"
      class="info-tip-btn"
      :class="{ 'is-open': open }"
      :aria-label="label"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-describedby="textId"
      @click.stop="toggle"
      @mouseenter="hoverOpen"
      @mouseleave="hoverClose"
      @focus="hoverOpen"
      @blur="hoverClose"
      @keydown.esc.stop="close"
    >
      <Info :size="size" aria-hidden="true" />
    </button>

    <!-- Read out with the button by screen readers; the bubble itself is only what sighted readers see. -->
    <span :id="textId" class="info-tip-sr"><slot>{{ text }}</slot></span>

    <Teleport to="body">
      <div
        v-if="open"
        ref="bubbleRef"
        class="info-tip-bubble"
        :class="{ 'is-above': placeAbove }"
        :style="bubbleStyle"
        aria-hidden="true"
        @mouseenter="cancelHoverClose"
        @mouseleave="hoverClose"
        @click.stop
      >
        <slot>{{ text }}</slot>
      </div>
    </Teleport>
  </span>
</template>

<script setup>
/*
 * A small (i) beside a heading or field that holds the longer explanation,
 * so the page shows only what people need to act. Hover or keyboard focus
 * shows it on a desktop; a tap toggles it on a touch screen (there is no
 * hover there). Escape, a tap elsewhere, or scrolling the icon out of sight
 * closes it.
 *
 * Do not hide behind it: errors, warnings, what a person must do next, or
 * anything a student needs during an exam. Those stay on the page.
 */
import { nextTick, onBeforeUnmount, ref } from 'vue'
import { Info } from 'lucide-vue-next'

defineProps({
  text: { type: String, default: '' },
  label: { type: String, default: 'More information' },
  size: { type: Number, default: 15 },
  // On the navy panels: a light icon instead of the grey one.
  onDark: { type: Boolean, default: false },
})

const textId = `info-tip-${Math.random().toString(36).slice(2, 10)}`

const open = ref(false)
const pinned = ref(false)
const placeAbove = ref(false)
const bubbleStyle = ref({})
const buttonRef = ref(null)
const bubbleRef = ref(null)
let closeTimer = null

const GUTTER = 12
const GAP = 8

const canHover = () => typeof window !== 'undefined' && window.matchMedia?.('(hover: hover) and (pointer: fine)').matches

async function place() {
  bubbleStyle.value = { top: '0px', left: '0px', visibility: 'hidden' }
  await nextTick()
  follow()
}

// Keeps the bubble beside its icon; the icon scrolled out of sight closes it.
function follow() {
  const button = buttonRef.value
  const bubble = bubbleRef.value
  if (!button || !bubble) return

  const anchor = button.getBoundingClientRect()
  if (anchor.bottom < 0 || anchor.top > window.innerHeight) {
    close()
    return
  }

  const box = bubble.getBoundingClientRect()
  const viewWidth = document.documentElement.clientWidth
  const viewHeight = window.innerHeight

  const left = Math.min(
    Math.max(GUTTER, anchor.left + anchor.width / 2 - box.width / 2),
    viewWidth - GUTTER - box.width,
  )
  const below = anchor.bottom + GAP
  placeAbove.value = below + box.height > viewHeight - GUTTER && anchor.top - GAP - box.height >= GUTTER
  const top = placeAbove.value ? anchor.top - GAP - box.height : below

  bubbleStyle.value = { top: `${Math.round(top)}px`, left: `${Math.round(Math.max(GUTTER, left))}px` }
}

function show() {
  clearTimeout(closeTimer)
  if (open.value) return
  open.value = true
  place()
  document.addEventListener('click', onOutside)
  window.addEventListener('scroll', follow, true)
  window.addEventListener('resize', follow)
  document.addEventListener('keydown', onKey)
}

function close() {
  clearTimeout(closeTimer)
  open.value = false
  pinned.value = false
  document.removeEventListener('click', onOutside)
  window.removeEventListener('scroll', follow, true)
  window.removeEventListener('resize', follow)
  document.removeEventListener('keydown', onKey)
}

function toggle() {
  // A click pins it open (or closes a pinned one); hover alone does not pin.
  if (open.value && (pinned.value || !canHover())) {
    close()
    return
  }
  show()
  pinned.value = true
}

function hoverOpen(event) {
  if (event.type === 'mouseenter' && !canHover()) return
  if (event.type === 'focus' && !event.target.matches(':focus-visible')) return
  show()
}

function hoverClose() {
  if (pinned.value) return
  clearTimeout(closeTimer)
  // A short grace so the pointer can cross the gap into the bubble.
  closeTimer = setTimeout(close, 120)
}

function cancelHoverClose() {
  clearTimeout(closeTimer)
}

function onOutside(event) {
  if (buttonRef.value?.contains(event.target)) return
  close()
}

function onKey(event) {
  if (event.key === 'Escape') close()
}

onBeforeUnmount(close)

defineExpose({ close })
</script>

<style scoped>
.info-tip {
  display: inline-flex;
  align-items: center;
  vertical-align: middle;
}

.info-tip-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  margin: -4px 0;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: var(--lnu-text-muted, #5d6b86);
  cursor: help;
  transition: color 0.15s ease, background 0.15s ease;
}

.info-tip-btn:hover,
.info-tip-btn.is-open {
  color: var(--lnu-navy, #1a237e);
  background: rgba(13, 21, 71, 0.06);
}

.info-tip-btn:focus-visible {
  outline: 2px solid var(--lnu-navy, #1a237e);
  outline-offset: 1px;
}

.on-dark .info-tip-btn {
  color: rgba(255, 255, 255, 0.72);
}

.on-dark .info-tip-btn:hover,
.on-dark .info-tip-btn.is-open {
  color: #fff;
  background: rgba(255, 255, 255, 0.14);
}

.on-dark .info-tip-btn:focus-visible {
  outline-color: var(--lnu-gold-light, #f5d77a);
}

.info-tip-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

.info-tip-bubble {
  position: fixed;
  z-index: 3000;
  width: max-content;
  max-width: min(300px, calc(100vw - 24px));
  padding: 9px 12px;
  border-radius: 10px;
  background: var(--lnu-navy-deep, #0d1547);
  color: #fff;
  font-family: var(--font-body, inherit);
  font-size: 12.5px;
  font-weight: 500;
  line-height: 1.5;
  letter-spacing: normal;
  text-transform: none;
  text-align: left;
  white-space: normal;
  box-shadow: 0 8px 24px rgba(13, 21, 71, 0.22);
}

/* Touch screens: a 44px tap target around the same 15px icon. */
@media (pointer: coarse) {
  .info-tip-btn {
    width: 32px;
    height: 32px;
    margin: -8px -4px;
  }

  .info-tip-btn::after {
    content: '';
    position: absolute;
    inset: -6px;
  }

  .info-tip-btn {
    position: relative;
  }

  .info-tip-bubble {
    font-size: 13.5px;
  }
}
</style>
