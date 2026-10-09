import http from './http'

class YearEndApi {
  // What closing the school year would do to each current student. Reads only.
  proposal() {
    return http.get('/admin/year-end')
  }

  // students: [{ id, action: 'graduate' | 'advance', year_level }] -- only the
  // ones the admin confirmed, each with the year level they were shown.
  apply(students) {
    return http.post('/admin/year-end', { students })
  }
}

export const yearEndApi = new YearEndApi()
