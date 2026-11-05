<template>
  <section ref="pageRef" class="room-view">
    <div v-if="auditError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ auditError }}</span>
    </div>

    <article class="surface-card room-shell-card is-flush">
      <header class="room-page-head is-page-title">
        <div class="room-page-title">
          <ClipboardList :size="18" />
          <h3>Audit Logs</h3>
        </div>
        <Teleport to="#layout-topbar-actions" defer :disabled="!isCompact">
          <div class="audit-head-actions">
            <button
              type="button"
              :class="isCompact ? 'appbar-icon-action' : 'ghost-btn'"
              :aria-label="isCompact ? 'Export as CSV' : undefined"
              :disabled="auditExporting || auditMeta.total === 0"
              :title="auditMeta.total ? `Download the ${auditMeta.total.toLocaleString()} entries that match as a CSV file` : ''"
              @click="exportAuditLogs"
            >
              <RefreshCw v-if="auditExporting" :size="isCompact ? 20 : 14" class="spin-soft" />
              <Download v-else :size="isCompact ? 20 : 14" />
              <template v-if="!isCompact">{{ auditExporting ? 'Exporting…' : 'Export CSV' }}</template>
            </button>
            <button
              type="button"
              :class="isCompact ? 'appbar-icon-action' : 'ghost-btn'"
              :aria-label="isCompact ? 'Refresh audit logs' : undefined"
              :disabled="auditLoading"
              @click="loadAuditLogs"
            >
              <RefreshCw :size="isCompact ? 20 : 14" :class="{ 'spin-soft': auditLoading }" />
              <template v-if="!isCompact">Refresh</template>
            </button>
          </div>
        </Teleport>
      </header>

      <!-- Filters: what most audit questions start from (who, what kind, when). -->
      <div class="audit-filters">
        <label class="audit-search">
          <Search :size="15" aria-hidden="true" />
          <input
            v-model="filters.q"
            type="search"
            class="text-input"
            placeholder="Search events, people, IP addresses…"
            aria-label="Search the audit log"
          />
        </label>

        <select v-model="filters.category" class="text-input" aria-label="Event type">
          <option value="">All events</option>
          <option v-for="category in categories" :key="category.key" :value="category.key">{{ category.label }}</option>
        </select>

        <select v-model="filters.outcome" class="text-input" aria-label="Outcome">
          <option value="">Any outcome</option>
          <option value="success">Succeeded</option>
          <option value="failure">Failed</option>
        </select>

        <label class="audit-date">
          <span>From</span>
          <input v-model="filters.from" type="date" class="text-input" :max="filters.to || undefined" />
        </label>

        <label class="audit-date">
          <span>To</span>
          <input v-model="filters.to" type="date" class="text-input" :min="filters.from || undefined" v-bind="dateChecks.bind('to')" />
        </label>

        <button v-if="activeFilterCount > 0" type="button" class="audit-clear" @click="clearFilters">
          <X :size="14" /> Clear filters
        </button>
      </div>
      <FieldError :checks="dateChecks" field="to" class="filter-field-error" />

      <div v-if="filters.actor_id || filters.target_type" class="audit-chips">
        <span v-if="filters.actor_id" class="audit-chip">
          <UserRound :size="13" aria-hidden="true" />
          By {{ filterNames.actor || `user #${filters.actor_id}` }}
          <button type="button" aria-label="Remove this filter" @click="setFilter({ actor_id: '' })"><X :size="13" /></button>
        </span>
        <span v-if="filters.target_type" class="audit-chip">
          <Crosshair :size="13" aria-hidden="true" />
          {{ filterNames.target_type || filters.target_type }}<template v-if="filters.target_id">: {{ filterNames.target || `#${filters.target_id}` }}</template>
          <button type="button" aria-label="Remove this filter" @click="setFilter({ target_type: '', target_id: '' })"><X :size="13" /></button>
        </span>
      </div>

      <div v-if="auditLoading && auditLogs.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading logs</h4>
        <p>Retrieving recent system actions.</p>
      </div>

      <div v-else-if="auditLogs.length === 0" class="room-empty-state">
        <ClipboardList :size="42" />
        <template v-if="activeFilterCount > 0">
          <h4>No entries match</h4>
          <p>Try a wider date range, another event type, or clear the filters.</p>
        </template>
        <template v-else>
          <h4>No logs yet</h4>
          <p>Audit records will appear after system activity occurs.</p>
        </template>
      </div>

      <template v-else>
        <!-- Table where it fits, rows on a narrow page. -->
        <div v-if="!useRows" class="directory-table-wrap audit-table-wrap" :class="{ 'is-refreshing': auditLoading }">
          <table class="directory-table audit-table">
            <thead>
              <tr>
                <th scope="col">Time</th>
                <th scope="col">Actor</th>
                <th scope="col">Event</th>
                <th scope="col">Target</th>
                <th scope="col" class="audit-expand-col"><span class="visually-hidden">Details</span></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="log in auditLogs" :key="log.id">
                <tr
                  class="audit-row"
                  :class="{ 'is-open': openId === log.id, 'is-failure': log.outcome === 'failure' }"
                  @click="toggle(log.id)"
                >
                  <td class="audit-time-cell">
                    <time :datetime="log.created_at" :title="exactTime(log.created_at)">{{ shortTime(log.created_at) }}</time>
                    <small>{{ relativeTime(log.created_at) }}</small>
                  </td>
                  <td class="audit-actor-cell">
                    <strong>{{ actorName(log) }}</strong>
                    <small>{{ actorSub(log) }}</small>
                  </td>
                  <td class="audit-event-cell">
                    <strong>
                      {{ log.event }}
                      <span v-if="log.outcome === 'failure'" class="audit-failed">Failed</span>
                    </strong>
                    <small>{{ log.category_label || 'Other' }}</small>
                  </td>
                  <td class="audit-target-cell">
                    <template v-if="log.target?.type_label">
                      <strong :title="targetName(log)">{{ targetName(log) }}</strong>
                      <small>{{ log.target.type_label }}<template v-if="log.target.deleted"> · deleted</template></small>
                    </template>
                    <span v-else class="muted">—</span>
                  </td>
                  <td class="audit-expand-col">
                    <button
                      type="button"
                      class="audit-expand"
                      :aria-expanded="openId === log.id ? 'true' : 'false'"
                      :aria-label="openId === log.id ? 'Hide details' : 'Show details'"
                      @click.stop="toggle(log.id)"
                    >
                      <ChevronDown :size="16" />
                    </button>
                  </td>
                </tr>
                <tr v-if="openId === log.id" class="audit-detail-row">
                  <td colspan="5">
                    <AuditDetails :log="log" @filter="applyFromDetails" />
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <ul v-else class="audit-rows">
          <li v-for="log in auditLogs" :key="log.id" class="audit-mrow" :class="{ 'is-open': openId === log.id }">
            <button type="button" class="audit-mrow-head" :aria-expanded="openId === log.id ? 'true' : 'false'" @click="toggle(log.id)">
              <span class="audit-mrow-copy">
                <strong>
                  {{ log.event }}
                  <span v-if="log.outcome === 'failure'" class="audit-failed">Failed</span>
                </strong>
                <span>{{ actorName(log) }} · {{ relativeTime(log.created_at) }}</span>
                <small v-if="log.target?.type_label">{{ log.target.type_label }}: {{ targetName(log) }}</small>
              </span>
              <ChevronDown :size="18" class="audit-mrow-chevron" aria-hidden="true" />
            </button>
            <AuditDetails v-if="openId === log.id" :log="log" @filter="applyFromDetails" />
          </li>
        </ul>

        <div class="directory-load-more">
          <p class="muted">{{ auditRangeLabel }}</p>
          <button
            v-if="canLoadMoreAuditLogs"
            type="button"
            class="ghost-btn"
            :disabled="auditLoadingMore || auditLoading"
            @click="loadMoreAuditLogs"
          >
            <RefreshCw v-if="auditLoadingMore" :size="14" class="spin-soft" />
            {{ auditLoadingMore ? 'Loading…' : 'Load older entries' }}
          </button>
        </div>
      </template>
    </article>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { AlertCircle, ChevronDown, ClipboardList, Crosshair, Download, RefreshCw, Search, UserRound, X } from 'lucide-vue-next'
import AuditDetails from './AuditDetails.vue'
import FieldError from '@/components/FieldError.vue'
import { actorName, actorSub, exactTime, relativeTime, shortTime, targetName } from './auditFormat'
import { useAuditModule } from '../composables/useAuditModule'
import { estimatePageWidth, useCompactLayout, useElementWidth } from '../composables/useViewport'

const isCompact = useCompactLayout()
const pageRef = ref(null)
const pageWidth = useElementWidth(pageRef, estimatePageWidth())
// Five columns need about this much; narrower, each entry is a row.
const useRows = computed(() => pageWidth.value < 760)

const {
  auditLogs,
  auditMeta,
  auditLoading,
  auditLoadingMore,
  auditExporting,
  auditError,
  auditRangeLabel,
  canLoadMoreAuditLogs,
  categories,
  filterNames,
  filters,
  dateChecks,
  activeFilterCount,
  loadAuditLogs,
  loadMoreAuditLogs,
  exportAuditLogs,
  setFilter,
  clearFilters,
} = useAuditModule()

const openId = ref(null)

function toggle(id) {
  openId.value = openId.value === id ? null : id
}

function applyFromDetails(changes) {
  openId.value = null
  setFilter(changes)
}
</script>

<style scoped>
.audit-head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.audit-filters {
  display: grid;
  grid-template-columns: minmax(220px, 1.6fr) repeat(2, minmax(140px, 1fr)) repeat(2, minmax(130px, 0.9fr)) auto;
  align-items: end;
  gap: 10px;
  padding: 14px 18px;
  border-bottom: 1px solid var(--dk-line-0d1547-a10, rgba(13, 21, 71, 0.1));
}

.audit-filters .text-input {
  width: 100%;
  min-width: 0;
}

.audit-search {
  position: relative;
  display: block;
}

.audit-search svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lnu-text-muted);
  pointer-events: none;
}

.audit-search .text-input {
  width: 100%;
  padding-left: 34px;
}

.audit-date {
  display: grid;
  gap: 3px;
}

.audit-date span {
  font-size: 11px;
  font-weight: 700;
  color: var(--lnu-text-muted);
}

.audit-clear {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: var(--control-height, 38px);
  padding: 0 6px;
  border: 0;
  background: transparent;
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
  font-size: 12.5px;
  font-weight: 700;
  white-space: nowrap;
}

.audit-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 10px 18px 0;
}

.audit-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 6px 4px 10px;
  border: 1px solid var(--dk-line-1a237e-a25, rgba(26, 35, 126, 0.25));
  border-radius: 999px;
  background: var(--dk-bg-1a237e-a06, rgba(26, 35, 126, 0.06));
  color: var(--dk-fg-lnu-navy-deep, var(--lnu-navy-deep));
  font-size: 12.5px;
  font-weight: 600;
  max-width: 100%;
}

.audit-chip button {
  display: inline-flex;
  padding: 3px;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: inherit;
}

.audit-chip button:hover {
  background: var(--dk-bg-1a237e-a12, rgba(26, 35, 126, 0.12));
}

/* ---- Table ---- */
.audit-table-wrap {
  max-height: none;
}

.audit-table-wrap.is-refreshing {
  opacity: 0.6;
}

.audit-table {
  min-width: 0;
  table-layout: fixed;
}

.audit-table thead th {
  padding: 11px 12px;
  font-size: 11.5px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

/* The standard four: when, who, what, to what. The address and device are in
   an entry's details (campus traffic mostly shares one address anyway).
   With a fixed 44px last column these leave room to spare, so the table
   never runs wider than its box. */
.audit-table th:nth-child(1) { width: 16%; }
.audit-table th:nth-child(2) { width: 21%; }
.audit-table th:nth-child(3) { width: 25%; }
.audit-table th:nth-child(4) { width: 33%; }
.audit-table .audit-expand-col { width: 44px; text-align: right; }

.audit-table td {
  vertical-align: top;
}

.audit-table td > strong,
.audit-table td > span,
.audit-table td > time,
.audit-table td > small {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
}

.audit-table td > strong {
  color: var(--dk-fg-lnu-navy-deep, var(--lnu-navy-deep));
  font-weight: 700;
}

.audit-table td > small {
  margin-top: 2px;
  font-size: 11.5px;
  color: var(--lnu-text-muted);
}

.audit-time-cell time {
  color: var(--lnu-text);
  font-variant-numeric: tabular-nums;
}

.audit-row {
  cursor: pointer;
}

.audit-row:hover td {
  background: var(--dk-bg-f6f7f9, #f6f7f9);
}

.audit-row.is-open td {
  background: var(--dk-bg-1a237e-a05, rgba(26, 35, 126, 0.05));
  border-bottom-color: transparent;
}

.audit-row.is-failure td:first-child {
  box-shadow: inset 3px 0 0 var(--dk-shadow-c98a1e, #c98a1e);
}

.audit-failed {
  display: inline-block;
  margin-left: 6px;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--dk-bg-fff3dc, #fff3dc);
  color: var(--dk-fg-8a5a0b, #8a5a0b);
  font-size: 11px;
  font-weight: 700;
  vertical-align: 1px;
}

.audit-expand {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--lnu-text-muted);
}

.audit-expand svg {
  transition: transform 0.15s ease;
}

.audit-row.is-open .audit-expand svg,
.audit-mrow.is-open .audit-mrow-chevron {
  transform: rotate(180deg);
}

.audit-detail-row td {
  padding: 0 12px 14px;
  background: var(--dk-bg-1a237e-a05, rgba(26, 35, 126, 0.05));
  white-space: normal;
}

/* ---- Rows (narrow pages) ---- */
.audit-rows {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  margin: 0;
  padding: 0;
  list-style: none;
}

.audit-mrow {
  border-bottom: 1px solid var(--dk-line-0d1547-a10, rgba(13, 21, 71, 0.1));
}

.audit-mrow.is-open {
  background: var(--dk-bg-1a237e-a04, rgba(26, 35, 126, 0.04));
}

.audit-mrow-head {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 44px;
  padding: 12px 16px;
  border: 0;
  background: transparent;
  text-align: left;
  color: inherit;
}

.audit-mrow-copy {
  display: grid;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.audit-mrow-copy strong {
  color: var(--dk-fg-lnu-navy-deep, var(--lnu-navy-deep));
  font-size: 14px;
}

.audit-mrow-copy span,
.audit-mrow-copy small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--lnu-text-muted);
  font-size: 12.5px;
}

.audit-mrow-chevron {
  flex-shrink: 0;
  color: var(--lnu-text-muted);
  transition: transform 0.15s ease;
}

.audit-mrow :deep(.audit-details) {
  margin: 0 16px 14px;
}

@media (max-width: 1280px) {
  .audit-filters {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr);
  }

  .audit-search {
    grid-column: 1 / -1;
  }
}

@media (max-width: 640px) {
  .audit-filters {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    padding: 12px 16px;
  }

  .audit-clear {
    grid-column: 1 / -1;
    justify-self: start;
  }

  .audit-chips {
    padding: 10px 16px 0;
  }
}
</style>
