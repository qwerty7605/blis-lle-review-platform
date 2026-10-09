# BLIS System Agent Quick Guide

This file is for coding agents. Keep it short, execution-focused, and updated when behavior changes.

## Project Snapshot

- Root app: Laravel 12 API + server-rendered entry.
- Frontend app: Vue 3 SPA in `frontend/` (Pinia + Vue Router + Axios).
- Auth: Laravel Sanctum bearer tokens (SPA stores token in `localStorage`).
- Database: MySQL in Docker.

## Primary Entry Points

- API routes: `routes/api.php`
- Web route: `routes/web.php`
- Role model/constants: `app/Models/User.php`
- Room model: `app/Models/Room.php`
- Exam model: `app/Models/Exam.php`
- Audit model: `app/Models/AuditLog.php`
- Settings model: `app/Models/SystemSetting.php`
- DB schema: `database/migrations/*`
- Seeder accounts: `database/seeders/DatabaseSeeder.php`
- Frontend auth store: `frontend/src/stores/auth.js`
- Frontend router guard: `frontend/src/router/index.js`
- Role-based sidebar/nav: `frontend/src/views/Dashboard.vue`
- Staff student directory view: `frontend/src/views/dashboard/staff/StudentsView.vue`
- Docker stack: `docker-compose.yml`, `dockerfile`, `nginx/default.conf`

## Role and Access Rules

- Canonical roles:
  - `student`
  - `staff_master_examiner` (teacher/faculty-equivalent)
  - `admin`
- Public registration always creates `student` (`POST /api/auth/register`).
- Management behavior checks include:
  - Backend: `admin`, `staff_master_examiner`, and legacy `faculty` string.
  - Frontend: same set for management sidebar.
- Admin-only modules:
  - User management (`/api/admin/users`)
  - Audit logs (`/api/admin/audit-logs`)
  - Global room oversight (`/api/rooms` for admin, or `/api/admin/rooms`)

## Current Seed Users (Local Dev)

Defined in `database/seeders/DatabaseSeeder.php`:

- Current students (120 total): `student@example.com`, `student1@example.com` ... `student119@example.com`
  - Password: `pass`
  - Role: `student`
  - Year levels are grouped by section:
    - `student@example.com` to `student29@example.com` = `1st Year`
    - `student30@example.com` to `student59@example.com` = `2nd Year`
    - `student60@example.com` to `student89@example.com` = `3rd Year`
    - `student90@example.com` to `student119@example.com` = `4th Year`
  - Student IDs: `2300001` ... `2301409`
- Archived graduate samples (4 total): `graduate1@example.com` ... `graduate4@example.com`
  - Password: `pass`
  - Role: `student`
  - Year level: `4`
  - These accounts are archived for history/demo flows.
- Teachers (3 total): `teacher@example.com`, `teacher1@example.com`, `teacher2@example.com`
  - Password: `pass`
  - Role: `staff_master_examiner`
- Admin: `admin@example.com` / `pass` / `admin`

## Seeded Demo Data (Local Dev)

- `migrate:fresh --seed` now creates demo question banks, rooms, exams, memberships, audit logs, and exam attempts.
- Seeded demo question banks all clone the single template at `tmp/question-set-tests/INDEXING-AND-ABSTRACTING.docx`; only the bank titles/subjects differ for sample data.
- Demo rooms now represent single year/section rosters with `30` current students each. Archived graduate records remain in the data model for global student-history views, but archived student records are no longer shown inside room rosters.
- `teacher@example.com` gets populated report data:
  - Rooms: `BLIS 1A` (`B1A26`), `BLIS 2A` (`B2A26`)
  - Exams: `Cataloging Midterm Mock`, `Reference Services Mock`, `Library Management Sprint`
- `teacher1@example.com` gets `BLIS 3A` (`B3A26`) with `Indexing Drill Set`.
- `teacher2@example.com` gets `BLIS 4A` (`B4A26`) with `Information Technology Quiz`.
- `student@example.com` is enrolled in `BLIS 1A` and has both submitted and in-progress attempts, so student dashboard analytics are non-empty immediately after reseeding.

## Phone and Tablet Layout

- Breakpoints live in `frontend/src/views/dashboard/composables/useViewport.js`: `useCompactLayout()` (≤900px: sticky app bar + slide-over nav drawer in `DashboardLayout.vue`) and `usePhoneLayout()` (≤640px: rows instead of cards/tables).
- A page moves its main action into the app bar with `<Teleport to="#layout-topbar-actions" defer :disabled="!isCompact">`; its duplicate title header carries `.room-page-head.is-page-title` so it hides at ≤900px.
- Phone filters and per-row actions use `frontend/src/components/BottomSheet.vue`; rows use `.directory-row` / `.directory-row-details` from the "Phone layouts" section at the end of `dashboard.css`.
- Touch screens (`pointer: coarse`) get 44px controls via `--control-height` and 16px field text (iOS zooms into smaller fields).
- `GET /api/admin/users` returns rows in directory order (current students by year, archived by year, staff, admins; then name) plus `group_totals` for the headings; phones grow the list with `per_page` instead of paging. `GET /api/admin/audit-logs` pages with `page`/`per_page` (legacy `limit` still works) and returns `meta`.

## Visual Design

- One look for the whole app, set by the signed-out pages: Poppins (`--font-display`), the navy panel (`--panel-navy`), large radii (`--radius-sm/md/lg` = 10/16/22px), white cards on the cream page, and navy buttons with gold text. Tokens live in `frontend/src/style.css`.
- Signed-out pages share `frontend/src/views/auth/AuthLayout.vue` + `auth.css`; logos are in `frontend/src/assets/`.
- Dashboard pages get the look from the "BRAND LAYER" block in `dashboard.css` (shared primitives: `.surface-card`, `.primary-btn`, `.text-input`, `.pill`, tables, modals). Restyle there before adding per-page CSS.
- Charts: `frontend/src/components/charts/JsChart.vue` wraps Chart.js (bar and line, pass-mark line, value labels, tooltips) and is used on the teacher dashboard. Below-passing is amber `#c98a1e` and passing is indigo `#3949ab`, a pair checked for colour-blind separation; do not swap in red/green. `ConfidenceGauge.vue` is a hand-built SVG gauge.
- Any `display: grid` list that can hold a long one-line string needs `grid-template-columns: minmax(0, 1fr)`, or the row widens the page on phones.

## Docker/Runtime Notes

- Service ports:
  - App (nginx): `http://localhost:8080`
  - phpMyAdmin: `http://localhost:8081`
- The `queue` service runs Laravel background jobs, including AI item mapping. Bank save/import now queues classification instead of waiting on OpenAI inline.
- MySQL container is intentionally named `lnu_lle_db`.
- MySQL volume name is `blis_system_mysql_data`.

## Common Commands

- Start stack: `docker compose up -d`
- Start or restart the queue worker after compose changes: `docker compose up -d queue`
- Stop stack: `docker compose down`
- Reset DB schema + reseed: `docker compose exec -T php php artisan migrate:fresh --seed`
- Seed only: `docker compose exec -T php php artisan db:seed`
- Run migrations: `docker compose exec -T php php artisan migrate`
- Watch queued job logs: `docker compose logs -f queue`
- Install PHP deps in container: `docker compose exec -T php composer install`
- List users/roles quickly:
  - `docker compose exec -T php php artisan tinker --execute="dump(App\\Models\\User::select('id','name','email','student_id','role')->get()->toArray());"`

## Known Fast Fixes

- Error: `vendor/autoload.php` missing
  - Run `docker compose exec -T php composer install`
- 500 with storage/log permission denied
  - Run:
    - `docker compose exec -T php sh -lc 'mkdir -p storage/logs bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwx storage bootstrap/cache'`
- Container name/port conflicts with old projects
  - Check `docker ps` and remove conflicting containers before `up -d`.
- New backend modules (API-backed):
  - Staff student directory: `/api/students/directory`
  - Student profile (staff and admin only): `GET /api/students/{student}/analytics` returns `student` plus the same payload as `/api/student/analytics` (both built by `$buildStudentAnalytics` in `routes/api/student.php`). Page: `frontend/src/views/dashboard/staff/StudentProfileView.vue` at `/dashboard/students/:studentId`, opened by clicking a name in the Students directory; it is a hidden nav module (`studentProfile`).
  - Exams: `/api/exams` (CRUD + room assignment + optional `scheduled_at` datetime)
    - Exams now support optional `question_bank_id`.
    - Room assignments can be archived/restored without deleting the historical exam-session link.
  - Student exam attempts:
    - `POST /api/student/exams/{exam}/start` (room-scoped start/resume)
    - `GET /api/student/exam-attempts/{attempt}`
    - `PATCH /api/student/exam-attempts/{attempt}/answers`
    - `POST /api/student/exam-attempts/{attempt}/submit`
  - Reports: `/api/reports/overview`. In the UI this page is the teacher's **Dashboard**: first in the staff sidebar and the page staff land on after signing in. The nav key, route (`/dashboard/reports`, `dashboard-reports`) and `ReportsView.vue` keep the name "reports".
  - System settings: `/api/settings/system` (admin can update)
  - End of school year (admin only): `GET /api/admin/year-end` proposes what closing the year would do (4th year and above graduate, everyone else moves up one level) and `POST /api/admin/year-end` applies only the students the admin sends back, each with the year level they were shown; a record that changed since is skipped. Logic in `app/Services/SchoolYearEndService.php`. Page: `frontend/src/views/dashboard/admin/YearEndView.vue` at `/dashboard/year-end`, opened from the Users page; it is a hidden nav module (`yearEnd`). Nothing about it is scheduled or automatic.
  - Student room details (`GET /api/rooms/{room}`) expose active rosters for room pages and current/archived exams separately; attempt availability is enforced by `scheduled_at`, archive state, and question set presence.

## Agent Working Style for This Repo

- Default to direct implementation.
- Avoid broad rediscovery unless files changed or task is unclear.
- For role/sidebar issues, inspect `routes/api.php` and `frontend/src/views/Dashboard.vue` first.
- Do not revert unrelated local changes.
