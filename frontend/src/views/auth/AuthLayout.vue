<template>
  <div class="auth-shell">
    <div class="auth-frame" :class="{ 'is-wide': wide }">
      <aside class="auth-panel">
        <div class="auth-panel-brand">
          <div class="auth-panel-logos">
            <img :src="lnuSeal" alt="Leyte Normal University seal" />
            <img :src="blisLogoWhite" alt="BLIS logo" />
          </div>
          <div>
            <p class="auth-panel-name">LNU LLE Platform</p>
            <p class="auth-panel-org">BLIS | Leyte Normal University</p>
          </div>
        </div>

        <div class="auth-panel-body">
          <h2>{{ panelHeading }}</h2>
          <p>{{ panelCopy }}</p>
        </div>

        <ul v-if="highlights.length" class="auth-panel-list">
          <li v-for="item in highlights" :key="item.text">
            <component :is="item.icon" :size="17" />
            <span>{{ item.text }}</span>
          </li>
        </ul>
      </aside>

      <main class="auth-main">
        <div class="auth-content">
          <header class="auth-header">
            <div class="auth-logo">
              <img :src="blisLogo" alt="" />
              <span>BLIS</span>
            </div>
            <p v-if="eyebrow" class="auth-eyebrow">{{ eyebrow }}</p>
            <h1 class="auth-title">{{ title }}</h1>
            <p v-if="subtitle" class="auth-subtitle">{{ subtitle }}</p>
          </header>

          <div class="auth-body">
            <slot />
          </div>

          <p v-if="$slots.footer" class="auth-footer">
            <slot name="footer" />
          </p>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import './auth.css'
import blisLogo from '@/assets/blis-logo.png'
import blisLogoWhite from '@/assets/blis-logo-white.png'
import lnuSeal from '@/assets/lnu-seal.png'

defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  panelHeading: { type: String, required: true },
  panelCopy: { type: String, required: true },
  // [{ icon: Component, text: String }]
  highlights: { type: Array, default: () => [] },
  // The registration form needs a wider form column for its two-up fields.
  wide: { type: Boolean, default: false },
})
</script>
