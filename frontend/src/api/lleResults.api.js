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

  /**
   * LNU's and the national passing rate by exam year (`years`), and the
   * school's `this_year`.
   */
  passingRates() {
    return http.get('/lle-results/passing-rates')
  }

  /**
   * scope ('national', administrators only, or 'school'), exam_year,
   * passers, takers, passing_rate, source. The same scope and year again
   * replaces its figures. Answers with the trend as GET does.
   */
  recordPassingRate(payload) {
    return http.post('/lle-results/passing-rates', payload)
  }

  removePassingRate(id) {
    return http.delete(`/lle-results/passing-rates/${id}`)
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
