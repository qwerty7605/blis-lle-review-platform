import http from './http'

class StudentsApi {
  /**
   * @param {object} [params] search, year_level, archive_state, sort, direction, page, per_page
   */
  directory(params = {}) {
    return http.get('/students/directory', { params })
  }

  /** One student's identity plus their analytics. Staff and admins only. */
  profile(studentId) {
    return http.get(`/students/${studentId}/analytics`)
  }
}

export const studentsApi = new StudentsApi()
