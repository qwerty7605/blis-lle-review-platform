import http from './http'

class SettingsApi {
  getSystem() {
    return http.get('/settings/system')
  }

  getPublicLegal() {
    return http.get('/settings/public/legal')
  }

  // The announcement banner and maintenance flag, on an endpoint every role
  // can reach -- /settings/system is staff-and-admin only.
  getPublicNotice() {
    return http.get('/settings/public/notice')
  }

  updateSystem(payload) {
    return http.put('/settings/system', payload)
  }
}

export const settingsApi = new SettingsApi()
