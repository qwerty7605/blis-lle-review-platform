import http from './http'

// Feedback from the Settings page, and the administrator's inbox of it.
class FeedbackApi {
  send(payload) {
    return http.post('/feedback', payload)
  }

  mine() {
    return http.get('/feedback')
  }

  list(params) {
    return http.get('/admin/feedback', { params })
  }

  get(id) {
    return http.get(`/admin/feedback/${id}`)
  }

  // action: send_to_developers | answer | decline | done | reopen
  decide(id, payload) {
    return http.patch(`/admin/feedback/${id}`, payload)
  }
}

export const feedbackApi = new FeedbackApi()
