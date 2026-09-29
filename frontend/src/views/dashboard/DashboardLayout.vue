<template>
  <div class="layout-shell">
    <!--
      Up to 900px wide the sidebar is a drawer that slides over the page from
      the app bar's menu button, instead of a panel that pushed the whole page
      down. It stays in the DOM, off-screen and inert, so it can animate.
    -->
    <aside
      id="dashboard-navigation"
      class="layout-sidebar"
      :class="{ collapsed: sidebarCollapsed && !isMobileViewport, 'is-open': drawerOpen }"
      :inert="isMobileViewport && !drawerOpen"
      :aria-label="isMobileViewport ? 'Navigation' : undefined"
      @keydown.esc="closeMobileNav"
    >
      <div class="layout-sidebar-top">
        <button
          v-if="isMobileViewport"
          ref="drawerCloseButton"
          type="button"
          class="layout-toggle layout-drawer-close"
          aria-label="Close navigation"
          @click="closeMobileNav"
        >
          <X :size="18" />
        </button>
        <button
          v-else
          class="layout-toggle"
          :aria-expanded="(!sidebarCollapsed).toString()"
          aria-label="Toggle navigation"
          @click="sidebarCollapsed = !sidebarCollapsed"
        >
          <ChevronRight v-if="sidebarCollapsed" :size="16" />
          <ChevronLeft v-else :size="16" />
        </button>

        <button class="layout-brand" @click="sidebarCollapsed = false">
          <span class="layout-brand-icon">
            <GraduationCap :size="20" />
          </span>
          <span v-if="showSidebarLabels" class="layout-brand-text">
            <strong>LNU LLE</strong>
            <small>Review System</small>
          </span>
        </button>
      </div>

      <nav class="layout-nav">
        <button
          v-for="item in navItems"
          :key="item.routeName"
          class="layout-nav-item"
          :class="{ active: currentRouteName === item.routeName }"
          :aria-current="currentRouteName === item.routeName ? 'page' : undefined"
          @click="navigate(item.routeName)"
        >
          <component :is="item.icon" :size="18" class="layout-nav-icon" />
          <span v-if="showSidebarLabels" class="layout-nav-label">{{ item.label }}</span>
        </button>
      </nav>

      <div class="layout-sidebar-footer" v-if="showSidebarLabels">
        <div class="layout-user-tile">
          <span class="layout-avatar">{{ userInitials }}</span>
          <div>
            <strong>{{ displayName }}</strong>
            <small>{{ displayRole }}</small>
          </div>
        </div>

        <button class="logout-btn" @click="handleLogout">
          <LogOut :size="16" />
          Log out
        </button>
      </div>
    </aside>

    <div
      v-if="isMobileViewport"
      class="layout-scrim"
      :class="{ 'is-visible': drawerOpen }"
      aria-hidden="true"
      @click="closeMobileNav"
    ></div>

    <section class="layout-main" :inert="drawerOpen">
      <header class="layout-topbar" :class="{ 'is-fill-screen': isFillScreenRoute }">
        <button
          v-if="isMobileViewport"
          ref="menuButton"
          type="button"
          class="layout-menu-btn"
          aria-label="Open navigation"
          aria-controls="dashboard-navigation"
          :aria-expanded="drawerOpen.toString()"
          @click="openMobileNav"
        >
          <Menu :size="22" />
        </button>

        <div class="layout-topbar-copy">
          <h1>{{ pageTitle }}</h1>
          <p>{{ pageSubtitle }}</p>
        </div>

        <!-- Pages move their main action up here on narrow screens. -->
        <div id="layout-topbar-actions" class="layout-topbar-actions"></div>
      </header>

      <main class="layout-content" :class="{ 'is-fill-screen': isFillScreenRoute }">
        <RouterView />
      </main>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import { lockScroll, unlockScroll } from '@/utils/scrollLock'
import { getDashboardNavItems } from './config/dashboardNavigation'
import { useCompactLayout } from './composables/useViewport'
import {
  ChevronLeft,
  ChevronRight,
  GraduationCap,
  LogOut,
  Menu,
  X,
} from 'lucide-vue-next'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

// Desktop only: on narrow screens the sidebar is a drawer, open or closed.
const sidebarCollapsed = ref(false)
const isMobileViewport = useCompactLayout()
const mobileNavOpen = ref(false)
const menuButton = ref(null)
const drawerCloseButton = ref(null)

const drawerOpen = computed(() => isMobileViewport.value && mobileNavOpen.value)
const showSidebarLabels = computed(() => isMobileViewport.value || !sidebarCollapsed.value)

const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())

const navItems = computed(() => getDashboardNavItems(normalizedRole.value))

const currentRouteName = computed(() => String(route.name ?? ''))
// Routes that lay themselves out to fill one screen with no page-level
// scroll (see their own <style> for the flex-fill chain) instead of the
// default scrollable page -- each such view already carries its own more
// prominent heading, so the shared topbar above shrinks rather than
// repeating that weight twice.
const FILL_SCREEN_ROUTES = [
  'dashboard-home',
  'dashboard-rooms',
  'dashboard-room-management',
  'dashboard-review-bot',
]
const isFillScreenRoute = computed(() => FILL_SCREEN_ROUTES.includes(currentRouteName.value))

const pageTitle = computed(() => String(route.meta?.title ?? 'Dashboard'))
const pageSubtitle = computed(() => String(route.meta?.sub ?? 'Manage your modules and workflows.'))

const displayName = computed(() => auth.user?.name ?? 'User')
const displayRole = computed(() => {
  if (normalizedRole.value === 'staff_master_examiner') return 'Staff / Master Examiner'
  if (normalizedRole.value === 'admin') return 'Administrator'
  return 'Student'
})

const userInitials = computed(() => {
  const parts = displayName.value.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return 'U'
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

async function openMobileNav() {
  if (!isMobileViewport.value) return

  mobileNavOpen.value = true
  await nextTick()
  drawerCloseButton.value?.focus()
}

function closeMobileNav({ restoreFocus = true } = {}) {
  if (!mobileNavOpen.value) return

  mobileNavOpen.value = false

  if (restoreFocus) {
    nextTick(() => menuButton.value?.focus())
  }
}

function navigate(name) {
  if (currentRouteName.value !== name) {
    router.push({ name })
  }

  closeMobileNav({ restoreFocus: false })
}

async function handleLogout() {
  await auth.logout()
  await router.push('/login')
}

// The page behind an open drawer should not scroll under the reader's thumb.
watch(drawerOpen, (open) => {
  if (open) {
    lockScroll()
  } else {
    unlockScroll()
  }
})

// Crossing the breakpoint either way starts from a closed drawer and, back on
// desktop, an expanded sidebar -- as the resize handler here always did.
watch(isMobileViewport, (mobile) => {
  mobileNavOpen.value = false

  if (!mobile) {
    sidebarCollapsed.value = false
  }
})

// Back/forward while the drawer is open should not leave it covering the page.
watch(() => route.fullPath, () => closeMobileNav({ restoreFocus: false }))

onBeforeUnmount(() => {
  if (drawerOpen.value) unlockScroll()
})
</script>

<style scoped src="./dashboard.css"></style>
<style scoped>
.layout-shell {
  min-height: 100vh;
  min-height: 100dvh;
  display: grid;
  grid-template-columns: auto 1fr;
  background: var(--lnu-bg, #f4f6f8);
}

.layout-sidebar {
  width: 260px;
  height: 100vh;
  height: 100dvh;
  position: sticky;
  top: 0;
  align-self: start;
  overflow-x: hidden;
  border-right: 1px solid rgba(240, 208, 128, 0.24);
  background: linear-gradient(180deg, var(--lnu-navy-deep), var(--lnu-navy));
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
  transition: width 0.2s ease;
  color: rgba(255, 255, 255, 0.92);
}

.layout-sidebar.collapsed {
  width: 84px;
  padding-left: 10px;
  padding-right: 10px;
}

.layout-sidebar.collapsed .layout-sidebar-top {
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.layout-sidebar.collapsed .layout-toggle {
  width: 44px;
  height: 44px;
  border-radius: 12px;
}

.layout-sidebar.collapsed .layout-brand {
  width: 44px;
  height: 44px;
  padding: 0;
  justify-content: center;
  border-radius: 12px;
}

.layout-sidebar.collapsed .layout-brand-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
}

.layout-sidebar.collapsed .layout-nav {
  justify-items: center;
  gap: 8px;
}

.layout-sidebar.collapsed .layout-nav-item {
  width: 44px;
  height: 44px;
  padding: 0;
  justify-content: center;
  border-radius: 12px;
}

.layout-sidebar-top {
  display: flex;
  align-items: center;
  gap: 8px;
}

.layout-toggle {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  border: 1px solid rgba(240, 208, 128, 0.28);
  background: rgba(255, 255, 255, 0.07);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.layout-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  border: none;
  background: transparent;
  color: var(--lnu-white);
  text-align: left;
  padding: 4px;
}

.layout-brand-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgba(240, 208, 128, 0.18);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.layout-brand-text {
  display: grid;
  gap: 2px;
}

.layout-brand-text strong {
  font-size: 15px;
}

.layout-brand-text small {
  font-size: 13px;
  color: rgba(240, 208, 128, 0.92);
}

.layout-nav {
  margin-top: 18px;
  display: grid;
  gap: 4px;
}

.layout-nav-item {
  height: 40px;
  border: none;
  border-radius: 10px;
  background: transparent;
  color: rgba(255, 255, 255, 0.9);
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 10px;
  text-align: left;
  transition: all 0.2s ease;
}

.layout-nav-item:hover:not(.active) {
  background: rgba(255, 255, 255, 0.05);
  color: #fff;
}

.layout-nav-item.active {
  background: rgba(255, 255, 255, 0.08);
  color: var(--lnu-gold-light);
  box-shadow: inset 4px 0 0 var(--lnu-gold-light);
}

.layout-nav-label {
  font-size: 14px;
  font-weight: 600;
}

.layout-sidebar-footer {
  margin-top: auto;
  border-top: 1px solid rgba(240, 208, 128, 0.22);
  padding-top: 12px;
  display: grid;
  gap: 10px;
}

.layout-user-tile {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.06);
}

.layout-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--lnu-gold);
  color: var(--lnu-navy-deep);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
}

.layout-user-tile strong {
  display: block;
  font-size: 14px;
}

.layout-user-tile small {
  display: block;
  margin-top: 2px;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.78);
}

.layout-main {
  min-width: 0;
  height: 100vh;
  height: 100dvh;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.layout-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  border-bottom: 1px solid rgba(240, 208, 128, 0.3);
  background: linear-gradient(120deg, var(--lnu-navy), var(--lnu-navy-light));
  padding: 14px 24px;
  color: var(--lnu-white);
}

.layout-topbar h1 {
  margin: 0;
  font-size: 22px;
}

.layout-topbar p {
  margin: 4px 0 0;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.82);
}

.layout-topbar-copy {
  min-width: 0;
}

.layout-topbar-actions {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 6px;
}

.layout-topbar-actions:empty {
  display: none;
}

.layout-menu-btn {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  border: 0;
  border-radius: 12px;
  background: transparent;
  color: var(--lnu-white);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.layout-menu-btn:hover {
  background: rgba(255, 255, 255, 0.1);
}

.layout-menu-btn:focus-visible,
.layout-drawer-close:focus-visible {
  outline: 2px solid var(--lnu-gold-light);
  outline-offset: 2px;
}

/* Fill-screen pages (dashboard home, rooms) carry their own more prominent
   heading already -- see DashboardHomeView.vue's hero and RoomsView.vue's
   own page head -- so this shared topbar is redundant there and shrinks
   rather than repeating that weight twice. */
.layout-topbar.is-fill-screen {
  padding-top: 10px;
  padding-bottom: 10px;
}

.layout-topbar.is-fill-screen h1 {
  font-size: 18px;
}

.layout-topbar.is-fill-screen p {
  margin-top: 2px;
  font-size: 12px;
}

.layout-content {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 18px 22px;
  background: transparent;
}

/* A fill-screen page lays itself out to fit one screen with no page-level
   scroll (see that view's own <style>) -- becoming a flex column here lets
   it stretch to fill exactly this box. overflow stays auto (not hidden) as
   a safety net: if a screen is unusually short, the page scrolls instead of
   silently clipping content. */
.layout-content.is-fill-screen {
  display: flex;
  flex-direction: column;
  padding: 12px 20px;
  overflow-y: auto;
}

/* Narrow screens: one sticky app bar (menu, title, the page's main action)
   and the sidebar as a drawer over the page. The old layout stacked a brand
   bar, a two-line title banner and, when opened, the whole menu above the
   content -- about 180px before the page began, and 470px more with the
   menu open. */
@media (max-width: 900px) {
  .layout-shell {
    display: block;
  }

  .layout-sidebar {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    z-index: 1100;
    width: min(300px, 86vw);
    height: 100vh;
    height: 100dvh;
    padding: 14px 12px calc(14px + env(safe-area-inset-bottom, 0px));
    overflow-y: auto;
    overscroll-behavior: contain;
    transform: translateX(-100%);
    visibility: hidden;
    transition: transform 0.24s cubic-bezier(0.2, 0.8, 0.2, 1), visibility 0s linear 0.24s;
  }

  .layout-sidebar.is-open {
    transform: none;
    visibility: visible;
    box-shadow: 12px 0 40px rgba(6, 10, 30, 0.32);
    transition: transform 0.24s cubic-bezier(0.2, 0.8, 0.2, 1);
  }

  .layout-sidebar-top {
    justify-content: space-between;
    gap: 10px;
  }

  .layout-drawer-close {
    order: 2;
    width: 44px;
    height: 44px;
    border-radius: 12px;
  }

  .layout-nav-item {
    height: 48px;
    padding: 0 12px;
  }

  .layout-nav-label {
    font-size: 15px;
  }

  .logout-btn {
    height: 44px;
  }

  .layout-scrim {
    position: fixed;
    inset: 0;
    z-index: 1090;
    background: rgba(6, 10, 30, 0.5);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
  }

  .layout-scrim.is-visible {
    opacity: 1;
    pointer-events: auto;
  }

  .layout-main {
    height: auto;
    min-height: 100vh;
    min-height: 100dvh;
  }

  .layout-topbar,
  .layout-topbar.is-fill-screen {
    position: sticky;
    top: 0;
    z-index: 40;
    min-height: var(--appbar-height, 56px);
    padding: 0 8px 0 4px;
    gap: 4px;
    justify-content: flex-start;
  }

  .layout-topbar-copy {
    flex: 1;
  }

  .layout-topbar h1,
  .layout-topbar.is-fill-screen h1 {
    font-size: 18px;
    line-height: 1.25;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Each page's own header says what the page is for; the app bar only
     needs to say where you are. */
  .layout-topbar p {
    display: none;
  }

  /* The document scrolls here, not this box. Left as a scroll container
     (overflow: auto) it would also be what sticky headers inside the page
     stick to -- a box that never scrolls, so they would never stick. */
  .layout-content {
    padding: 16px;
    overflow: visible;
  }

  .layout-content.is-fill-screen {
    overflow: visible;
    display: block;
  }
}

@media (prefers-reduced-motion: reduce) {
  .layout-sidebar,
  .layout-sidebar.is-open,
  .layout-scrim {
    transition: none;
  }
}
</style>
