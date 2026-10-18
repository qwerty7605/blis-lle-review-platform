<template>
  <article class="surface-card account-card">
    <header class="account-card-head">
      <h3>Where You're Signed In <InfoTip text="Every browser this account is signed in on. Sign out any you do not recognise or no longer use." /></h3>
    </header>

    <div v-if="loading && sessions.length === 0" class="room-detail-loading">
      <RefreshCw :size="15" class="spin-soft" />
      <span>Loading your sign-ins...</span>
    </div>

    <ul v-else class="account-session-list">
      <li v-for="session in sessions" :key="session.id" class="account-session">
        <span class="account-session-icon" aria-hidden="true">
          <Smartphone v-if="isPhone(session.device)" :size="18" />
          <Monitor v-else :size="18" />
        </span>

        <span class="account-session-copy">
          <strong>
            {{ session.device }}
            <span v-if="session.current" class="pill success">This Device</span>
          </strong>
          <span>
            {{ session.current ? 'Active now' : `Last used ${formatWhen(session.last_used_at)}` }}
            &middot; signed in {{ formatWhen(session.signed_in_at) }}
          </span>
          <span>{{ session.remembered ? 'Stays signed in for 7 days' : 'Signs out when the browser closes or after 8 hours unused' }}</span>
        </span>

        <button
          v-if="!session.current"
          type="button"
          class="ghost-btn"
          :disabled="busyId === session.id || signingOutOthers"
          @click="signOutOne(session)"
        >
          <RefreshCw v-if="busyId === session.id" :size="14" class="spin-soft" />
          <span>Sign Out</span>
        </button>
      </li>
    </ul>

    <div v-if="message" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ message }}</span></div>
    <div v-if="error" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ error }}</span></div>

    <div class="account-actions split">
      <p class="account-note">
        {{ otherCount === 0 ? 'You are not signed in anywhere else.' : `Signed in on ${otherCount} other ${otherCount === 1 ? 'device' : 'devices'}.` }}
      </p>
      <button type="button" class="primary-btn" :disabled="otherCount === 0 || signingOutOthers" @click="signOutOthers">
        <RefreshCw v-if="signingOutOthers" :size="14" class="spin-soft" />
        <span>Sign Out All Other Devices</span>
      </button>
    </div>
  </article>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import { computed, onMounted, ref } from 'vue'
import { AlertCircle, CheckCircle2, Monitor, RefreshCw, Smartphone } from 'lucide-vue-next'
import { accountApi } from '@/api/account.api'
import { useLiveRefresh } from '@/utils/liveRefresh'

const sessions = ref([])
const loading = ref(false)
const error = ref('')
const message = ref('')
const busyId = ref(null)
const signingOutOthers = ref(false)

const otherCount = computed(() => sessions.value.filter((session) => !session.current).length)

const isPhone = (device) => /iPhone|Android|iPad/.test(String(device))

const whenFormat = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })

function formatWhen(value) {
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? 'at an unknown time' : whenFormat.format(date)
}

// Quiet when silent: no spinner, and a failure leaves the list as it is.
async function load({ silent = false } = {}) {
  if (!silent) {
    loading.value = true
    error.value = ''
  }

  try {
    const { data } = await accountApi.listSessions()
    sessions.value = Array.isArray(data?.sessions) ? data.sessions : []
  } catch (failure) {
    if (!silent) error.value = failure?.response?.data?.message ?? 'Unable to load your sign-ins.'
  } finally {
    if (!silent) loading.value = false
  }
}

async function signOutOne(session) {
  busyId.value = session.id
  error.value = ''
  message.value = ''

  try {
    await accountApi.signOutSession(session.id)
    message.value = `${session.device} has been signed out.`
  } catch (failure) {
    // 404: it had already ended. Either way the list below is reloaded.
    if (failure?.response?.status !== 404) {
      error.value = failure?.response?.data?.message ?? 'Unable to sign that device out.'
    }
  } finally {
    busyId.value = null
    await load({ silent: true })
  }
}

async function signOutOthers() {
  signingOutOthers.value = true
  error.value = ''
  message.value = ''

  try {
    const { data } = await accountApi.signOutOtherSessions()
    message.value = data?.message ?? 'Every other device has been signed out.'
  } catch (failure) {
    error.value = failure?.response?.data?.message ?? 'Unable to sign the other devices out.'
  } finally {
    signingOutOthers.value = false
    await load({ silent: true })
  }
}

onMounted(load)

// A sign-in made on another device while this page is open shows up when the
// reader comes back to it.
useLiveRefresh(() => load({ silent: true }), { when: () => busyId.value === null && !signingOutOthers.value })
</script>

<style scoped src="../dashboard.css"></style>
<style scoped src="./accountSettings.css"></style>
