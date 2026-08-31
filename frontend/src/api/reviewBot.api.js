import http from './http'

class ReviewBotApi {
  catalog() {
    return http.get('/student/review-bot/catalog')
  }

  startSession(payload) {
    return http.post('/student/review-bot/sessions', payload)
  }

  submitSession(attemptId, payload) {
    return http.post(`/student/review-bot/sessions/${attemptId}/submit`, payload)
  }

  getSession(attemptId) {
    return http.get(`/student/review-bot/sessions/${attemptId}`)
  }
}

export const reviewBotApi = new ReviewBotApi()
