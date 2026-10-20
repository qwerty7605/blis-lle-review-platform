<template>
  <div class="audit-details">
    <p v-if="log.description" class="audit-details-desc">{{ log.description }}</p>

    <dl class="audit-details-grid">
      <div>
        <dt>Event</dt>
        <dd>
          {{ log.event }}
          <code>{{ log.action }}</code>
        </dd>
      </div>
      <div>
        <dt>Outcome</dt>
        <dd>{{ log.outcome === 'failure' ? 'Failed or refused' : 'Succeeded' }}</dd>
      </div>
      <div>
        <dt>Time</dt>
        <dd>
          {{ exactTime(log.created_at) }}
          <small>{{ log.created_at }}</small>
        </dd>
      </div>
      <div>
        <dt>Actor</dt>
        <dd>
          {{ actorName(log) }}
          <small v-if="log.actor">{{ log.actor.email }} · {{ roleLabel(log.actor.role) }}</small>
          <button v-if="log.actor" type="button" class="audit-link" @click="$emit('filter', { actor_id: String(log.actor.id) })">
            Everything by this person
          </button>
        </dd>
      </div>
      <div v-if="log.target?.type_label">
        <dt>Target</dt>
        <dd>
          {{ targetName(log) }}
          <small>{{ log.target.type_label }}<template v-if="log.target.id"> · ID {{ log.target.id }}</template><template v-if="log.target.deleted"> · since deleted</template></small>
          <button
            v-if="log.target.id"
            type="button"
            class="audit-link"
            @click="$emit('filter', { target_type: log.target.type, target_id: String(log.target.id) })"
          >
            Everything done to this {{ log.target.type_label.toLowerCase() }}
          </button>
        </dd>
      </div>
      <div>
        <dt>IP address</dt>
        <dd><code>{{ log.ip_address || 'Not recorded' }}</code></dd>
      </div>
      <div>
        <dt>Device</dt>
        <dd>
          {{ log.device || 'Not recorded' }}
          <small v-if="log.user_agent" class="audit-agent">{{ log.user_agent }}</small>
        </dd>
      </div>
      <div>
        <dt>Entry ID</dt>
        <dd><code>{{ log.id }}</code></dd>
      </div>
    </dl>

    <div v-if="detailEntries.length > 0" class="audit-details-meta">
      <h5>Details</h5>
      <dl>
        <div v-for="[key, value] in detailEntries" :key="key">
          <dt>{{ detailLabel(key) }}</dt>
          <dd>{{ detailValue(value) }}</dd>
        </div>
      </dl>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { actorName, detailLabel, detailValue, exactTime, roleLabel, targetName } from './auditFormat'

const props = defineProps({
  log: { type: Object, required: true },
})

defineEmits(['filter'])

const detailEntries = computed(() => Object.entries(props.log.metadata ?? {}))
</script>

<style scoped>
.audit-details {
  display: grid;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid var(--dk-line-0d1547-a10, rgba(13, 21, 71, 0.1));
  border-radius: 12px;
  background: var(--dk-bg-ffffff, #fff);
  font-size: 13px;
  white-space: normal;
}

.audit-details-desc {
  margin: 0;
  color: var(--dk-fg-lnu-navy-deep, var(--lnu-navy-deep));
  font-weight: 600;
  line-height: 1.5;
}

.audit-details-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px 20px;
  margin: 0;
}

.audit-details dt {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--lnu-text-muted);
}

.audit-details dd {
  display: grid;
  gap: 2px;
  justify-items: start;
  margin: 3px 0 0;
  min-width: 0;
  color: var(--lnu-text);
  overflow-wrap: anywhere;
}

.audit-details small {
  font-size: 11.5px;
  color: var(--lnu-text-muted);
}

.audit-details code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
  color: var(--dk-fg-46526b, #46526b);
}

.audit-agent {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px !important;
}

.audit-link {
  margin-top: 2px;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--dk-fg-lnu-navy, var(--lnu-navy));
  font-size: 12px;
  font-weight: 700;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.audit-details-meta {
  padding-top: 10px;
  border-top: 1px solid var(--dk-line-0d1547-a08, rgba(13, 21, 71, 0.08));
}

.audit-details-meta h5 {
  margin: 0 0 8px;
  font-size: 12px;
  color: var(--dk-fg-lnu-navy-deep, var(--lnu-navy-deep));
}

.audit-details-meta dl {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 8px 20px;
  margin: 0;
}

@media (pointer: coarse) {
  .audit-link {
    min-height: 40px;
  }
}
</style>
