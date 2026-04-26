import http from './http'

class ReviewBotApi {
  overview() {
    return http.get('/student/review-bot/overview')
  }

  startAttempt(payload) {
    return http.post('/student/review-bot/start', payload)
  }

  getAttempt(attemptId) {
    return http.get(`/student/review-bot/attempts/${attemptId}`)
  }

  saveAnswer(attemptId, payload) {
    return http.patch(`/student/review-bot/attempts/${attemptId}/answers`, payload)
  }

  submitAttempt(attemptId) {
    return http.post(`/student/review-bot/attempts/${attemptId}/submit`)
  }
}

export const reviewBotApi = new ReviewBotApi()
