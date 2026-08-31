import http from './http'

class ReviewBotAdminApi {
  generate(payload) {
    return http.post('/library/review-bot/generate', payload)
  }

  listQuestions(params = {}) {
    return http.get('/library/review-bot/questions', { params })
  }

  updateQuestion(questionId, payload) {
    return http.patch(`/library/review-bot/questions/${questionId}`, payload)
  }

  publish(questionIds) {
    return http.post('/library/review-bot/publish', { question_ids: questionIds })
  }

  unpublish(questionIds) {
    return http.post('/library/review-bot/unpublish', { question_ids: questionIds })
  }

  deleteQuestion(questionId) {
    return http.delete(`/library/review-bot/questions/${questionId}`)
  }
}

export const reviewBotAdminApi = new ReviewBotAdminApi()
