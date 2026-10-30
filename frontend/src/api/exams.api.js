import http from './http'

class ExamsApi {
  list() {
    return http.get('/exams')
  }

  create(payload) {
    return http.post('/exams', payload)
  }

  update(examId, payload) {
    return http.patch(`/exams/${examId}`, payload)
  }

  remove(examId) {
    return http.delete(`/exams/${examId}`)
  }

  /** Archives the exam from every room it is still in; the exam and its results stay. */
  archive(examId) {
    return http.post(`/exams/${examId}/archive`)
  }

  liveBoard(examId, roomId) {
    return http.get(`/exams/${examId}/live-dashboard`, { params: { room_id: roomId } })
  }

  itemAnalytics(examId) {
    return http.get(`/exams/${examId}/item-analytics`)
  }

  /** Pre-test and mock board series (the six subject exams that belong together). */
  listSeries() {
    return http.get('/exam-series')
  }

  createSeries(payload) {
    return http.post('/exam-series', payload)
  }
}

export const examsApi = new ExamsApi()
