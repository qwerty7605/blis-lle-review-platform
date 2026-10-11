import { onBeforeUnmount, onMounted, ref } from 'vue'

// Kept in step with the breakpoints in dashboard.css: at 900px the sidebar
// becomes a slide-over drawer under a top app bar, at 640px lists and tables
// switch to phone rows.
export const COMPACT_QUERY = '(max-width: 900px)'
export const PHONE_QUERY = '(max-width: 640px)'

function currentMatch(query) {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
  return window.matchMedia(query).matches
}

// Read synchronously on setup, not just on mount, so the first render is
// already the right layout -- a view that loads data for its layout (a page
// size, say) would otherwise fetch twice.
export function useMediaQuery(query) {
  const matches = ref(currentMatch(query))
  let mediaQueryList = null

  function sync(event) {
    matches.value = event.matches
  }

  onMounted(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return

    mediaQueryList = window.matchMedia(query)
    matches.value = mediaQueryList.matches
    mediaQueryList.addEventListener('change', sync)
  })

  onBeforeUnmount(() => {
    mediaQueryList?.removeEventListener('change', sync)
    mediaQueryList = null
  })

  return matches
}

// The width of one element, kept current (0 until it is mounted). For a
// layout that depends on the room a list really has: the window width does
// not say, because the sidebar takes 264px of it, or 84px when collapsed.
//
// initialWidth is what to report before the element exists; see
// estimatePageWidth() for the one case that needs it.
export function useElementWidth(target, initialWidth = 0) {
  const width = ref(initialWidth)
  let observer = null

  onMounted(() => {
    if (!target.value || typeof ResizeObserver === 'undefined') return

    width.value = target.value.getBoundingClientRect().width
    observer = new ResizeObserver((entries) => {
      width.value = entries[0].contentRect.width
    })
    observer.observe(target.value)
  })

  onBeforeUnmount(() => {
    observer?.disconnect()
    observer = null
  })

  return width
}

// The room a dashboard page is about to have, read before it is mounted: the
// width of the layout's content area, which is already on screen when one
// page replaces another. 0 when it is not (the first page after a reload).
//
// For a view whose layout decides what it fetches. Measured only after
// mounting, such a view would load once for the layout it guessed and again
// for the one it has.
export function estimatePageWidth() {
  if (typeof document === 'undefined') return 0

  const content = document.querySelector('.layout-content')
  if (!content) return 0

  const style = window.getComputedStyle(content)
  const width = content.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)

  return Number.isFinite(width) && width > 0 ? width : 0
}

export function useCompactLayout() {
  return useMediaQuery(COMPACT_QUERY)
}

export function usePhoneLayout() {
  return useMediaQuery(PHONE_QUERY)
}
