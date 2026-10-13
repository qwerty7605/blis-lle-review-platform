import http from './http'

// What a signed-in person changes about their own account (Settings page).
class AccountApi {
  updateProfile(payload) {
    return http.patch('/account/profile', payload)
  }

  changePassword(payload) {
    return http.put('/account/password', payload)
  }

  // Sends a link to the new address; nothing changes until it is opened.
  requestEmailChange(payload) {
    return http.post('/account/email', payload)
  }

  savePreferences(payload) {
    return http.put('/account/preferences', payload)
  }

  // Where this account is signed in.
  listSessions() {
    return http.get('/account/sessions')
  }

  signOutSession(id) {
    return http.delete(`/account/sessions/${id}`)
  }

  signOutOtherSessions() {
    return http.post('/account/sessions/sign-out-others')
  }
}

export const accountApi = new AccountApi()
