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

  /** What students have done with an exam, for its edit form (ExamEditRules). */
  editing(examId) {
    return http.get(`/exams/${examId}/editing`)
  }

  /** Extra time from the Live Board for one student's attempt. */
  giveExtraTime(examId, attemptId, payload) {
    return http.post(`/exams/${examId}/attempts/${attemptId}/extra-time`, payload)
  }

  /** The same extra time for everyone taking the exam in a room right now. */
  giveRoomExtraTime(examId, payload) {
    return http.post(`/exams/${examId}/extra-time`, payload)
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
