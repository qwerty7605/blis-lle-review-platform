import { analyticsApi } from '@/api/analytics.api'
import { auditApi } from '@/api/audit.api'
import { examsApi } from '@/api/exams.api'
import { questionsApi } from '@/api/questions.api'
import { reportsApi } from '@/api/reports.api'
import { roomsApi } from '@/api/rooms.api'
import { settingsApi } from '@/api/settings.api'
import { studentExamsApi } from '@/api/studentExams.api'
import { studentsApi } from '@/api/students.api'
import { lleResultsApi } from '@/api/lleResults.api'
import { usersApi } from '@/api/users.api'

export function useDashboardDataServices() {
  return {
    getStudentAnalyticsOverview: () => analyticsApi.overview(),

    getAttempt: (attemptId) => studentExamsApi.getAttempt(attemptId),
    startExam: (examId, payload) => studentExamsApi.startExam(examId, payload),
    saveAnswer: (attemptId, payload) => studentExamsApi.saveAnswer(attemptId, payload),
    bookmarkQuestion: (attemptId, questionId, payload) => studentExamsApi.bookmarkQuestion(attemptId, questionId, payload),
    submitAttempt: (attemptId) => studentExamsApi.submitAttempt(attemptId),

    getRooms: () => roomsApi.list(),
    getRoom: (roomId) => roomsApi.detail(roomId),
    createRoom: (payload) => roomsApi.create(payload),
    updateRoom: (roomId, payload) => roomsApi.update(roomId, payload),
    deleteRoom: (roomId) => roomsApi.remove(roomId),
    getArchivedRooms: () => roomsApi.listArchived(),
    archiveRoom: (roomId) => roomsApi.archive(roomId),
    restoreRoom: (roomId) => roomsApi.restore(roomId),
    setRoomJoining: (roomId, acceptingStudents) => roomsApi.setJoining(roomId, acceptingStudents),
    joinRoom: (payload) => roomsApi.join(payload),
    removeRoomMember: (roomId, memberId) => roomsApi.removeMember(roomId, memberId),
    archiveRoomExam: (roomId, examId) => roomsApi.archiveExam(roomId, examId),
    restoreRoomExam: (roomId, examId) => roomsApi.restoreExam(roomId, examId),
    leaveRoom: (roomId) => roomsApi.leave(roomId),
    exportRoomGrades: (roomId, params) => roomsApi.exportGrades(roomId, params),

    getLibraryBanks: () => questionsApi.listBanks(),

    getExams: () => examsApi.list(),
    createExam: (payload) => examsApi.create(payload),
    updateExam: (examId, payload) => examsApi.update(examId, payload),
    deleteExam: (examId) => examsApi.remove(examId),
    archiveExam: (examId) => examsApi.archive(examId),
    getExamSeries: () => examsApi.listSeries(),
    createExamSeries: (payload) => examsApi.createSeries(payload),
    getLiveBoard: (examId, roomId) => examsApi.liveBoard(examId, roomId),
    getExamEditing: (examId) => examsApi.editing(examId),
    giveExtraTime: (examId, attemptId, payload) => examsApi.giveExtraTime(examId, attemptId, payload),
    giveRoomExtraTime: (examId, payload) => examsApi.giveRoomExtraTime(examId, payload),
    getItemAnalytics: (examId) => examsApi.itemAnalytics(examId),

    getReportsOverview: (teacherId) => reportsApi.overview(teacherId),
    getStudentsDirectory: (params) => studentsApi.directory(params),
    getLleResults: (params) => lleResultsApi.list(params),
    recordLleResult: (payload) => lleResultsApi.record(payload),
    getLleBatch: (params) => lleResultsApi.batch(params),
    recordLleBatch: (payload) => lleResultsApi.recordBatch(payload),
    removeLleResult: (id) => lleResultsApi.remove(id),
    getLetModelStatus: () => lleResultsApi.modelStatus(),
    retrainLetModel: () => lleResultsApi.retrainModel(),
    activateLetModel: (version) => lleResultsApi.activateModel(version),
    exportCompleteResultsXlsx: (examId, roomId) => reportsApi.completeResultsXlsx(examId, roomId),
    exportCompleteResultsCsv: (examId, roomId) => reportsApi.completeResultsCsv(examId, roomId),
    exportResultsSummaryPdf: (examId, roomId) => reportsApi.resultsSummaryPdf(examId, roomId),
    exportAnswerKeyPdf: (examId, roomId) => reportsApi.answerKeyPdf(examId, roomId),
    exportStudentReportPdf: (examId, roomId, studentId) => reportsApi.studentReportPdf(examId, roomId, studentId),
    exportStudentReportsZip: (examId, roomId) => reportsApi.studentReportsZip(examId, roomId),
    emailStudentReportPdf: (examId, roomId, studentId, payload) => reportsApi.emailStudentReportPdf(examId, roomId, studentId, payload),
    emailStudentReportsBulk: (examId, roomId, payload) => reportsApi.emailStudentReportsBulk(examId, roomId, payload),

    getSystemSettings: () => settingsApi.getSystem(),
    saveSystemSettings: (payload) => settingsApi.updateSystem(payload),

    getUsers: (params) => usersApi.list(params),
    createUser: (payload) => usersApi.create(payload),
    updateUser: (userId, payload) => usersApi.update(userId, payload),
    archiveUser: (userId) => usersApi.archive(userId),
    restoreUser: (userId) => usersApi.restore(userId),
    verifyUserEmail: (userId) => usersApi.verifyEmail(userId),
    recoverUserAccount: (userId, payload) => usersApi.recoverAccount(userId, payload),

    getAuditLogs: (params) => auditApi.list(params),
  }
}
