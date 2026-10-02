<template>
  <section class="room-view">
    <div v-if="auditError" class="feedback danger">
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
        </Teleport>
      </header>

      <div v-if="auditLoading && auditLogs.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading logs</h4>
        <p>Retrieving recent system actions.</p>
      </div>

      <div v-else-if="auditLogs.length === 0" class="room-empty-state">
        <ClipboardList :size="42" />
        <h4>No logs yet</h4>
        <p>Audit records will appear after system activity occurs.</p>
      </div>

      <template v-else>
        <div class="management-list audit-list">
          <article v-for="log in auditLogs" :key="log.id" class="management-item">
            <div class="management-item-main">
              <strong>{{ log.description || log.action }}</strong>
              <p>{{ log.action }} • {{ formatDateTime(log.created_at) }}</p>
              <div class="management-inline">
                <span class="pill navy">{{ log.target_type || 'system' }}</span>
                <span class="pill neutral">Target #{{ log.target_id || 'n/a' }}</span>
                <span class="pill success">{{ log.actor?.name || 'System' }}</span>
              </div>
            </div>
          </article>
        </div>

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
import { AlertCircle, ClipboardList, RefreshCw } from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useAuditModule } from '../composables/useAuditModule'
import { useCompactLayout } from '../composables/useViewport'

const isCompact = useCompactLayout()

const {
  auditLogs,
  auditLoading,
  auditLoadingMore,
  auditError,
  auditRangeLabel,
  canLoadMoreAuditLogs,
  loadAuditLogs,
  loadMoreAuditLogs,
} = useAuditModule()
</script>

<style scoped src="../dashboard.css"></style>
