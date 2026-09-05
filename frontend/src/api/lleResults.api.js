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
}

export const lleResultsApi = new LleResultsApi()
