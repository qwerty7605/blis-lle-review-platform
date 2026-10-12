import http from './http'

class LleResultsApi {
  /**
   * @param {object} [params] search, exam_year, result, page, per_page
   */
  list(params = {}) {
    return http.get('/lle-results', { params })
  }

  /** Recording the same student and exam_year again corrects that row. */
  record(payload) {
    return http.post('/lle-results', payload)
  }

  remove(id) {
    return http.delete(`/lle-results/${id}`)
  }

  /** Which Exam Outcome model is in use, and what there is to retrain it on. */
  modelStatus() {
    return http.get('/predictions/let/model')
  }

  /** Retrains now; the server keeps the current model unless the new one does at least as well. */
  retrainModel() {
    return http.post('/predictions/let/model/retrain')
  }

  /** Admin only: go back to the model before the one in use. */
  revertModel() {
    return http.post('/predictions/let/model/revert')
  }
}

export const lleResultsApi = new LleResultsApi()
