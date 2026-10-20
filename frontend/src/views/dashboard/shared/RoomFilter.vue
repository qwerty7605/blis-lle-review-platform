<template>
  <!-- Narrows a list to one room. A dropdown with its own search, not a row
       of chips: rooms only grow (every section, every year), and at a few
       dozen the chips pushed the list itself off the screen. -->
  <div ref="rootRef" class="room-filter">
    <span class="room-filter-label" :id="labelId">
      <DoorOpen :size="15" aria-hidden="true" />
      {{ label }}
    </span>

    <div class="room-filter-control">
      <button
        ref="triggerRef"
        type="button"
        class="text-input room-filter-trigger"
        :class="{ 'is-set': selectedRoom }"
        aria-haspopup="listbox"
        :aria-expanded="open"
        :aria-labelledby="`${labelId} ${triggerTextId}`"
        @click="toggle"
      >
        <span :id="triggerTextId" class="room-filter-trigger-text">{{ selectedRoom ? selectedRoom.name : allLabel }}</span>
        <ChevronDown :size="15" aria-hidden="true" />
      </button>
      <button
        v-if="selectedRoom"
        type="button"
        class="room-filter-clear"
        :aria-label="`Show all rooms, not only ${selectedRoom.name}`"
        @click="choose('')"
      >
        <X :size="14" />
      </button>

      <!-- Beside the button on a wider screen -->
      <div v-if="open && !isPhone" class="room-filter-popover" @keydown="handleKeydown">
        <div class="room-filter-panel">
          <label class="room-filter-search">
            <Search :size="15" aria-hidden="true" />
            <input ref="searchRef" v-model="query" type="search" placeholder="Find a room" aria-label="Find a room" :aria-controls="listId" />
          </label>
          <ul :id="listId" class="room-filter-list" role="listbox" :aria-labelledby="labelId">
            <template v-for="entry in entries" :key="entry.key">
              <li v-if="entry.heading" class="room-filter-group" role="presentation">{{ entry.heading }}</li>
              <li
                v-else
                :id="`${listId}-${entry.index}`"
                class="room-filter-option"
                :class="{ 'is-active': entry.index === activeIndex, 'is-selected': entry.value === modelValue, 'is-empty': entry.count === 0 }"
                role="option"
                :aria-selected="entry.value === modelValue"
                @mousemove="activeIndex = entry.index"
                @click="choose(entry.value)"
              >
                <span class="room-filter-option-name">{{ entry.name }}</span>
                <span class="room-filter-option-count">{{ entry.count }}</span>
              </li>
            </template>
            <li v-if="options.length === 1 && query" class="room-filter-none" role="presentation">No room matches "{{ query }}".</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- The same list as a sheet from the bottom on a phone -->
    <BottomSheet :open="open && isPhone" :title="`Filter by ${label.toLowerCase()}`" @update:open="(value) => { if (!value) close() }">
      <div class="room-filter-panel is-sheet" @keydown="handleKeydown">
        <label class="room-filter-search">
          <Search :size="15" aria-hidden="true" />
          <input v-model="query" type="search" placeholder="Find a room" aria-label="Find a room" />
        </label>
        <ul class="room-filter-list" role="listbox" :aria-label="label">
          <template v-for="entry in entries" :key="entry.key">
            <li v-if="entry.heading" class="room-filter-group" role="presentation">{{ entry.heading }}</li>
            <li
              v-else
              class="room-filter-option"
              :class="{ 'is-selected': entry.value === modelValue, 'is-empty': entry.count === 0 }"
              role="option"
              :aria-selected="entry.value === modelValue"
              @click="choose(entry.value)"
            >
              <span class="room-filter-option-name">{{ entry.name }}</span>
              <span class="room-filter-option-count">{{ entry.count }}</span>
            </li>
          </template>
          <li v-if="options.length === 1 && query" class="room-filter-none" role="presentation">No room matches "{{ query }}".</li>
        </ul>
      </div>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { ChevronDown, DoorOpen, Search, X } from 'lucide-vue-next'
import BottomSheet from '@/components/BottomSheet.vue'
import { usePhoneLayout } from '../composables/useViewport'

const props = defineProps({
  /** '' for every room, otherwise a room id as a string. */
  modelValue: { type: String, default: '' },
  /** [{ id, name, code, ownerName, count }]; count is what choosing it would show. */
  rooms: { type: Array, default: () => [] },
  /** The count beside "All rooms". */
  totalCount: { type: Number, default: 0 },
  /** An administrator's list: rooms under their teacher's name. */
  groupByOwner: { type: Boolean, default: false },
  label: { type: String, default: 'Room' },
  allLabel: { type: String, default: 'All rooms' },
})

const emit = defineEmits(['update:modelValue'])

const uid = Math.random().toString(36).slice(2, 8)
const labelId = `room-filter-label-${uid}`
const triggerTextId = `room-filter-value-${uid}`
const listId = `room-filter-list-${uid}`

const isPhone = usePhoneLayout()
const rootRef = ref(null)
const triggerRef = ref(null)
const searchRef = ref(null)
const open = ref(false)
const query = ref('')
const activeIndex = ref(0)

const selectedRoom = computed(() => props.rooms.find((room) => String(room.id) === props.modelValue) ?? null)

const byName = (left, right) => String(left.name).localeCompare(String(right.name), undefined, { numeric: true, sensitivity: 'base' })

// Rooms with exams first, then by name, so BLIS 1-A to 1-F stay together
// and the ones that would show nothing sink to the bottom.
const byUse = (left, right) => (left.count === 0) - (right.count === 0) || byName(left, right)

const matching = computed(() => {
  const needle = query.value.trim().toLowerCase()
  if (!needle) return props.rooms

  return props.rooms.filter((room) => (
    [room.name, room.code, room.ownerName].some((value) => String(value ?? '').toLowerCase().includes(needle))
  ))
})

// Everything that can be picked, in the order shown; "All rooms" first.
const options = computed(() => {
  const all = { value: '', name: props.allLabel, count: props.totalCount, owner: null }
  const rooms = matching.value.map((room) => ({
    value: String(room.id),
    name: room.code ? `${room.name} (${room.code})` : room.name,
    count: room.count,
    owner: room.ownerName || 'No teacher',
  }))

  if (!props.groupByOwner) return [all, ...rooms.sort(byUse)]

  return [all, ...rooms.sort((left, right) => left.owner.localeCompare(right.owner) || byUse(left, right))]
})

// The options plus a heading wherever the teacher changes.
const entries = computed(() => {
  const out = []
  let owner = null

  options.value.forEach((option, index) => {
    if (props.groupByOwner && option.owner && option.owner !== owner) {
      owner = option.owner
      out.push({ key: `group-${owner}`, heading: owner })
    }
    out.push({ ...option, key: `room-${option.value || 'all'}`, index })
  })

  return out
})

watch(query, () => { activeIndex.value = options.value.length > 1 && query.value ? 1 : 0 })

function openPanel() {
  query.value = ''
  activeIndex.value = Math.max(0, options.value.findIndex((option) => option.value === props.modelValue))
  open.value = true
  nextTick(() => {
    searchRef.value?.focus()
    scrollActiveIntoView()
  })
}

function close({ returnFocus = false } = {}) {
  open.value = false
  if (returnFocus) triggerRef.value?.focus()
}

function toggle() {
  if (open.value) close()
  else openPanel()
}

function choose(value) {
  emit('update:modelValue', value)
  close({ returnFocus: !isPhone.value })
}

function scrollActiveIntoView() {
  document.getElementById(`${listId}-${activeIndex.value}`)?.scrollIntoView({ block: 'nearest' })
}

function handleKeydown(event) {
  const last = options.value.length - 1

  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    const step = event.key === 'ArrowDown' ? 1 : -1
    activeIndex.value = Math.min(last, Math.max(0, activeIndex.value + step))
    nextTick(scrollActiveIntoView)
  } else if (event.key === 'Enter') {
    event.preventDefault()
    const option = options.value[activeIndex.value]
    if (option) choose(option.value)
  } else if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    close({ returnFocus: true })
  } else if (event.key === 'Tab') {
    close()
  }
}

// A press anywhere else closes the popover (the sheet has its own backdrop).
function handlePointerDown(event) {
  if (!open.value || isPhone.value) return
  if (!rootRef.value?.contains(event.target)) close()
}

watch(open, (value) => {
  if (value) document.addEventListener('pointerdown', handlePointerDown, true)
  else document.removeEventListener('pointerdown', handlePointerDown, true)
})

onBeforeUnmount(() => document.removeEventListener('pointerdown', handlePointerDown, true))
</script>

<style scoped>
.room-filter {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
  font-size: 13px;
  color: var(--ink-soft);
}

.room-filter-label {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  white-space: nowrap;
}

.room-filter-control {
  position: relative;
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.room-filter-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 240px;
  max-width: 100%;
  min-width: 0;
  cursor: pointer;
  text-align: left;
}

.room-filter-trigger.is-set {
  border-color: var(--dk-line-lnu-navy, var(--lnu-navy));
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
  font-weight: 600;
}

.room-filter-trigger-text {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.room-filter-clear {
  flex: none;
  width: 32px;
  height: 32px;
  border: 1px solid var(--border-soft);
  border-radius: 999px;
  background: var(--surface);
  color: var(--lnu-text-muted);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.room-filter-clear:hover {
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
  border-color: var(--dk-line-lnu-navy, var(--lnu-navy));
}

.room-filter-popover {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  z-index: 30;
  width: 320px;
  max-width: calc(100vw - 32px);
  border: 1px solid var(--border-strong);
  border-radius: 14px;
  background: var(--surface);
  box-shadow: var(--shadow-md);
  overflow: hidden;
}

.room-filter-panel {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
}

.room-filter-search {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--border-soft);
  color: var(--lnu-text-muted);
}

.room-filter-search input {
  flex: 1;
  min-width: 0;
  border: 0;
  background: transparent;
  font: inherit;
  font-size: 13.5px;
  color: var(--lnu-text);
  outline: none;
}

.room-filter-list {
  max-height: 320px;
  margin: 0;
  padding: 6px;
  list-style: none;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.room-filter-panel.is-sheet .room-filter-list {
  max-height: none;
}

.room-filter-group {
  padding: 10px 10px 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--lnu-text-muted);
}

.room-filter-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 36px;
  padding: 0 10px;
  border-radius: 9px;
  font-size: 13.5px;
  color: var(--lnu-text);
  cursor: pointer;
}

.room-filter-option.is-active {
  background: var(--dk-bg-1a237e-a07, rgba(26, 35, 126, 0.07));
}

.room-filter-option.is-selected {
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
  font-weight: 600;
}

.room-filter-option.is-empty {
  color: var(--lnu-text-muted);
}

.room-filter-option-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.room-filter-option-count {
  flex: none;
  min-width: 22px;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--dk-bg-1a237e-a07, rgba(26, 35, 126, 0.07));
  font-size: 11.5px;
  font-weight: 600;
  text-align: center;
}

.room-filter-none {
  padding: 12px 10px;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

@media (pointer: coarse) {
  .room-filter-option {
    min-height: 44px;
  }

  .room-filter-search input {
    font-size: 16px;
  }
}

@media (max-width: 640px) {
  .room-filter {
    display: flex;
    width: 100%;
  }

  .room-filter-control {
    flex: 1;
  }

  .room-filter-trigger {
    width: auto;
    flex: 1;
  }
}
</style>
