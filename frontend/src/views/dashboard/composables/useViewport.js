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

export function useCompactLayout() {
  return useMediaQuery(COMPACT_QUERY)
}

export function usePhoneLayout() {
  return useMediaQuery(PHONE_QUERY)
}
