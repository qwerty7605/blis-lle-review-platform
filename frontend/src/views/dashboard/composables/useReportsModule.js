import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { DoorOpen, FileText } from 'lucide-vue-next'
import { useDashboardDataServices } from './useDashboardDataServices'
import { useLiveRefresh } from '@/utils/liveRefresh'

// "Skipped 3: 2 did not take the exam, 1 demo account." for the bulk email.
const SKIP_REASONS = {
  no_attempt: 'did not take the exam',
  invalid_email: 'no valid email address',
  demo_account: 'demo account',
  email_not_verified: 'email not verified',
}

function skippedSummary(issues) {
  if (!issues.length) return ''
  const counts = {}
  for (const issue of issues) counts[issue.reason] = (counts[issue.reason] ?? 0) + 1
  const parts = Object.entries(counts).map(([reason, count]) => `${count} ${SKIP_REASONS[reason] ?? reason}`)
  return `Skipped ${issues.length}: ${parts.join(', ')}.`
}

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function numericValue(value) {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
}

function formatPercent(value, fallback = 'N/A') {
  const numeric = numericValue(value)
  if (numeric === null) return fallback

  return `${new Intl.NumberFormat(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(numeric)}%`
}

function extractDownloadFilename(contentDisposition, fallbackName) {
  if (typeof contentDisposition !== 'string' || contentDisposition.trim() === '') {
    return fallbackName
  }

  const utf8Match = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)
  if (utf8Match?.[1]) {
    try {
      return decodeURIComponent(utf8Match[1]).replace(/"/g, '').trim()
    } catch (error) {
      // Fallback to plain filename parsing below.
    }
  }

  const plainMatch = contentDisposition.match(/filename="?([^";]+)"?/i)
  if (plainMatch?.[1]) {
    return plainMatch[1].trim()
  }

  return fallbackName
}

function saveDownloadedBlob(response, fallbackName) {
  if (typeof window === 'undefined' || typeof document === 'undefined') return

  const blob = response?.data instanceof Blob
    ? response.data
    : new Blob([response?.data ?? ''])

  const contentDisposition = response?.headers?.['content-disposition']
  const filename = extractDownloadFilename(contentDisposition, fallbackName)

  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

async function resolveBlobApiError(error, fallbackMessage) {
  const blobPayload = error?.response?.data
  if (blobPayload instanceof Blob) {
    try {
      const text = await blobPayload.text()
      const parsed = JSON.parse(text)
      const message = String(parsed?.message ?? '').trim()
      if (message) return message
    } catch (parseError) {
      // Ignore and fall back.
    }
  }

  return firstApiError(error, fallbackMessage)
}

export function useReportsModule() {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  // Everyone the Dashboard is for: teachers, and administrators, whose
  // figures cover the whole programme unless narrowed to one teacher.
  const isStaffRole = computed(() => ['staff_master_examiner', 'faculty', 'admin'].includes(String(auth.user?.role ?? 'student').toLowerCase()))
  const reportTeachers = ref([])
  const reportTeacherId = ref('')

  const reportLoading = ref(false)
  const reportError = ref('')
  const reportMetrics = ref({})
  const reportActivity = ref([])
  const reportSessionPerformance = ref([])
  const reportSubjectPerformance = ref([])
  // Distribution / trend / cohort topics / watchlist / room comparison, all
  // from DashboardAnalyticsService::buildStaffAnalytics() and scoped the same
  // way as the metrics above, so the two never disagree.
  const reportAnalytics = ref({})
  const reportActionRows = ref([])
  const reportTargetsLoading = ref(false)
  const reportStudentsLoading = ref(false)

  const itemAnalyticsData = ref([])
  const itemAnalyticsLoading = ref(false)
  const itemAnalyticsError = ref('')
  const itemAnalyticsExamId = ref('')
  const reportExportingKey = ref('')
  const reportExportError = ref('')
  const reportExportMessage = ref('')
  const reportExportExams = ref([])
  const reportExportStudents = ref([])
  const reportExportForm = reactive({
    exam_id: '',
    room_id: '',
    student_id: '',
    verified_only: false,
  })

  const reportExportRoomOptions = computed(() => {
    const examId = Number(reportExportForm.exam_id)
    if (!Number.isFinite(examId) || examId < 1) return []

    return reportExportExams.value
      .find((exam) => Number(exam.id) === examId)
      ?.rooms ?? []
  })

  const canExportSessionReports = computed(() => (
    Number(reportExportForm.exam_id) > 0 && Number(reportExportForm.room_id) > 0
  ))

  const canExportSingleStudentReport = computed(() => (
    canExportSessionReports.value && Number(reportExportForm.student_id) > 0
  ))

  const reportMetricCards = computed(() => {
    if (!isStaffRole.value) return []

    const data = reportMetrics.value ?? {}

    return [
      {
        label: 'Managed Rooms',
        value: data.managed_rooms ?? 0,
        trend: `${data.students_enrolled ?? 0} students enrolled`,
        positive: true,
        tone: 'navy',
        icon: DoorOpen,
      },
      {
        label: 'Managed Exams',
        value: data.managed_exams ?? 0,
        trend: `${data.exam_assignments ?? 0} assignments`,
        positive: true,
        tone: 'gold',
        icon: FileText,
      },
      {
        label: 'Completion Rate',
        value: formatPercent(data.completion_rate_percent),
        trend: `${data.attempts_submitted ?? 0} completed of ${data.session_enrollments ?? 0} expected`,
        positive: Number(data.completion_rate_percent ?? 0) >= 60,
        tone: 'success',
        icon: DoorOpen,
      },
      {
        label: 'Average Score',
        value: formatPercent(data.average_score_percent),
        trend: `${formatPercent(data.pass_rate_percent)} pass rate`,
        positive: Number(data.average_score_percent ?? 0) >= 75,
        tone: 'navy',
        icon: FileText,
      },
    ]
  })

  async function loadReportExportStudents() {
    if (!Number(reportExportForm.room_id)) {
      reportExportStudents.value = []
      reportExportForm.student_id = ''
      return
    }

    reportStudentsLoading.value = true

    try {
      const { data } = await services.getRoom(Number(reportExportForm.room_id))
      // The class list is inside `room`. This read `data.members`, which is
      // never there, so the Student field stayed empty and disabled.
      reportExportStudents.value = (data?.room?.members ?? [])
        .filter((member) => String(member?.role ?? '').toLowerCase() === 'student')
        .map((member) => ({
          id: member.id,
          name: member.name,
          student_id: member.student_id,
        }))

      if (!reportExportStudents.value.some((student) => String(student.id) === String(reportExportForm.student_id))) {
        reportExportForm.student_id = ''
      }
    } catch (error) {
      reportExportStudents.value = []
      reportExportForm.student_id = ''
      reportExportError.value = firstApiError(error, 'Unable to load room students for individual reports.')
    } finally {
      reportStudentsLoading.value = false
    }
  }

  async function loadReportExportTargets(preserveSelection = false) {
    if (!isStaffRole.value) return

    reportTargetsLoading.value = true
    reportExportError.value = ''

    try {
      const { data } = await services.getExams()
      reportExportExams.value = (data?.exams ?? []).map((exam) => ({
        id: exam.id,
        title: exam.title,
        rooms: Array.isArray(exam.rooms) ? exam.rooms : [],
      }))

      if (!preserveSelection) {
        reportExportForm.exam_id = ''
        reportExportForm.room_id = ''
      }

      const selectedExamStillValid = reportExportExams.value.some((exam) => String(exam.id) === String(reportExportForm.exam_id))
      if (!selectedExamStillValid) {
        reportExportForm.exam_id = reportExportExams.value[0] ? String(reportExportExams.value[0].id) : ''
      }

      const roomOptions = reportExportExams.value
        .find((exam) => String(exam.id) === String(reportExportForm.exam_id))
        ?.rooms ?? []

      const selectedRoomStillValid = roomOptions.some((room) => String(room.id) === String(reportExportForm.room_id))
      if (!selectedRoomStillValid) {
        reportExportForm.room_id = roomOptions[0] ? String(roomOptions[0].id) : ''
      }

      if (!reportExportForm.room_id) {
        reportExportStudents.value = []
        reportExportForm.student_id = ''
      }
    } catch (error) {
      reportExportExams.value = []
      reportExportStudents.value = []
      reportExportForm.exam_id = ''
      reportExportForm.room_id = ''
      reportExportForm.student_id = ''
      reportExportError.value = firstApiError(error, 'Unable to load report export targets.')
    } finally {
      reportTargetsLoading.value = false
    }
  }

  async function exportReport(type) {
    if (!canExportSessionReports.value) {
      reportExportError.value = 'Select an exam and room first.'
      return
    }

    if (type === 'student_pdf' && !canExportSingleStudentReport.value) {
      reportExportError.value = 'Select a student first for individual report export.'
      return
    }

    reportExportingKey.value = type
    reportExportError.value = ''
    reportExportMessage.value = ''

    const examId = Number(reportExportForm.exam_id)
    const roomId = Number(reportExportForm.room_id)
    const studentId = Number(reportExportForm.student_id)

    const fallbackNameMap = {
      xlsx: `complete-results-${examId}-${roomId}.xlsx`,
      csv: `complete-results-${examId}-${roomId}.csv`,
      summary_pdf: `results-summary-${examId}-${roomId}.pdf`,
      answer_key_pdf: `answer-key-${examId}-${roomId}.pdf`,
      student_pdf: `student-report-${examId}-${roomId}-${studentId}.pdf`,
      student_zip: `student-reports-${examId}-${roomId}.zip`,
    }

    try {
      let response

      if (type === 'xlsx') {
        response = await services.exportCompleteResultsXlsx(examId, roomId)
      } else if (type === 'csv') {
        response = await services.exportCompleteResultsCsv(examId, roomId)
      } else if (type === 'summary_pdf') {
        response = await services.exportResultsSummaryPdf(examId, roomId)
      } else if (type === 'answer_key_pdf') {
        response = await services.exportAnswerKeyPdf(examId, roomId)
      } else if (type === 'student_pdf') {
        response = await services.exportStudentReportPdf(examId, roomId, studentId)
      } else if (type === 'student_zip') {
        response = await services.exportStudentReportsZip(examId, roomId)
      } else {
        throw new Error('Unsupported report export type.')
      }

      saveDownloadedBlob(response, fallbackNameMap[type] ?? 'report-export')
      reportExportMessage.value = 'Report export generated successfully.'
    } catch (error) {
      reportExportError.value = await resolveBlobApiError(error, 'Unable to export report.')
    } finally {
      reportExportingKey.value = ''
    }
  }

  async function sendReportEmail(type) {
    if (!canExportSessionReports.value) {
      reportExportError.value = 'Select an exam and room first.'
      return
    }

    if (type === 'student_email' && !canExportSingleStudentReport.value) {
      reportExportError.value = 'Select a student first for individual report email.'
      return
    }

    reportExportingKey.value = type
    reportExportError.value = ''
    reportExportMessage.value = ''

    const examId = Number(reportExportForm.exam_id)
    const roomId = Number(reportExportForm.room_id)
    const studentId = Number(reportExportForm.student_id)
    const payload = {
      verified_only: Boolean(reportExportForm.verified_only),
    }

    try {
      if (type === 'student_email') {
        const { data } = await services.emailStudentReportPdf(examId, roomId, studentId, payload)
        const studentName = String(data?.student?.name ?? 'Student')
        const recipientEmail = String(data?.student?.email ?? '').trim()
        reportExportMessage.value = recipientEmail
          ? `Student report emailed to ${studentName} (${recipientEmail}).`
          : 'Student report emailed successfully.'
        return
      }

      if (type === 'student_email_bulk') {
        const { data } = await services.emailStudentReportsBulk(examId, roomId, payload)
        const queued = Number(data?.summary?.queued_count ?? 0)
        reportExportMessage.value = [
          queued > 0
            ? `Sending ${queued} report ${queued === 1 ? 'email' : 'emails'} in the background; they arrive over the next few minutes.`
            : 'No report emails were sent.',
          skippedSummary(data?.issues ?? []),
        ].filter(Boolean).join(' ')
        return
      }

      throw new Error('Unsupported email action.')
    } catch (error) {
      reportExportError.value = firstApiError(error, 'Unable to send report email.')
    } finally {
      reportExportingKey.value = ''
    }
  }

  // silent: the quiet refresh behind useLiveRefresh (below).
  async function loadReports({ silent = false } = {}) {
    if (!isStaffRole.value) return

    if (!silent) {
      reportLoading.value = true
      reportError.value = ''
    }

    try {
      const { data } = await services.getReportsOverview(reportTeacherId.value)
      reportTeachers.value = Array.isArray(data.scope?.teachers) ? data.scope.teachers : []
      reportMetrics.value = data.metrics ?? {}
      reportActivity.value = data.recent_activity ?? []
      reportSessionPerformance.value = data.session_performance ?? []
      reportSubjectPerformance.value = data.subject_performance ?? []
      reportAnalytics.value = data.analytics ?? {}
      reportActionRows.value = Array.isArray(data.action_plan) ? data.action_plan : []
    } catch (error) {
      if (!silent) reportError.value = firstApiError(error, 'Unable to load report data.')
    } finally {
      if (!silent) reportLoading.value = false
    }
  }

  // The figures are read again when the teacher comes back to this page.
  // Not on a timer: this is the heaviest request a teacher's pages make.
  useLiveRefresh(() => loadReports({ silent: true }), {
    when: () => !reportLoading.value,
  })

  async function refreshAll() {
    await Promise.all([
      loadReports(),
      loadReportExportTargets(true),
    ])
  }

  watch(
    () => reportExportForm.exam_id,
    () => {
      reportExportForm.room_id = ''
      reportExportForm.student_id = ''
      reportExportStudents.value = []
      reportExportError.value = ''
      reportExportMessage.value = ''
    },
  )

  watch(
    () => reportExportForm.room_id,
    async (roomId, previousRoomId) => {
      if (String(roomId ?? '') === String(previousRoomId ?? '')) return
      reportExportForm.student_id = ''
      reportExportError.value = ''
      reportExportMessage.value = ''
      await loadReportExportStudents()
    },
  )

  watch(itemAnalyticsExamId, async (newId) => {
    if (!newId) {
      itemAnalyticsData.value = []
      return
    }
    itemAnalyticsLoading.value = true
    itemAnalyticsError.value = ''
    try {
      const { data } = await services.getItemAnalytics(Number(newId))
      itemAnalyticsData.value = data.item_analytics ?? []
    } catch (error) {
      itemAnalyticsData.value = []
      itemAnalyticsError.value = firstApiError(error, 'Unable to fetch item difficulty data.')
    } finally {
      itemAnalyticsLoading.value = false
    }
  })

  onMounted(async () => {
    await refreshAll()
  })

  /*
   * What the teacher should do about the numbers above (see
   * TeacherActionPlanner). Same shape the student Analytics page renders, so
   * both go through the shared NextStepsList component.
   */
  const reportActionPlan = computed(() => (
    reportActionRows.value.map((row, index) => ({ ...row, rank: index + 1 }))
  ))

  return {
    reportLoading,
    reportActionPlan,
    reportError,
    reportMetrics,
    reportActivity,
    reportSessionPerformance,
    reportSubjectPerformance,
    reportAnalytics,
    reportTargetsLoading,
    reportStudentsLoading,
    reportExportingKey,
    reportExportError,
    reportExportMessage,
    reportExportExams,
    reportExportStudents,
    reportExportForm,
    reportExportRoomOptions,
    canExportSessionReports,
    canExportSingleStudentReport,
    reportMetricCards,
    formatPercent,
    loadReports,
    reportTeachers,
    reportTeacherId,
    loadReportExportTargets,
    exportReport,
    sendReportEmail,
    refreshAll,
    
    itemAnalyticsData,
    itemAnalyticsLoading,
    itemAnalyticsError,
    itemAnalyticsExamId,
  }
}
