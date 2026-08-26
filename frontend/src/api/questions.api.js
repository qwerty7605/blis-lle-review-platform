import http from './http'

class QuestionsApi {
  listBanks() {
    return http.get('/library/banks')
  }

  listBankQuestions(bankId) {
    return http.get(`/library/banks/${bankId}/questions`)
  }

  importPreview(formData) {
    return http.post('/library/import/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  }

  saveBank(payload) {
    return http.post('/library/banks', payload)
  }

  deleteBank(bankId) {
    return http.delete(`/library/banks/${bankId}`)
  }

  listTopics(subject) {
    return http.get('/library/topics', { params: subject ? { subject } : {} })
  }

  queueMapping(bankId, mode) {
    return http.post(`/library/banks/${bankId}/queue-mapping`, mode ? { mode } : {})
  }

  listMappingQueue() {
    return http.get('/library/mapping-queue')
  }

  updateBankTagsStatus(bankId) {
    return http.patch(`/library/banks/${bankId}/tags/status`)
  }

  updateQuestionTags(questionId, payload) {
    return http.patch(`/library/questions/${questionId}/tags`, payload)
  }
}

export const questionsApi = new QuestionsApi()
