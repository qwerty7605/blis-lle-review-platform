import http from './http'

class RoomsApi {
  list() {
    return http.get('/rooms')
  }

  // Rooms put away with their results (staff and administrators).
  listArchived() {
    return http.get('/rooms', { params: { archived: 1 } })
  }

  archive(roomId) {
    return http.post(`/rooms/${roomId}/archive`)
  }

  restore(roomId) {
    return http.post(`/rooms/${roomId}/restore`)
  }

  detail(roomId) {
    return http.get(`/rooms/${roomId}`)
  }

  create(payload) {
    return http.post('/rooms', payload)
  }

  update(roomId, payload) {
    return http.patch(`/rooms/${roomId}`, payload)
  }

  remove(roomId) {
    return http.delete(`/rooms/${roomId}`)
  }

  join(payload) {
    return http.post('/rooms/join', payload)
  }

  removeMember(roomId, memberId) {
    return http.delete(`/rooms/${roomId}/members/${memberId}`)
  }

  archiveExam(roomId, examId) {
    return http.post(`/rooms/${roomId}/exams/${examId}/archive`)
  }

  restoreExam(roomId, examId) {
    return http.post(`/rooms/${roomId}/exams/${examId}/restore`)
  }

  leave(roomId) {
    return http.delete(`/rooms/${roomId}/leave`)
  }

  exportGrades(roomId, params = {}) {
    return http.get(`/rooms/${roomId}/export-grades`, {
      params,
      responseType: 'blob',
    })
  }
}

export const roomsApi = new RoomsApi()
