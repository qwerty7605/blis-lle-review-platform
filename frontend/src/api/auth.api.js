import http from './http'

class AuthApi {
  login(payload) {
    return http.post('/auth/login', payload)
  }

  register(payload) {
    return http.post('/auth/register', payload)
  }

  resendVerification(payload) {
    return http.post('/email/verification-notification', payload)
  }

  forgotPassword(payload) {
    return http.post('/auth/forgot-password', payload)
  }

  resetPassword(payload) {
    return http.post('/auth/reset-password', payload)
  }

  me() {
    return http.get('/auth/me')
  }

  logout() {
    return http.post('/auth/logout')
  }
}

export const authApi = new AuthApi()
