import http from './http'

class StudentsApi {
  /**
   * @param {object} [params] search, year_level, archive_state, sort, direction, page, per_page
   */
  directory(params = {}) {
    return http.get('/students/directory', { params })
  }
}

export const studentsApi = new StudentsApi()
