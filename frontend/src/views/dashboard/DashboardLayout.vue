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
            <img :src="blisLogoWhite" alt="" />
          </span>
          <span v-if="showSidebarLabels" class="layout-brand-text">
            <strong>BLIS</strong>
            <small>{{ PLATFORM_NAME }}</small>
          </span>
        </button>
      </div>

      <nav class="layout-nav">
        <template v-for="item in navItems" :key="item.routeName">
          <p v-if="item.groupLabel && showSidebarLabels" class="layout-nav-heading">{{ item.groupLabel }}</p>
          <hr v-else-if="item.groupLabel" class="layout-nav-divider" />
          <!--
            On a phone a student's rooms live here rather than as a list on the
            Rooms page itself: the page had to spend its first screenful on a
            room picker before showing any room. Desktop keeps the plain link,
            where the page has width for a sidebar of its own.
          -->
          <div v-if="showRoomsSubnav(item)" class="layout-nav-group">
            <button
              type="button"
              class="layout-nav-item layout-nav-parent"
              :class="{ active: activeNavRoute === item.routeName }"
              :aria-expanded="roomsExpanded.toString()"
              aria-controls="layout-nav-rooms"
              @click="toggleRoomsSubnav"
            >
              <component :is="item.icon" :size="18" class="layout-nav-icon" />
              <span class="layout-nav-label">{{ item.label }}</span>
              <ChevronDown
                :size="16"
                class="layout-nav-caret"
                :class="{ 'is-open': roomsExpanded }"
              />
            </button>

            <div v-show="roomsExpanded" id="layout-nav-rooms" class="layout-nav-rooms">
              <p v-if="navRoomsLoading" class="layout-nav-rooms-note">Loading rooms...</p>

              <!-- With no rooms the only useful action is reaching the page
                   that can join one, so the empty state is that link. -->
              <button
                v-else-if="navRooms.length === 0"
                type="button"
                class="layout-nav-room layout-nav-rooms-empty"
                @click="navigate(item.routeName)"
              >
                <span class="layout-nav-room-name">No rooms joined yet</span>
                <span class="layout-nav-room-code">Tap to join a room</span>
              </button>

              <button
                v-for="room in navRooms"
                :key="room.id"
                type="button"
                class="layout-nav-room"
                :class="{ active: isActiveRoom(room.id) }"
                :aria-current="isActiveRoom(room.id) ? 'page' : undefined"
                @click="openRoom(room.id)"
              >
                <span class="layout-nav-room-name">{{ room.name }}</span>
                <span class="layout-nav-room-code">{{ room.code }}</span>
              </button>
            </div>
          </div>

          <button
            v-else
            class="layout-nav-item"
            :class="{ active: activeNavRoute === item.routeName }"
            :aria-current="activeNavRoute === item.routeName ? 'page' : undefined"
            @click="navigate(item.routeName)"
          >
            <component :is="item.icon" :size="18" class="layout-nav-icon" />
            <span v-if="showSidebarLabels" class="layout-nav-label">{{ item.label }}</span>
          </button>
        </template>
      </nav>

      <div class="layout-sidebar-footer" v-if="showSidebarLabels">
        <div class="layout-user-tile">
          <span class="layout-avatar">{{ userInitials }}</span>
          <div>
            <strong>{{ displayName }}</strong>
            <small>{{ displayRole }}</small>
          </div>
        </div>

        <button class="logout-btn" @click="openLogoutModal">
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
          <!-- What the page is for sits behind the (i), not in a line under the title. -->
          <div class="layout-title-row">
            <h1>{{ pageTitle }}</h1>
            <InfoTip class="layout-title-tip" :text="pageSubtitle" :label="`About ${pageTitle}`" :size="16" :on-dark="isMobileViewport" />
          </div>
        </div>

        <!-- Pages move their main action up here on narrow screens. -->
        <div id="layout-topbar-actions" class="layout-topbar-actions"></div>

        <!-- Replays this page's guide (src/guides/). -->
        <button
          v-if="pageHasGuide"
          type="button"
          class="layout-guide-btn"
          data-guide-button
          :aria-label="`Show the guide to ${pageTitle}`"
          title="Show this page's guide"
          :disabled="guideActive"
          @click="replayGuide"
        >
          <CircleHelp :size="20" />
          <span v-if="!isMobileViewport" class="layout-guide-label">Guide</span>
        </button>
      </header>

      <!--
        An announcement an administrator wrote is addressed to everyone, so it
        sits above the page content on every dashboard screen rather than only
        on the settings form that produced it.
      -->
      <div v-if="announcement" class="layout-announcement" role="status">
        <Megaphone :size="16" class="layout-announcement-icon" />
        <p>{{ announcement }}</p>
        <button
          type="button"
          class="layout-announcement-dismiss"
          aria-label="Dismiss announcement"
          @click="dismissAnnouncement"
        >
          <X :size="15" />
        </button>
      </div>

      <main class="layout-content" :class="{ 'is-fill-screen': isFillScreenRoute }">
        <RouterView />
      </main>
    </section>

    <!-- One stray click on Log out used to end the session at once. -->
    <teleport to="body">
      <div v-if="showLogoutModal" class="modal-backdrop" @click.self="closeLogoutModal">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="logout-dialog-title">
          <header class="modal-head">
            <h4 id="logout-dialog-title">Log Out</h4>
            <button type="button" class="modal-close" aria-label="Close" @click="closeLogoutModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Are you sure you want to log out?</p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="loggingOut" @click="closeLogoutModal">Cancel</button>
            <button type="button" class="danger-btn" :disabled="loggingOut" @click="handleLogout">
              <LogOut :size="16" />
              {{ loggingOut ? 'Logging Out...' : 'Log Out' }}
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </div>
</template>

<script setup>
// The shared dashboard stylesheet, loaded once for every dashboard page. It
// used to be a <style scoped src> in each of 25 components, which put a full
// copy (165 KB) into each one's CSS: the Settings page alone loaded four.
import './dashboard.css'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import InfoTip from '@/components/InfoTip.vue'
import { roomsApi } from '@/api/rooms.api'
import { settingsApi } from '@/api/settings.api'
import { useAuthStore } from '@/store/auth.store'
import blisLogoWhite from '@/assets/blis-logo-white.png'
import { useLiveRefresh } from '@/utils/liveRefresh'
import { lockScroll, unlockScroll } from '@/utils/scrollLock'
import { getDashboardNavItems } from './config/dashboardNavigation'
import { ANNOUNCEMENT_CHANGED_EVENT } from './composables/useSettingsModule'
import { useCompactLayout } from './composables/useViewport'
import { closeGuide, guideActive, useGuide } from '@/guides/useGuide'
import { PLATFORM_NAME } from '@/brand'
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CircleHelp,
  LogOut,
  Megaphone,
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
  'dashboard-rooms',
  'dashboard-room-management',
  'dashboard-review-bot',
]
const isFillScreenRoute = computed(() => FILL_SCREEN_ROUTES.includes(currentRouteName.value))

// Pages reached from a sidebar page keep that page's item lit, so the menu
// still says where you are.
const NAV_PARENTS = {
  'dashboard-student-profile': 'dashboard-students',
  'dashboard-year-end': 'dashboard-users',
}
const activeNavRoute = computed(() => NAV_PARENTS[currentRouteName.value] ?? currentRouteName.value)

const pageTitle = computed(() => String(route.meta?.title ?? 'Dashboard'))
const pageSubtitle = computed(() => String(route.meta?.sub ?? 'Manage your modules and workflows.'))

const displayName = computed(() => auth.user?.name ?? 'User')
const displayRole = computed(() => {
  if (normalizedRole.value === 'staff_master_examiner') return 'Teacher'
  if (normalizedRole.value === 'admin') return 'Administrator'
  return 'Student'
})

const userInitials = computed(() => {
  const parts = displayName.value.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return 'U'
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

/*
 * The student's rooms, listed inside the phone nav under the Rooms item.
 *
 * Fetched here rather than shared with the Rooms page's module: /rooms is a
 * small payload, and reaching into that module's state would couple the shell
 * to a view it otherwise knows nothing about. Re-fetched each time the drawer
 * opens, which is both the moment the list is about to be read and the cheapest
 * point to notice a room was joined or left since last time.
 */
const navRooms = ref([])
const navRoomsLoading = ref(false)
const roomsExpanded = ref(false)

const isStudent = computed(() => normalizedRole.value === 'student')
const activeRoomId = computed(() => {
  const roomId = Number(route.query?.room)
  return Number.isFinite(roomId) && roomId > 0 ? roomId : null
})

function showRoomsSubnav(item) {
  return isMobileViewport.value && isStudent.value && item.routeName === 'dashboard-rooms'
}

function isActiveRoom(roomId) {
  return currentRouteName.value === 'dashboard-rooms' && activeRoomId.value === roomId
}

async function loadNavRooms() {
  if (!isStudent.value) return

  navRoomsLoading.value = true

  try {
    const { data } = await roomsApi.list()
    navRooms.value = data?.rooms ?? []
  } catch {
    // The nav still works without the list; the Rooms page reports the error.
    navRooms.value = []
  } finally {
    navRoomsLoading.value = false
  }
}

function toggleRoomsSubnav() {
  roomsExpanded.value = !roomsExpanded.value

  if (roomsExpanded.value) loadNavRooms()
}

function openRoom(roomId) {
  router.push({ name: 'dashboard-rooms', query: { room: String(roomId) } })
  closeMobileNav({ restoreFocus: false })
}

// Opening the drawer while already on Rooms shows the list expanded, so the
// room you are in is visible without an extra tap.
watch(drawerOpen, (open) => {
  if (!open || !isStudent.value) return

  if (currentRouteName.value === 'dashboard-rooms') roomsExpanded.value = true
  if (roomsExpanded.value) loadNavRooms()
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

/*
 * The administrator's announcement banner. Dismissal is remembered per text,
 * so clearing one notice does not also hide the next one an admin posts --
 * and it is per browser, which is all localStorage can honestly offer.
 */
const announcementText = ref('')
const dismissedAnnouncement = ref(readDismissedAnnouncement())

const announcement = computed(() =>
  announcementText.value && announcementText.value !== dismissedAnnouncement.value
    ? announcementText.value
    : '',
)

function readDismissedAnnouncement() {
  try {
    return localStorage.getItem('lle.dismissedAnnouncement') ?? ''
  } catch {
    // Private windows and blocked site data both throw here; a banner that
    // reappears is a far smaller problem than a layout that fails to render.
    return ''
  }
}

function dismissAnnouncement() {
  dismissedAnnouncement.value = announcementText.value

  try {
    localStorage.setItem('lle.dismissedAnnouncement', announcementText.value)
  } catch {
    // Not persisted; it stays dismissed for this page view either way.
  }
}

async function loadAnnouncement() {
  try {
    const { data } = await settingsApi.getPublicNotice()
    announcementText.value = String(data?.announcement_banner ?? '').trim()
  } catch {
    // A missing notice is not worth an error state on every dashboard page.
  }
}

onMounted(() => {
  loadAnnouncement()
  window.addEventListener(ANNOUNCEMENT_CHANGED_EVENT, loadAnnouncement)
})

onBeforeUnmount(() => {
  window.removeEventListener(ANNOUNCEMENT_CHANGED_EVENT, loadAnnouncement)
})

// An announcement is for people who are already signed in. Read once, it
// reached only those who opened the site after it was posted; it is read
// again every few minutes, whenever someone comes back to the tab, and at
// once when the Settings page saves one (ANNOUNCEMENT_CHANGED_EVENT).
useLiveRefresh(loadAnnouncement, { every: 5 * 60 * 1000 })

const showLogoutModal = ref(false)
const loggingOut = ref(false)

// On a phone the button is in the slide-over menu, which would sit on top
// of the dialog: the menu closes first.
function openLogoutModal() {
  mobileNavOpen.value = false
  showLogoutModal.value = true
}

function closeLogoutModal() {
  if (!loggingOut.value) showLogoutModal.value = false
}

// The in-app guide: offered once per page, replayed with the "?" button.
const guide = useGuide()
const pageHasGuide = computed(() => guide.hasGuide(currentRouteName.value))

function replayGuide() {
  guide.start(currentRouteName.value)
}

watch(
  currentRouteName,
  (name, previous) => {
    if (previous !== undefined) closeGuide()
    if (name) guide.offer(name)
  },
  { immediate: true },
)

onBeforeUnmount(closeGuide)

async function handleLogout() {
  closeGuide()
  loggingOut.value = true
  try {
    await auth.logout()
    showLogoutModal.value = false
    await router.push('/login')
  } finally {
    loggingOut.value = false
  }
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

// A dialog covers the page, so the page behind it should not scroll either:
// on a phone a swipe that started on the dialog's title or buttons moved the
// list underneath. Every dialog is a .modal-backdrop teleported to <body>, and
// the exam a student is taking is an .exam-attempt-backdrop there, so this
// watches for either, once, instead of each one locking for itself.
let dialogObserver = null
let dialogLocked = false

function syncDialogLock() {
  const open = document.querySelector('body > .modal-backdrop, body > .exam-attempt-backdrop') !== null
  if (open === dialogLocked) return

  dialogLocked = open
  if (open) {
    lockScroll()
  } else {
    unlockScroll()
  }
}

/*
 * A click belongs to whatever the press and the release have in common. Press
 * inside a dialog, drag (selecting the text in a field, say) and let go over
 * the dimmed area around it, and the click is the dimmed area's: the dialog
 * closed, and the form in it was gone. A click on that area counts only when
 * the press began there too; any other is dropped here, on its way down,
 * before a dialog hears it.
 */
let pressTarget = null

function rememberPress(event) {
  pressTarget = event.target
}

function dropDragOutClick(event) {
  const target = event.target
  if (!(target instanceof Element)) return
  if (!target.classList.contains('modal-backdrop') && !target.classList.contains('sheet-backdrop')) return
  if (pressTarget === target) return

  event.stopPropagation()
}

onMounted(() => {
  dialogObserver = new MutationObserver(syncDialogLock)
  dialogObserver.observe(document.body, { childList: true })
  syncDialogLock()

  document.addEventListener('pointerdown', rememberPress, true)
  document.addEventListener('click', dropDragOutClick, true)
})

onBeforeUnmount(() => {
  if (drawerOpen.value) unlockScroll()

  dialogObserver?.disconnect()
  dialogObserver = null
  document.removeEventListener('pointerdown', rememberPress, true)
  document.removeEventListener('click', dropDragOutClick, true)
  pressTarget = null
  if (dialogLocked) {
    dialogLocked = false
    unlockScroll()
  }
})
</script>

<style scoped>
.layout-shell {
  min-height: 100vh;
  min-height: 100dvh;
  display: grid;
  grid-template-columns: auto 1fr;
  background:
    radial-gradient(circle at 12% 10%, var(--dk-glow-1a237e-a07, rgba(26, 35, 126, 0.07)), transparent 34%),
    radial-gradient(circle at 88% 92%, var(--dk-glow-c9a84c-a16, rgba(201, 168, 76, 0.16)), transparent 36%),
    var(--dk-bg-lnu-bg, var(--lnu-bg, var(--dk-bg-f4f6f8, #f4f6f8)));
}

/* A rounded navy panel set in from the page edge, the same panel the
   signed-out pages use (see .auth-panel in views/auth/auth.css). */
.layout-sidebar {
  width: 264px;
  height: calc(100vh - 16px);
  height: calc(100dvh - 16px);
  margin: 8px 0 8px 8px;
  position: sticky;
  top: 8px;
  align-self: start;
  overflow-x: hidden;
  border-radius: 22px;
  background: var(--panel-navy);
  padding: 18px 14px;
  display: flex;
  flex-direction: column;
  transition: width 0.2s ease;
  color: var(--dk-fg-ffffff-a92, rgba(255, 255, 255, 0.92));
  font-family: var(--font-display);
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
  border-radius: 14px;
}

.layout-sidebar.collapsed .layout-brand {
  width: 44px;
  height: 44px;
  padding: 0;
  justify-content: center;
  border-radius: 14px;
}

.layout-sidebar.collapsed .layout-brand-icon {
  width: 44px;
  height: 44px;
  border-radius: 14px;
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
  border-radius: 14px;
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
  border: 1px solid var(--dk-line-f0d080-a28, rgba(240, 208, 128, 0.28));
  background: var(--dk-bg-ffffff-a07, rgba(255, 255, 255, 0.07));
  color: var(--dk-fg-lnu-gold-light, var(--lnu-gold-light));
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
  color: var(--dk-fg-lnu-white, var(--lnu-white));
  text-align: left;
  padding: 4px;
}

.layout-brand-icon {
  width: 40px;
  height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.layout-brand-icon img {
  width: 40px;
  height: 40px;
  display: block;
}

.layout-brand-text {
  display: grid;
  gap: 1px;
}

.layout-brand-text strong {
  font-size: 19px;
  font-weight: 700;
  line-height: 1.1;
}

.layout-brand-text small {
  font-size: 11.5px;
  font-weight: 500;
  color: var(--dk-fg-f0d080-a90, rgba(240, 208, 128, 0.9));
}

.layout-nav {
  margin-top: 22px;
  display: grid;
  gap: 6px;
  /* The menu takes what height is left and scrolls inside it, so the account
     tile and Log out below stay on screen however many pages a role has: an
     administrator's list is longer than a 640px-high laptop screen. */
  flex: 1 1 auto;
  min-height: 0;
  align-content: start;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(255, 255, 255, 0.28) transparent;
}

.layout-nav-heading {
  margin: 10px 0 2px;
  padding: 0 14px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--dk-fg-ffffff-a55, rgba(255, 255, 255, 0.55));
}

.layout-nav-heading:first-child {
  margin-top: 0;
}

.layout-nav-divider {
  width: 28px;
  margin: 6px auto;
  border: 0;
  border-top: 1px solid var(--dk-line-ffffff-a22, rgba(255, 255, 255, 0.22));
}

.layout-nav-divider:first-child {
  display: none;
}

.layout-nav-item {
  height: 44px;
  border: 1px solid transparent;
  border-radius: 14px;
  background: transparent;
  color: var(--dk-fg-ffffff-a86, rgba(255, 255, 255, 0.86));
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 13px;
  text-align: left;
  transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
}

.layout-nav-item:hover:not(.active) {
  background: var(--dk-bg-ffffff-a06, rgba(255, 255, 255, 0.06));
  color: var(--dk-fg-ffffff, #fff);
}

.layout-nav-item.active {
  background: var(--dk-bg-ffffff-a10, rgba(255, 255, 255, 0.1));
  border-color: var(--dk-line-ffffff-a16, rgba(255, 255, 255, 0.16));
  color: var(--dk-fg-lnu-gold-light, var(--lnu-gold-light));
}

.layout-nav-label {
  font-size: 14px;
  font-weight: 500;
}

.layout-nav-item.active .layout-nav-label {
  font-weight: 600;
}

.layout-sidebar-footer {
  flex: none;
  margin-top: auto;
  border-top: 1px solid var(--dk-line-f0d080-a22, rgba(240, 208, 128, 0.22));
  padding-top: 12px;
  display: grid;
  gap: 10px;
}

.layout-user-tile {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border: 1px solid var(--dk-line-ffffff-a18, rgba(255, 255, 255, 0.18));
  border-radius: 10px;
  background: var(--dk-bg-ffffff-a06, rgba(255, 255, 255, 0.06));
}

.layout-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--dk-bg-lnu-gold, var(--lnu-gold));
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
  color: var(--dk-fg-ffffff-a78, rgba(255, 255, 255, 0.78));
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
  padding: 20px 22px 6px;
  color: var(--lnu-text);
  font-family: var(--font-display);
}

/* On desktop the title sits straight on the page; the navy lives in the
   sidebar panel. Narrow screens turn this into the navy app bar (below). */
.layout-topbar h1 {
  margin: 0;
  font-size: 24px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--dk-fg-0b0d17, #0b0d17);
}

.layout-title-row {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
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
  border-radius: 14px;
  background: transparent;
  color: var(--dk-fg-lnu-white, var(--lnu-white));
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.layout-menu-btn:hover {
  background: var(--dk-bg-ffffff-a10, rgba(255, 255, 255, 0.1));
}

.layout-guide-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 38px;
  padding: 0 14px 0 11px;
  border: 1px solid var(--border-strong);
  border-radius: 999px;
  background: var(--surface);
  color: var(--ink);
  font: 600 14px/1 var(--font-display);
  cursor: pointer;
}

.layout-guide-btn:hover {
  background: var(--surface-muted);
}

.layout-guide-btn:disabled {
  opacity: 0.55;
  cursor: default;
}

.layout-guide-btn:focus-visible {
  outline: 2px solid var(--dk-line-lnu-gold, var(--lnu-gold));
  outline-offset: 2px;
}

.layout-menu-btn:focus-visible,
.layout-drawer-close:focus-visible {
  outline: 2px solid var(--dk-line-lnu-gold-light, var(--lnu-gold-light));
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

.layout-content {
  /* Anything absolutely positioned inside (screen-reader-only text, an
     InfoTip's label) is placed within this scrolling box. Without it such an
     element is placed against the whole page, below the screen, and the
     window gets a second scroll past the end of the content. */
  position: relative;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 14px 22px 24px;
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
.layout-announcement {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin: 0 24px;
  margin-top: 16px;
  padding: 11px 12px;
  border: 1px solid var(--dk-line-c9a84c-a45, rgba(201, 168, 76, 0.45));
  border-radius: var(--radius-sm, 8px);
  background: var(--dk-bg-f0d080-a16, rgba(240, 208, 128, 0.16));
  color: var(--lnu-text, var(--dk-fg-1b1f3b, #1b1f3b));
}

.layout-announcement-icon {
  flex-shrink: 0;
  margin-top: 2px;
  color: var(--dk-fg-lnu-navy, var(--lnu-navy, var(--dk-fg-1a237e, #1a237e)));
}

.layout-announcement p {
  margin: 0;
  flex: 1;
  font-size: 14px;
  line-height: 1.45;
  /* An admin can paste a long unbroken string; it must not widen the page. */
  overflow-wrap: anywhere;
}

.layout-announcement-dismiss {
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  color: var(--lnu-text-muted, var(--dk-fg-5b6079, #5b6079));
  cursor: pointer;
}

.layout-announcement-dismiss:hover {
  background: var(--dk-bg-0d1547-a08, rgba(13, 21, 71, 0.08));
  color: var(--lnu-text, var(--dk-fg-1b1f3b, #1b1f3b));
}

@media (max-width: 900px) {
  /* On the navy app bar the guide button is a plain white icon, like the menu button. */
  .layout-guide-btn {
    width: 44px;
    height: 44px;
    padding: 0;
    justify-content: center;
    border: 0;
    border-radius: 14px;
    background: transparent;
    color: var(--dk-fg-lnu-white, var(--lnu-white));
  }

  .layout-guide-btn:hover {
    background: var(--dk-bg-ffffff-a10, rgba(255, 255, 255, 0.1));
  }

  .layout-shell {
    display: block;
  }

  .layout-announcement {
    margin: 12px 16px 0;
  }

  /* Full-screen overlay navigation: the menu is the whole screen while it is
     open, sliding in from the left. A part-width drawer left a strip of the
     page showing beside it, which on a phone reads as a rendering glitch
     rather than a menu -- and gave the nav items barely half the width they
     needed, so labels wrapped. */
  .layout-sidebar {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    z-index: 1100;
    width: 100%;
    max-width: 100%;
    height: 100vh;
    height: 100dvh;
    margin: 0;
    border-radius: 0;
    padding:
      calc(14px + env(safe-area-inset-top, 0px))
      calc(16px + env(safe-area-inset-right, 0px))
      calc(14px + env(safe-area-inset-bottom, 0px))
      calc(16px + env(safe-area-inset-left, 0px));
    overflow-y: auto;
    overscroll-behavior: contain;
    transform: translateX(-100%);
    visibility: hidden;
    transition: transform 0.26s cubic-bezier(0.2, 0.8, 0.2, 1), visibility 0s linear 0.26s;
  }

  .layout-sidebar.is-open {
    transform: none;
    visibility: visible;
    transition: transform 0.26s cubic-bezier(0.2, 0.8, 0.2, 1);
  }

  .layout-sidebar-top {
    justify-content: space-between;
    gap: 10px;
    padding-bottom: 14px;
    margin-bottom: 6px;
    border-bottom: 1px solid var(--dk-line-f0d080-a18, rgba(240, 208, 128, 0.18));
  }

  .layout-drawer-close {
    order: 2;
    width: 44px;
    height: 44px;
    border-radius: 14px;
  }

  /* Full width means the list can breathe: bigger hit areas, and the nav
     fills the space instead of sitting in a narrow column. */
  .layout-nav {
    gap: 6px;
    padding-top: 10px;
  }

  .layout-nav-item {
    min-height: 52px;
    padding: 0 14px;
    border-radius: 14px;
    font-size: 16px;
  }

  .layout-nav-label {
    font-size: 16px;
  }

  /* ---- Rooms sub-navigation (phone only) ---- */
  .layout-nav-group {
    display: grid;
    gap: 4px;
  }

  .layout-nav-parent .layout-nav-label {
    flex: 1;
    text-align: left;
  }

  .layout-nav-caret {
    flex-shrink: 0;
    opacity: 0.75;
    transition: transform 0.2s ease;
  }

  .layout-nav-caret.is-open {
    transform: rotate(180deg);
  }

  /*
   * Capped and scrollable: a student in a dozen rooms would otherwise push
   * Review Bot and Analytics off the bottom of the menu. The cap is in vh so
   * it stays proportionate on a short phone in landscape.
   */
  .layout-nav-rooms {
    max-height: min(46vh, 320px);
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    display: grid;
    gap: 4px;
    margin: 2px 0 6px;
    padding: 6px;
    border-radius: 14px;
    background: var(--dk-bg-000000-a16, rgba(0, 0, 0, 0.16));
    border: 1px solid var(--dk-line-f0d080-a14, rgba(240, 208, 128, 0.14));
  }

  .layout-nav-room {
    display: grid;
    gap: 2px;
    width: 100%;
    min-height: 48px;
    padding: 8px 12px;
    border: none;
    border-radius: 10px;
    background: transparent;
    color: var(--dk-fg-ffffff-a86, rgba(255, 255, 255, 0.86));
    text-align: left;
    cursor: pointer;
  }

  .layout-nav-room:hover {
    background: var(--dk-bg-ffffff-a08, rgba(255, 255, 255, 0.08));
  }

  .layout-nav-room.active {
    background: var(--dk-bg-f0d080-a17, rgba(240, 208, 128, 0.17));
    color: var(--dk-fg-lnu-gold-light, var(--lnu-gold-light));
  }

  .layout-nav-room-name {
    font-size: 15px;
    font-weight: 600;
    /* A long room name truncates rather than wrapping to three lines. */
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .layout-nav-room-code {
    font-size: 12px;
    letter-spacing: 0.04em;
    opacity: 0.68;
  }

  .layout-nav-rooms-note {
    margin: 0;
    padding: 10px 12px;
    font-size: 13px;
    line-height: 1.4;
    color: var(--dk-fg-ffffff-a64, rgba(255, 255, 255, 0.64));
  }

  .layout-nav-rooms-empty .layout-nav-room-name {
    font-weight: 500;
    color: var(--dk-fg-ffffff-a72, rgba(255, 255, 255, 0.72));
  }

  /* Pinned to the bottom of the overlay rather than floating under a short
     nav list. */
  .layout-sidebar-footer {
    margin-top: auto;
    padding-top: 16px;
  }

  .logout-btn {
    height: 48px;
  }

  .layout-scrim {
    position: fixed;
    inset: 0;
    z-index: 1090;
    background: var(--dk-bg-060a1e-a50, rgba(6, 10, 30, 0.5));
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
    background: var(--dk-bg-lnu-navy, var(--lnu-navy));
    color: var(--dk-fg-lnu-white, var(--lnu-white));
  }

  .layout-topbar-copy {
    flex: 1;
  }

  .layout-topbar h1,
  .layout-topbar.is-fill-screen h1 {
    color: var(--dk-fg-lnu-white, var(--lnu-white));
    font-size: 18px;
    line-height: 1.25;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Each page's own header says what the page is for; the app bar only
     needs to say where you are. */
  .layout-title-row h1 {
    flex: 0 1 auto;
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
