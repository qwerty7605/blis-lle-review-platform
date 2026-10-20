<template>
  <section class="dashboard-view reports-view">
    <div v-if="reportError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ reportError }}</span>
    </div>
    <div v-if="reportExportMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ reportExportMessage }}</span>
    </div>
    <div v-if="reportExportError" class="feedback danger" role="alert">
      <AlertCircle :size="15" />
      <span>{{ reportExportError }}</span>
    </div>

    <!-- Who this is for and the one page-wide action -->
    <header class="tdash-hero">
      <div class="tdash-hero-copy">
        <h2 class="tdash-hero-title">Welcome back, {{ teacherFirstName }}</h2>
        <p class="tdash-hero-body">{{ heroSummary }}</p>
        <p v-if="reportTeachers.length > 0" class="tdash-hero-scope">
          {{ reportScopeText }}
        </p>
      </div>

      <div v-if="reportTeachers.length > 1" class="tdash-hero-filter">
        <OwnerFilter v-model="reportTeacherId" :owners="reportTeachers" label="Classes of" />
      </div>

      <button type="button" class="tdash-hero-btn" :disabled="reportLoading" @click="loadReports">
        <RefreshCw :size="15" :class="{ 'spin-soft': reportLoading }" />
        Refresh
      </button>
    </header>

    <!-- Headline numbers -->
    <section aria-label="Class overview">
      <div class="stats-grid">
        <article v-for="card in reportMetricCards" :key="card.label" class="stat-card">
          <div class="stat-icon" :class="`tone-${card.tone}`">
            <component :is="card.icon" :size="17" />
          </div>
          <div>
            <p class="stat-label">{{ card.label }}</p>
            <p class="stat-value">{{ card.value }}</p>
            <p class="stat-trend" :class="{ positive: card.positive, negative: !card.positive }">
              {{ card.trend }}
            </p>
          </div>
        </article>
      </div>
    </section>

    <!-- One group of panels at a time, instead of nine stacked down the page -->
    <div v-scroll-hint class="tdash-tabs" role="tablist" aria-label="Dashboard sections">
      <button
        v-for="tab in tabs"
        :id="`tdash-tab-${tab.key}`"
        :key="tab.key"
        type="button"
        role="tab"
        class="tdash-tab"
        :class="{ active: activeTab === tab.key }"
        :aria-selected="activeTab === tab.key"
        aria-controls="tdash-panel"
        @click="activeTab = tab.key"
      >
        <component :is="tab.icon" :size="15" />
        <span>{{ tab.label }}</span>
      </button>
    </div>

    <div id="tdash-panel" role="tabpanel" :aria-labelledby="`tdash-tab-${activeTab}`">
      <div v-show="activeTab === 'overview'" class="tdash-panel">
      <!-- Distribution + trend -->
      <section aria-labelledby="rp-shape-heading">
        <header class="section-head">
          <h2 id="rp-shape-heading" class="section-title">Score Shape &amp; Direction</h2>
          <span class="section-count">{{ analytics.graded_attempts }} graded result(s)</span>
        </header>

        <div class="insight-grid">
          <article class="surface-card rp-panel">
            <header class="rp-panel-head">
              <div>
                <h3>Score Distribution</h3>
                <p>How the class is actually spread, not just the average.</p>
              </div>
            </header>

            <div v-if="reportLoading" class="rp-inline-loading">
              <RefreshCw :size="15" class="spin-soft" />
              <span>Loading...</span>
            </div>

            <div v-else-if="scoreDistribution.length === 0" class="rp-empty">
              <BarChart3 :size="22" />
              <h4>No graded results yet</h4>
              <p>Distribution appears once students submit.</p>
            </div>

            <template v-else>
              <p class="rp-callout">
                <strong>{{ passingCount }}</strong> of <strong>{{ analytics.graded_attempts }}</strong>
                results cleared the 75% mark
                <span class="rp-callout-sub">({{ passingShare }}%)</span>
              </p>

              <JsChart
                type="bar"
                :labels="distributionChart.labels"
                :datasets="distributionChart.datasets"
                :tooltip-titles="distributionChart.titles"
                value-labels
                :height="230"
                aria-label="Number of graded results in each score band"
              />

              <div class="rp-legend">
                <span><i class="rp-dot is-fail" />Below passing</span>
                <span><i class="rp-dot is-pass" />Passing</span>
              </div>
            </template>
          </article>

          <article class="surface-card rp-panel">
            <header class="rp-panel-head">
              <div>
                <h3>Performance Trend</h3>
                <p>Average score per session, oldest to newest.</p>
              </div>
              <span v-if="trendDelta !== null" class="pill" :class="trendDelta >= 0 ? 'success' : 'danger'">
                {{ trendDelta >= 0 ? '+' : '' }}{{ trendDelta }} pts
              </span>
            </header>

            <div v-if="reportLoading" class="rp-inline-loading">
              <RefreshCw :size="15" class="spin-soft" />
              <span>Loading...</span>
            </div>

            <div v-else-if="analytics.trend.length === 0" class="rp-empty">
              <TrendingUp :size="22" />
              <h4>Not enough sessions yet</h4>
              <p>Two or more graded sessions will plot a trend.</p>
            </div>

            <template v-else>
              <p class="rp-callout">{{ trendSummary }}</p>
              <JsChart
                type="line"
                :labels="trendChart.labels"
                :datasets="trendChart.datasets"
                :tooltip-titles="trendChart.titles"
                :threshold="75"
                threshold-label="75% pass mark"
                :max="100"
                unit="%"
                :height="230"
                aria-label="Average score per session, oldest to newest"
              />
            </template>
          </article>
        </div>
      </section>

      <!-- What to do about the numbers above: the part a teacher can act on. -->
      <section v-if="reportActionPlan.length > 0" aria-labelledby="rp-actions-heading">
        <header class="section-head">
          <h2 id="rp-actions-heading" class="section-title">What To Do Next</h2>
          <span class="section-note">Most urgent first</span>
        </header>

        <NextStepsList :actions="reportActionPlan" />
      </section>
      </div>

      <div v-show="activeTab === 'class'" class="tdash-panel">
      <!-- Actionable: who and what needs attention -->
      <section aria-labelledby="rp-attention-heading">
        <header class="section-head">
          <h2 id="rp-attention-heading" class="section-title">Needs Attention</h2>
        </header>

        <div class="insight-grid">
          <article class="surface-card rp-panel">
            <header class="rp-panel-head">
              <div>
                <h3>Students Below Passing</h3>
                <p>Ranked by average across your sessions, lowest first.</p>
              </div>
              <span class="pill" :class="studentsNeedingAttention.length > 0 ? 'danger' : 'success'">
                {{ studentsNeedingAttention.length }}
              </span>
            </header>

            <div v-if="reportLoading" class="rp-inline-loading">
              <RefreshCw :size="15" class="spin-soft" />
              <span>Loading...</span>
            </div>

            <div v-else-if="studentsNeedingAttention.length === 0" class="rp-empty is-good">
              <ShieldCheck :size="22" />
              <h4>Everyone is at or above 75%</h4>
              <p>No student in your sessions is averaging below the passing mark.</p>
            </div>

            <ol v-else class="rp-student-list">
              <li v-for="(student, index) in studentsNeedingAttention" :key="student.id" class="rp-student">
                <span class="rp-student-rank" aria-hidden="true">{{ index + 1 }}</span>
                <div class="rp-student-copy">
                  <strong>{{ student.name }}</strong>
                  <p>
                    {{ student.student_id || 'No student ID' }}
                    · {{ student.attempts_submitted }} graded
                    <template v-if="student.weakest_subject"> · weakest: {{ student.weakest_subject }}</template>
                  </p>
                </div>
                <strong class="rp-student-score">{{ student.average_score_percent }}%</strong>
              </li>
            </ol>

            <div v-if="topPerformers.length > 0" class="rp-top">
              <span class="rp-top-label">Top performers</span>
              <div class="rp-top-row">
                <span v-for="student in topPerformers" :key="student.id" class="rp-top-chip">
                  {{ student.name }}<em>{{ student.average_score_percent }}%</em>
                </span>
              </div>
            </div>
          </article>

          <article class="surface-card rp-panel">
            <header class="rp-panel-head">
              <div>
                <h3>Topic Accuracy</h3>
                <p>Per-question accuracy across every graded answer.</p>
              </div>
              <span class="section-count">{{ topicPerformance.length }} topic(s)</span>
            </header>

            <div v-if="reportLoading" class="rp-inline-loading">
              <RefreshCw :size="15" class="spin-soft" />
              <span>Loading...</span>
            </div>

            <div v-else-if="topicPerformance.length === 0" class="rp-empty">
              <BookOpen :size="22" />
              <h4>No mapped topics yet</h4>
              <p>Topic accuracy appears once graded answers come from mapped questions.</p>
            </div>

            <template v-else>
              <div class="rp-topic-group">
                <span class="rp-topic-label is-weak">Reteach first</span>
                <div v-for="topic in weakestTopics" :key="`weak-${topic.topic_id}`" class="rp-topic">
                  <div class="rp-topic-copy">
                    <strong>{{ topic.label }}</strong>
                    <p>{{ topic.subject }} · {{ topic.correct_count }}/{{ topic.attempted_count }} correct</p>
                  </div>
                  <div class="rp-topic-meter">
                    <div class="rp-topic-track">
                      <div class="rp-topic-fill is-weak" :style="{ width: `${clampPercent(topic.score)}%` }" />
                    </div>
                    <strong>{{ Math.round(topic.score) }}%</strong>
                  </div>
                </div>
              </div>

              <div v-if="strongestTopics.length > 0" class="rp-topic-group">
                <span class="rp-topic-label is-strong">Well understood</span>
                <div v-for="topic in strongestTopics" :key="`strong-${topic.topic_id}`" class="rp-topic">
                  <div class="rp-topic-copy">
                    <strong>{{ topic.label }}</strong>
                    <p>{{ topic.subject }} · {{ topic.correct_count }}/{{ topic.attempted_count }} correct</p>
                  </div>
                  <div class="rp-topic-meter">
                    <div class="rp-topic-track">
                      <div class="rp-topic-fill is-strong" :style="{ width: `${clampPercent(topic.score)}%` }" />
                    </div>
                    <strong>{{ Math.round(topic.score) }}%</strong>
                  </div>
                </div>
              </div>
            </template>
          </article>
        </div>
      </section>

      <!-- Breakdowns -->
      <section aria-labelledby="rp-breakdown-heading">
        <header class="section-head">
          <h2 id="rp-breakdown-heading" class="section-title">Breakdowns</h2>
        </header>

        <div class="insight-grid">
          <article class="surface-card rp-panel">
            <header class="rp-panel-head">
              <div>
                <h3>Subject Performance</h3>
                <p>Average exam score by subject.</p>
              </div>
              <span class="section-count">{{ reportSubjectPerformance.length }}</span>
            </header>

            <div v-if="reportLoading" class="rp-inline-loading">
              <RefreshCw :size="15" class="spin-soft" />
              <span>Loading subject trends...</span>
            </div>

            <div v-else-if="reportSubjectPerformance.length === 0" class="rp-empty">
              <BookOpen :size="22" />
              <h4>No subject trends yet</h4>
              <p>Submitted attempts populate the breakdown by subject.</p>
            </div>

            <JsChart
              v-else
              type="bar"
              horizontal
              :labels="subjectChart.labels"
              :datasets="subjectChart.datasets"
              :threshold="75"
              threshold-label="75%"
              :max="100"
              unit="%"
              value-labels
              :height="subjectChart.height"
              aria-label="Average exam score by subject"
            />
          </article>

          <article class="surface-card rp-panel">
            <header class="rp-panel-head">
              <div>
                <h3>Room Comparison</h3>
                <p>Which section is ahead, and which is falling behind.</p>
              </div>
              <span class="section-count">{{ roomComparison.length }}</span>
            </header>

            <div v-if="reportLoading" class="rp-inline-loading">
              <RefreshCw :size="15" class="spin-soft" />
              <span>Loading...</span>
            </div>

            <div v-else-if="roomComparison.length === 0" class="rp-empty">
              <DoorOpen :size="22" />
              <h4>No room results yet</h4>
              <p>Comparison appears once more than one room has graded results.</p>
            </div>

            <div v-else class="rp-room-list">
              <div v-for="room in roomComparison" :key="room.room_id" class="rp-room">
                <div class="rp-room-copy">
                  <strong>{{ room.room_name }}</strong>
                  <p>
                    <span v-if="room.room_code">{{ room.room_code }} · </span>
                    {{ room.students_graded }} student(s) · {{ room.graded_attempts }} graded
                  </p>
                </div>
                <div class="rp-room-stats">
                  <span class="rp-room-stat">
                    <em>Avg</em>
                    <strong :class="Number(room.average_score_percent) >= 75 ? 'rp-ok' : 'rp-bad'">
                      {{ room.average_score_percent }}%
                    </strong>
                  </span>
                  <span class="rp-room-stat">
                    <em>Pass</em>
                    <strong>{{ room.pass_rate_percent }}%</strong>
                  </span>
                </div>
              </div>
            </div>
          </article>
        </div>
      </section>
      </div>

      <div v-show="activeTab === 'exams'" class="tdash-panel">
      <!-- Session detail -->
      <section aria-labelledby="rp-session-heading">
        <header class="section-head">
          <h2 id="rp-session-heading" class="section-title">Session Performance</h2>
          <span class="section-count">{{ reportSessionPerformance.length }}</span>
        </header>

        <article class="surface-card rp-panel">
          <div v-if="reportLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading report data...</span>
          </div>

          <div v-else-if="reportSessionPerformance.length === 0" class="rp-empty">
            <BarChart3 :size="22" />
            <h4>No session data yet</h4>
            <p>Assign exams to rooms and wait for student activity to populate this view.</p>
          </div>

          <div v-else class="rp-session-list">
            <article v-for="session in reportSessionPerformance" :key="`${session.exam_id}-${session.room_id}`" class="rp-session">
              <div class="rp-session-copy">
                <strong>{{ session.exam_title }}</strong>
                <p>{{ session.room_name }}{{ session.room_code ? ` (${session.room_code})` : '' }}</p>
              </div>

              <div class="rp-session-metrics">
                <span class="pill neutral">{{ session.students_submitted }}/{{ session.students_total }} submitted</span>
                <span class="pill navy">{{ formatPercent(session.completion_rate_percent) }} done</span>
                <strong :class="Number(session.average_score_percent ?? 0) >= 75 ? 'rp-ok' : 'rp-bad'">
                  {{ formatPercent(session.average_score_percent) }}
                </strong>
              </div>
            </article>
          </div>
        </article>
      </section>

      <!-- Item analytics -->
      <section aria-labelledby="rp-item-heading">
        <header class="section-head">
          <h2 id="rp-item-heading" class="section-title">Item-Level Analytics</h2>
          <span class="section-count">Question difficulty tracking</span>
        </header>

        <article class="surface-card rp-panel">
          <label class="field-stack rp-item-picker">
            <span class="field-label">Target exam</span>
            <select v-model="itemAnalyticsExamId" class="text-input" :disabled="reportTargetsLoading">
              <option value="">Select an exam to analyze</option>
              <option v-for="exam in reportExportExams" :key="exam.id" :value="String(exam.id)">
                {{ exam.title }}
              </option>
            </select>
          </label>

          <div v-if="itemAnalyticsLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Calculating item difficulties...</span>
          </div>

          <div v-else-if="itemAnalyticsError" class="feedback danger" role="alert">
            <AlertCircle :size="15" />
            <span>{{ itemAnalyticsError }}</span>
          </div>

          <div v-else-if="itemAnalyticsExamId && itemAnalyticsData.length === 0" class="rp-empty">
            <FileText :size="22" />
            <h4>No attempts recorded</h4>
            <p>Wait for students to complete this exam to analyze item failure rates.</p>
          </div>

          <div v-else-if="itemAnalyticsData.length > 0" class="rp-item-list">
            <div v-for="item in itemAnalyticsData" :key="item.question_id" class="rp-item">
              <div class="rp-item-copy">
                <strong>{{ item.question_text }}</strong>
                <p>{{ item.total_attempts }} attempt(s) recorded</p>
              </div>
              <strong :class="item.failure_rate_percent >= 50 ? 'rp-bad' : 'rp-ok'">
                {{ item.failure_rate_percent }}% failed
              </strong>
            </div>
          </div>

          <p v-else class="rp-hint">Pick an exam above to see which questions students miss most.</p>
        </article>
      </section>
      </div>

      <div v-show="activeTab === 'exports'" class="tdash-panel">
      <!-- Export center (unchanged behaviour) -->
      <details class="surface-card collapsible-card report-export-card" open>
        <summary class="collapsible-summary">
          <div class="surface-head-copy">
            <span class="section-kicker">Export Center</span>
            <h3>Generate Session Reports <InfoTip text="Download or email result files only when you need them, without crowding the main report view." /></h3>
          </div>

          <div class="collapsible-summary-meta">
            <span class="pill" :class="canExportSessionReports ? 'success' : 'neutral'">
              {{ canExportSessionReports ? 'Ready' : 'Select exam and room' }}
            </span>
            <ChevronDown :size="18" class="collapsible-icon" />
          </div>
        </summary>

        <div class="collapsible-content">
          <div class="surface-head compact">
            <h3>Export Setup</h3>
            <button type="button" class="ghost-btn" :disabled="reportTargetsLoading" @click="loadReportExportTargets(true)">
              <RefreshCw :size="14" :class="{ 'spin-soft': reportTargetsLoading }" />
              Refresh
            </button>
          </div>

          <div v-if="reportTargetsLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading export targets...</span>
          </div>

          <template v-else>
            <div class="room-form-grid">
              <label class="field-stack">
                <span class="field-label">Exam</span>
                <select v-model="reportExportForm.exam_id" class="text-input">
                  <option value="">Select exam</option>
                  <option v-for="exam in reportExportExams" :key="exam.id" :value="String(exam.id)">
                    {{ exam.title }}
                  </option>
                </select>
              </label>

              <label class="field-stack">
                <span class="field-label">Room</span>
                <select v-model="reportExportForm.room_id" class="text-input" :disabled="!reportExportForm.exam_id">
                  <option value="">Select room</option>
                  <option v-for="room in reportExportRoomOptions" :key="room.id" :value="String(room.id)">
                    {{ room.name }}{{ room.code ? ` (${room.code})` : '' }}
                  </option>
                </select>
              </label>

              <label class="field-stack">
                <span class="field-label">Student (for one-student reports)</span>
                <select
                  v-model="reportExportForm.student_id"
                  class="text-input"
                  :disabled="!reportExportForm.room_id || reportStudentsLoading || reportExportStudents.length === 0"
                >
                  <option value="">Select student</option>
                  <option v-for="student in reportExportStudents" :key="student.id" :value="String(student.id)">
                    {{ student.name }} ({{ student.student_id || 'No ID' }})
                  </option>
                </select>
                <small class="muted">{{ reportStudentHint }}</small>
              </label>

              <div class="field-stack">
                <span class="field-label">Email delivery</span>
                <label class="check-item">
                  <input v-model="reportExportForm.verified_only" type="checkbox" />
                  <span>Send only to verified student emails</span>
                </label>
              </div>
            </div>

            <div class="overview-pill-row report-selection-row" v-if="selectedReportExam || selectedReportRoom || selectedReportStudent">
              <span v-if="selectedReportExam" class="pill navy">{{ selectedReportExam.title }}</span>
              <span v-if="selectedReportRoom" class="pill neutral">
                {{ selectedReportRoom.name }}{{ selectedReportRoom.code ? ` (${selectedReportRoom.code})` : '' }}
              </span>
              <span v-if="selectedReportStudent" class="pill success">{{ selectedReportStudent.name }}</span>
            </div>

            <!-- Each export says what it is under its name, so the buttons can
                 be told apart without opening anything. -->
            <div class="report-action-sections">
              <section v-for="group in EXPORT_GROUPS" :key="group.key" class="action-section">
                <header class="action-section-head">
                  <h4>{{ group.title }} <InfoTip :text="group.info" /></h4>
                  <p v-if="group.key === 'student' && !canExportSingleStudentReport" class="export-group-hint">
                    Pick a student in the Student field above to use these.
                  </p>
                </header>

                <div class="export-option-grid">
                  <button
                    v-for="option in group.options"
                    :key="option.key"
                    type="button"
                    class="export-option"
                    :class="{ 'is-primary': option.primary }"
                    :disabled="!(group.key === 'student' ? canExportSingleStudentReport : canExportSessionReports) || reportExportingKey !== ''"
                    @click="option.email ? sendReportEmail(option.key) : exportReport(option.key)"
                  >
                    <span class="export-option-icon" aria-hidden="true">
                      <RefreshCw v-if="reportExportingKey === option.key" :size="18" class="spin-soft" />
                      <component :is="option.icon" v-else :size="18" />
                    </span>
                    <span class="export-option-copy">
                      <span class="export-option-title">
                        {{ option.title }}
                        <span class="export-option-format">{{ option.format }}</span>
                      </span>
                      <span class="export-option-desc">{{ option.description }}</span>
                    </span>
                  </button>
                </div>
              </section>

              <p class="export-gradebook-note">
                <DoorOpen :size="14" aria-hidden="true" />
                <span>For every exam in a room at once, use <strong>Export</strong> on that room's page in Rooms (the Gradebook).</span>
              </p>
            </div>
          </template>
        </div>
      </details>

      <!-- Activity -->
      <section aria-labelledby="rp-activity-heading">
        <header class="section-head">
          <h2 id="rp-activity-heading" class="section-title">Recent Activity</h2>
          <span class="section-count">{{ reportActivity.length }}</span>
        </header>

        <article class="surface-card rp-panel">
          <div v-if="reportLoading" class="rp-inline-loading">
            <RefreshCw :size="15" class="spin-soft" />
            <span>Loading report data...</span>
          </div>

          <div v-else-if="reportActivity.length === 0" class="rp-empty">
            <ClipboardList :size="22" />
            <h4>No activity yet</h4>
            <p>Recent operational logs will appear here.</p>
          </div>

          <div v-else class="rp-activity-list">
            <div v-for="activity in reportActivity" :key="activity.id" class="rp-activity">
              <span class="rp-activity-dot" aria-hidden="true" />
              <div class="rp-activity-copy">
                <strong>{{ activity.description || activity.action }}</strong>
                <p>{{ formatDateTime(activity.created_at) }} · {{ activity.actor?.name || 'System' }}</p>
              </div>
              <span class="pill neutral">{{ activity.action }}</span>
            </div>
          </div>
        </article>
      </section>
      </div>

    </div>
  </section>
</template>

<script setup>
import InfoTip from '@/components/InfoTip.vue'
import { computed, ref, watch } from 'vue'
import {
  AlertCircle,
  BarChart3,
  BookOpen,
  CheckCircle2,
  ChevronDown,
  ClipboardList,
  DoorOpen,
  FileArchive,
  FileKey,
  FileSpreadsheet,
  FileText,
  LayoutDashboard,
  Mail,
  MailPlus,
  RefreshCw,
  Sheet,
  ShieldCheck,
  TrendingUp,
  Users,
} from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useReportsModule } from '../composables/useReportsModule'
import NextStepsList from '@/components/NextStepsList.vue'
import JsChart from '@/components/charts/JsChart.vue'
import { useAuthStore } from '@/store/auth.store'
import { vScrollHint } from '@/utils/scrollHint'
import OwnerFilter from '../shared/OwnerFilter.vue'

const {
  reportLoading,
  reportError,
  reportActivity,
  reportSessionPerformance,
  reportSubjectPerformance,
  reportAnalytics,
  reportTargetsLoading,
  reportStudentsLoading,
  reportActionPlan,
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
  loadReportExportTargets,
  exportReport,
  sendReportEmail,
  itemAnalyticsData,
  itemAnalyticsLoading,
  itemAnalyticsError,
  itemAnalyticsExamId,
  reportTeachers,
  reportTeacherId,
} = useReportsModule()

// An administrator's figures: the whole programme, or one teacher's classes.
const reportScopeText = computed(() => {
  const teacher = reportTeachers.value.find((entry) => String(entry.id) === reportTeacherId.value)

  return teacher
    ? `Showing ${teacher.name}'s rooms and exams.`
    : `Showing every teacher's rooms and exams (${reportTeachers.value.length} ${reportTeachers.value.length === 1 ? 'teacher' : 'teachers'}).`
})

watch(reportTeacherId, () => loadReports())

const PASSING_SCORE = 75

// Chart colours: below the pass mark and at or above it. Chosen as a pair that
// stays distinguishable with colour-blind vision (amber against indigo).
const COLOR_BELOW = '#c98a1e'
const COLOR_PASSING = '#3949ab'

const auth = useAuthStore()

const teacherFirstName = computed(() => (
  auth.user?.first_name
  || String(auth.user?.name ?? '').trim().split(/\s+/).filter(Boolean)[0]
  || 'Teacher'
))

// The page's sections, grouped so one group shows at a time.
const tabs = [
  { key: 'overview', label: 'Overview', icon: LayoutDashboard },
  { key: 'class', label: 'Students & Topics', icon: Users },
  { key: 'exams', label: 'Exams', icon: ClipboardList },
  { key: 'exports', label: 'Exports', icon: FileText },
]
const activeTab = ref('overview')

const analytics = computed(() => ({
  score_distribution: [],
  trend: [],
  topic_performance: [],
  students_needing_attention: [],
  top_performers: [],
  room_comparison: [],
  graded_attempts: 0,
  students_graded: 0,
  ...(reportAnalytics.value ?? {}),
}))

const scoreDistribution = computed(() => analytics.value.score_distribution)
const topicPerformance = computed(() => analytics.value.topic_performance)
const studentsNeedingAttention = computed(() => analytics.value.students_needing_attention)
const topPerformers = computed(() => analytics.value.top_performers)
const roomComparison = computed(() => analytics.value.room_comparison)

const passingCount = computed(() => (
  scoreDistribution.value
    .filter((band) => band.is_passing)
    .reduce((total, band) => total + Number(band.count ?? 0), 0)
))

const passingShare = computed(() => {
  const total = Number(analytics.value.graded_attempts ?? 0)
  if (total === 0) return 0

  return Math.round((passingCount.value / total) * 100)
})

const heroSummary = computed(() => {
  const graded = Number(analytics.value.graded_attempts ?? 0)
  if (graded === 0) return 'Results appear here as soon as your students submit an exam.'

  const students = Number(analytics.value.students_graded ?? 0)

  return `${graded} graded ${graded === 1 ? 'result' : 'results'} from ${students} `
    + `${students === 1 ? 'student' : 'students'} so far. ${passingShare.value}% cleared the 75% pass mark.`
})

// How many results fell in each score band.
const distributionChart = computed(() => ({
  labels: scoreDistribution.value.map((band) => band.label),
  titles: scoreDistribution.value.map((band) => `Scores ${band.label} (${band.percent}% of results)`),
  datasets: [{
    label: 'Results',
    data: scoreDistribution.value.map((band) => Number(band.count ?? 0)),
    colors: scoreDistribution.value.map((band) => (band.is_passing ? COLOR_PASSING : COLOR_BELOW)),
  }],
}))

// Average score per session, oldest first. Short codes on the axis, the exam
// name in the tooltip.
const trendChart = computed(() => ({
  labels: analytics.value.trend.map((row) => row.label),
  titles: analytics.value.trend.map((row) => row.exam_title),
  datasets: [{
    label: 'Average score',
    data: analytics.value.trend.map((row) => Math.round(Number(row.average_score_percent ?? 0) * 10) / 10),
  }],
}))

// Average score by subject, coloured by which side of the pass mark it is on.
const subjectChart = computed(() => {
  const rows = reportSubjectPerformance.value

  return {
    labels: rows.map((subject) => subject.label),
    datasets: [{
      label: 'Average score',
      data: rows.map((subject) => Math.round(Number(subject.score ?? 0) * 10) / 10),
      colors: rows.map((subject) => (Number(subject.score ?? 0) >= PASSING_SCORE ? COLOR_PASSING : COLOR_BELOW)),
    }],
    // One row per subject, so the chart grows with the list.
    height: Math.max(180, rows.length * 44 + 50),
  }
})

/** First-to-last movement across the plotted sessions -- the number a teacher actually wants from a trend. */
const trendDelta = computed(() => {
  const points = analytics.value.trend
  if (points.length < 2) return null

  const first = Number(points[0].average_score_percent ?? 0)
  const last = Number(points[points.length - 1].average_score_percent ?? 0)

  return Math.round((last - first) * 10) / 10
})

const trendSummary = computed(() => {
  const points = analytics.value.trend
  if (points.length === 0) return ''

  const last = points[points.length - 1]
  const lastScore = Math.round(Number(last.average_score_percent ?? 0))

  if (trendDelta.value === null) {
    return `Latest session "${last.exam_title}" averaged ${lastScore}%.`
  }

  if (trendDelta.value > 0) {
    return `Up ${trendDelta.value} points since the first session — latest averaged ${lastScore}%.`
  }

  if (trendDelta.value < 0) {
    return `Down ${Math.abs(trendDelta.value)} points since the first session — latest averaged ${lastScore}%.`
  }

  return `Flat across ${points.length} sessions — latest averaged ${lastScore}%.`
})

const weakestTopics = computed(() => topicPerformance.value.slice(0, 5))

/* Only genuinely strong topics qualify, and never one already listed as weak
   -- with a short topic list the "best" entry can still be a failing one. */
const strongestTopics = computed(() => {
  const weakIds = new Set(weakestTopics.value.map((topic) => topic.topic_id))

  return [...topicPerformance.value]
    .filter((topic) => !weakIds.has(topic.topic_id) && Number(topic.score) >= PASSING_SCORE)
    .sort((left, right) => Number(right.score) - Number(left.score))
    .slice(0, 3)
})

function clampPercent(value) {
  return Math.max(0, Math.min(100, Number(value ?? 0)))
}

const selectedReportExam = computed(() => (
  reportExportExams.value.find((exam) => String(exam.id) === String(reportExportForm.exam_id)) ?? null
))

const selectedReportRoom = computed(() => (
  reportExportRoomOptions.value.find((room) => String(room.id) === String(reportExportForm.room_id)) ?? null
))

const selectedReportStudent = computed(() => (
  reportExportStudents.value.find((student) => String(student.id) === String(reportExportForm.student_id)) ?? null
))

// Under the Student field: why it is empty, or what picking one is for. The
// other downloads cover the whole room whether or not a student is picked.
const reportStudentHint = computed(() => {
  if (!reportExportForm.room_id) return 'Choose a room to list its students.'
  if (reportStudentsLoading.value) return 'Loading the room\'s students...'
  if (reportExportStudents.value.length === 0) return 'This room has no current students.'

  return 'Only needed for the one-student reports below.'
})

// The Exports tab's buttons, grouped by who they are about. The key is what
// exportReport() / sendReportEmail() take.
const EXPORT_GROUPS = [
  {
    key: 'class',
    title: 'For the Whole Class',
    info: 'Everyone in the chosen room on the chosen exam. Each student counts once, by their latest sitting.',
    options: [
      { key: 'xlsx', title: 'Class Results', format: 'Excel', icon: FileSpreadsheet, primary: true, description: 'Every student\'s score and right or wrong on each question, plus how the class did per question. Best for your records.' },
      { key: 'summary_pdf', title: 'Class Summary', format: 'PDF', icon: FileText, description: 'One or two printable pages: average, pass rate, score breakdown and the questions to review.' },
      { key: 'csv', title: 'Class Results', format: 'CSV', icon: Sheet, description: 'The same scores as plain data, for Google Sheets or another records system. Not formatted.' },
      { key: 'student_zip', title: 'Every Student\'s Report', format: 'ZIP', icon: FileArchive, description: 'A Student Report PDF for each student who has taken the exam, in one download.' },
      { key: 'student_email_bulk', title: 'Email Every Student Their Report', format: 'Email', icon: MailPlus, email: true, description: 'Sends each student their own Student Report PDF. Hidden results leave out the correct answers.' },
    ],
  },
  {
    key: 'student',
    title: 'For One Student',
    info: 'The student picked in the Student field above, from their latest sitting.',
    options: [
      { key: 'student_pdf', title: 'Student Report', format: 'PDF', icon: FileText, description: 'Their score, then each question with the answer they gave and the correct one.' },
      { key: 'student_email', title: 'Email This Student Their Report', format: 'Email', icon: Mail, email: true, description: 'Sends the same PDF to their email. Hidden results leave out the correct answers.' },
    ],
  },
  {
    key: 'key',
    title: 'Answer Key',
    info: 'The same for every room the exam is in.',
    options: [
      { key: 'answer_key_pdf', title: 'Answer Key', format: 'PDF', icon: FileKey, description: 'Every question with its choices and the correct answer marked. For you, not for students.' },
    ],
  },
]
</script>

<style scoped src="./reportsView.css"></style>
