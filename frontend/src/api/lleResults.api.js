import http from './http'

class LleResultsApi {
  /**
   * @param {object} [params] search, user_id, exam_year, result, page, per_page
   * The reply also carries passing_averages (LleResultRules::PASSING_AVERAGES).
   */
  list(params = {}) {
    return http.get('/lle-results', { params })
  }

  /** Recording the same student and exam_year again corrects that row. */
  record(payload) {
    return http.post('/lle-results', payload)
  }

  /**
   * The batches there are, and one batch's students with what is recorded
   * for exam_year and their other_results (other years), plus passing_averages.
   */
  batch(params = {}) {
    return http.get('/lle-results/batch', { params })
  }

  /** Every student given a result, for one exam year, in one request. */
  recordBatch(payload) {
    return http.post('/lle-results/batch', payload)
  }

  remove(id) {
    return http.delete(`/lle-results/${id}`)
  }

  /** Which version of the Exam Outcome model is in use, and every version with its figures. */
  modelStatus() {
    return http.get('/predictions/let/model')
  }

  /** Admin only: retrains now; the server keeps the current model unless the new one passes its checks. */
  retrainModel() {
    return http.post('/predictions/let/model/retrain')
  }

  /** Admin only: put an earlier version back into use (1 is the original model). */
  activateModel(version) {
    return http.post('/predictions/let/model/activate', { version })
  }
}

export const lleResultsApi = new LleResultsApi()
