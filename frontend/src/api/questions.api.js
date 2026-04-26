import http from './http'

class QuestionsApi {
  listBanks() {
    return http.get('/library/banks')
  }

  listBankQuestions(bankId) {
    return http.get(`/library/banks/${bankId}/questions`)
  }

  listRemedialQueue(params = {}) {
    return http.get('/library/remedial-queue', { params })
  }

  importPreview(formData) {
    return http.post('/library/import/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  }

  saveBank(payload) {
    return http.post('/library/banks', payload)
  }

  queueRemedialGeneration(bankId) {
    return http.post(`/library/banks/${bankId}/queue-remedial-generation`)
  }

  deleteBank(bankId) {
    return http.delete(`/library/banks/${bankId}`)
  }

  updateRemedialStatus(questionId, payload) {
    return http.patch(`/library/remedial-questions/${questionId}/status`, payload)
  }

  updateBankRemedialStatus(bankId, payload) {
    return http.patch(`/library/banks/${bankId}/remedial-questions/status`, payload)
  }

  updateRemedialQuestion(questionId, payload) {
    return http.patch(`/library/remedial-questions/${questionId}`, payload)
  }

  refreshRemedialQuestion(questionId) {
    return http.post(`/library/questions/${questionId}/remedial-refresh`)
  }
}

export const questionsApi = new QuestionsApi()
