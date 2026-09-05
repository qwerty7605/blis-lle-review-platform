import http from './http'

class ReviewBotAdminApi {
  generate(payload) {
    return http.post('/library/review-bot/generate', payload)
  }

  listQuestions(params = {}) {
    return http.get('/library/review-bot/questions', { params })
  }

  /** Per-exam-item coverage: which cognitive levels each source item still lacks. */
  coverage(params = {}) {
    return http.get('/library/review-bot/coverage', { params })
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

  /** Declines drafts without deleting them -- see the reject route's note. */
  reject(questionIds) {
    return http.post('/library/review-bot/reject', { question_ids: questionIds })
  }

  deleteQuestion(questionId) {
    return http.delete(`/library/review-bot/questions/${questionId}`)
  }
}

export const reviewBotAdminApi = new ReviewBotAdminApi()
