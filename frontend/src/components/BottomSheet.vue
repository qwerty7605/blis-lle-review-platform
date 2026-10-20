<template>
  <Teleport to="body">
    <Transition name="sheet" :duration="240" @after-leave="emit('closed')">
      <div v-if="open" class="sheet-backdrop" @click.self="close">
        <section
          ref="panel"
          class="sheet-panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
          @keydown="handleKeydown"
        >
          <header class="sheet-head">
            <div class="sheet-head-copy">
              <h4 :id="titleId">{{ title }}</h4>
              <p v-if="subtitle">{{ subtitle }}</p>
            </div>
            <button type="button" class="sheet-close" aria-label="Close" @click="close">
              <X :size="18" />
            </button>
          </header>

          <div class="sheet-body">
            <slot />
          </div>

          <footer v-if="$slots.footer" class="sheet-footer">
            <slot name="footer" />
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { X } from 'lucide-vue-next'
import { lockScroll, unlockScroll } from '@/utils/scrollLock'

/**
 * A panel that slides up from the bottom of the screen: the phone
 * counterpart of a dropdown or a modal, and where filters and per-row
 * actions go on a phone so they don't take room in the list itself.
 */
const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    required: true,
  },
  subtitle: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:open', 'closed'])

const titleId = `sheet-title-${useId()}`
const panel = ref(null)

let returnFocusTo = null
let locked = false

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function close() {
  emit('update:open', false)
}

// Escape closes; Tab stays inside the sheet, since the page behind it is
// covered and should not take focus.
function handleKeydown(event) {
  if (event.key === 'Escape') {
    event.stopPropagation()
    close()
    return
  }

  if (event.key !== 'Tab' || !panel.value) return

  const focusable = [...panel.value.querySelectorAll(FOCUSABLE)]
  if (focusable.length === 0) {
    event.preventDefault()
    return
  }

  const first = focusable[0]
  const last = focusable[focusable.length - 1]

  if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function release() {
  if (!locked) return

  locked = false
  unlockScroll()

  const target = returnFocusTo
  returnFocusTo = null

  if (target && typeof target.focus === 'function' && document.contains(target)) {
    target.focus()
  }
}

watch(
  () => props.open,
  async (isOpen) => {
    if (isOpen) {
      if (locked) return

      returnFocusTo = document.activeElement
      locked = true
      lockScroll()

      // The panel itself takes focus rather than its first field, so opening
      // a sheet never pops the on-screen keyboard.
      await nextTick()
      panel.value?.focus()
      return
    }

    release()
  },
  { immediate: true },
)

onBeforeUnmount(release)
</script>

<style scoped>
.sheet-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1200;
  background: var(--dk-bg-060a1e-a50, rgba(6, 10, 30, 0.5));
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.sheet-panel {
  width: 100%;
  max-width: 560px;
  max-height: min(88dvh, 760px);
  display: flex;
  flex-direction: column;
  background: var(--surface, var(--dk-bg-ffffff, #ffffff));
  border-radius: 18px 18px 0 0;
  box-shadow: 0 -12px 40px var(--dk-shadow-0d1547-a22, rgba(13, 21, 71, 0.22));
  outline: none;
}

.sheet-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 8px 12px 18px;
  border-bottom: 1px solid var(--border-soft, var(--dk-line-0d1547-a10, rgba(13, 21, 71, 0.1)));
}

.sheet-head-copy {
  min-width: 0;
  padding-top: 2px;
}

.sheet-head h4 {
  margin: 0;
  font-size: 17px;
  font-weight: 800;
  line-height: 1.3;
  color: var(--ink, var(--dk-fg-102149, #102149));
}

.sheet-head p {
  margin: 3px 0 0;
  font-size: 13px;
  color: var(--ink-muted, var(--dk-fg-5d6b86, #5d6b86));
  overflow-wrap: anywhere;
}

.sheet-close {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  margin-top: -8px;
  border: 0;
  border-radius: 14px;
  background: transparent;
  color: var(--ink-muted, var(--dk-fg-5d6b86, #5d6b86));
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.sheet-close:hover,
.sheet-close:focus-visible {
  background: var(--dk-bg-0d1547-a06, rgba(13, 21, 71, 0.06));
  color: var(--ink, var(--dk-fg-102149, #102149));
}

.sheet-close:focus-visible {
  outline: 2px solid var(--dk-line-lnu-navy, var(--lnu-navy, var(--dk-line-1a237e, #1a237e)));
  outline-offset: -2px;
}

.sheet-body {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 14px 18px 18px;
  display: grid;
  gap: 14px;
}

.sheet-footer {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(0, 1fr);
  gap: 10px;
  padding: 12px 18px calc(12px + env(safe-area-inset-bottom, 0px));
  border-top: 1px solid var(--border-soft, var(--dk-line-0d1547-a10, rgba(13, 21, 71, 0.1)));
}

.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.2s ease;
}

.sheet-enter-active .sheet-panel,
.sheet-leave-active .sheet-panel {
  transition: transform 0.24s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}

.sheet-enter-from .sheet-panel,
.sheet-leave-to .sheet-panel {
  transform: translateY(100%);
}

@media (min-width: 641px) {
  .sheet-backdrop {
    align-items: center;
    padding: 24px;
  }

  .sheet-panel {
    border-radius: 18px;
  }

  .sheet-enter-from .sheet-panel,
  .sheet-leave-to .sheet-panel {
    transform: translateY(24px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .sheet-enter-active .sheet-panel,
  .sheet-leave-active .sheet-panel {
    transition: none;
  }
}
</style>
