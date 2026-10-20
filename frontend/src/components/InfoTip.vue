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
  /* Holds .info-tip-sr: without it that absolute text is placed against the
     whole page, outside the scrolling content, and makes the window itself
     scroll past the end of the page. */
  position: relative;
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
  color: var(--lnu-text-muted, var(--dk-fg-5d6b86, #5d6b86));
  cursor: help;
  transition: color 0.15s ease, background 0.15s ease;
}

.info-tip-btn:hover,
.info-tip-btn.is-open {
  color: var(--dk-fg-lnu-navy, var(--lnu-navy, var(--dk-fg-1a237e, #1a237e)));
  background: var(--dk-bg-0d1547-a06, rgba(13, 21, 71, 0.06));
}

.info-tip-btn:focus-visible {
  outline: 2px solid var(--dk-line-lnu-navy, var(--lnu-navy, var(--dk-line-1a237e, #1a237e)));
  outline-offset: 1px;
}

.on-dark .info-tip-btn {
  color: var(--dk-fg-ffffff-a72, rgba(255, 255, 255, 0.72));
}

.on-dark .info-tip-btn:hover,
.on-dark .info-tip-btn.is-open {
  color: var(--dk-fg-ffffff, #fff);
  background: var(--dk-bg-ffffff-a14, rgba(255, 255, 255, 0.14));
}

.on-dark .info-tip-btn:focus-visible {
  outline-color: var(--dk-line-lnu-gold-light, var(--lnu-gold-light, var(--dk-line-f5d77a, #f5d77a)));
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
  padding: 10px 14px;
  border-radius: 10px;
  /* White card, dark text: reads clearly on the cream page and the navy
     panels alike, where the old navy bubble blended into the navy. */
  border: 1px solid var(--dk-line-0d1547-a14, rgba(13, 21, 71, 0.14));
  border-top: 3px solid var(--dk-line-lnu-gold, var(--lnu-gold, var(--dk-line-d4a72c, #d4a72c)));
  background: var(--dk-bg-ffffff, #fff);
  color: var(--dk-fg-1f2433, #1f2433);
  font-family: var(--font-body, inherit);
  font-size: 12.5px;
  font-weight: 500;
  line-height: 1.55;
  letter-spacing: normal;
  text-transform: none;
  /* Straight edges on both sides; hyphenation keeps a narrow line from
     opening wide gaps, and the last line stays left-aligned. */
  text-align: justify;
  text-align-last: left;
  hyphens: auto;
  -webkit-hyphens: auto;
  white-space: normal;
  box-shadow: 0 10px 28px var(--dk-shadow-0d1547-a18, rgba(13, 21, 71, 0.18)), 0 2px 6px var(--dk-shadow-0d1547-a08, rgba(13, 21, 71, 0.08));
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
