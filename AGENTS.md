# BLIS System Agent Quick Guide

This file is for coding agents. Keep it short, execution-focused, and updated when behavior changes.

## Project Snapshot

- Root app: Laravel 12 API + server-rendered entry.
- Frontend app: Vue 3 SPA in `frontend/` (Pinia + Vue Router + Axios).
- Auth: a Laravel Sanctum token kept in an HttpOnly, SameSite=strict cookie (`auth_token`, 7 days, set by `AuthTokenCookieService`). The SPA holds no token of its own; `frontend/src/api/http.js` sends the cookie with `withCredentials`.
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
- A list that is a table on desktop switches on the page's measured width, not the window's (the sidebar takes 264px): `useElementWidth()` in `useViewport.js`. Exams falls back to `.directory-row` rows under 860px, Library to cards under 940px, Licensure to rows under 940px. Students has three steps: rows under 920px, an eight-column table up to 1599px (email, batch, status and last activity folded under their neighbours, each still sortable from the heading it folded into), all twelve columns from 1600px. Users: rows under 950px, icon-only row buttons up to 1139px. Do not give a table a `min-width` and let its action column scroll off-screen (the shared `.directory-table` rule sets 1080px; a table that needs less resets it).
- A view whose layout decides what it fetches (Users: rows load more, the table pages) passes `estimatePageWidth()` to `useElementWidth()` so the first request already matches the layout.
- Dialogs: on phones every `.modal-card` is a bottom sheet (`margin: auto 0 0`, padding from `--modal-pad`); the exam form is full screen. From 641px a dialog is never taller than the screen: it scrolls inside and its `.modal-actions` stay at its bottom edge. `DashboardLayout.vue` locks page scroll while any `.modal-backdrop` or `.exam-attempt-backdrop` is in `<body>`, and drops a click on a backdrop whose press began inside the dialog (a text selection dragged out used to close the dialog). The exam form ignores a click outside it once anything in it has changed (`dismissExamModal`). Dialog-specific layout goes in the owning view's own `<style scoped>`, because the single-class `.modal-card` rules late in `dashboard.css` override earlier ones.
- Never put `min-height: 0` on rows inside a list with a `max-height`: the rows collapse to their padding once they no longer fit (this was the overlapping room list in the exam form).
- The exam a student is taking (`.exam-attempt-*`, in the "EXAM OVERLAY REFRESH" block of `dashboard.css`): its header and buttons are pinned, so their height comes out of the question. Up to 640px the header is one row (64px) and the buttons two (113px); from 641px one row each. Measure both before adding anything to either.
- A row that scrolls sideways with its scrollbar hidden (tabs, filter chips) takes `v-scroll-hint` (`frontend/src/utils/scrollHint.js`), which fades the edge that has more beyond it.
- The Rooms pages and the Review Bot pin themselves to the screen height only where that leaves their lists room: Rooms from 1201px wide and 760px tall, Review Bot from 1001px wide. Below that the page scrolls. A 1366x768 laptop shows about 640px of page height, so check fill-screen layouts at 1366x640, not 1366x768.
- No text under 11px. The trend chart (`ScoreTrendChart.vue`) draws at its real width so its labels are not scaled down with it, and `JsChart.vue` wraps the category names of a sideways bar chart.
- `GET /api/admin/users` returns rows in directory order (current students by year, archived by year, staff, admins; then name) plus `group_totals` for the headings; phones grow the list with `per_page` instead of paging. `GET /api/admin/audit-logs` pages with `page`/`per_page` (legacy `limit` still works) and returns `meta`.

## Staying Current Without a Reload

Nobody should have to reload a page to see what changed. Four pieces keep that true; a new page or feature has to use them rather than work around them.

- **Time** (`frontend/src/utils/clock.js`): anything on screen that depends on the time (has an exam opened, has it closed) reads `now()`, never `Date.now()`, and names the moment its answer changes with `beatAt()`. `now()` is reactive and follows the server's clock (learned from the `Date` header in `api/http.js`), so Take Exam turns on at the start time by itself, even on a phone whose own clock is wrong.
- **Data** (`frontend/src/utils/liveRefresh.js`): every page module calls `useLiveRefresh(quietRefresh, { every, when })`. All of them refresh when the reader comes back to the tab; the pages people wait on also refresh on a timer: student and staff Rooms and student Home every 60s, Audit every 60s, the announcement banner every 5 minutes. A quiet refresh sets no loading flag, clears no message, keeps the selection, and leaves the data alone if it fails: that is what `{ silent: true }` on a load function means. `when` keeps it out from under an open dialog and out of an exam.
- **Cost**: the student Rooms timer is one `GET /api/rooms/{id}` a minute per student, spread at random over the minute. That is the page a whole class sits on before an exam; do not shorten it or add requests to it without redoing that sum.
- **Updates** (`frontend/src/utils/appVersion.js`): a tab opened before a deploy runs old code and would ask for page scripts the deploy removed. The site's `index.html` is compared with the running one every 5 minutes and on return to the tab; once it differs, the next move to another page is a full load. A page script that fails to load anyway triggers the same at once. Not active under `npm run dev`.
- **Sessions** (`frontend/src/api/http.js`, `main.js`): a 401 from anything outside `/auth/`, or the 403 "Account is disabled.", sends the reader to `/login?ended=...` through the router, where the page says why.
- A save or delete reloads what it changed itself (every module already does); `useLiveRefresh` is for changes made by someone else.

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
- 429 `Too Many Attempts`
  - Limits live in `AppServiceProvider::configureRateLimiting()`. Per-IP tiers are sized for a campus behind one address (about 230 students): 120 sign-ins and 600 signed-out requests a minute, 30 registrations a minute and 150 an hour, 30 resend/reset emails an hour. The tight tiers are per account (5 sign-in tries a minute) and per mailbox (3 emails an hour). Signed-in users are counted per user (120 a minute). Covered by `tests/Feature/CampusRateLimitTest.php`.
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
  - Reports: `/api/reports/overview`. In the UI this page is the teacher's **Dashboard**: first in the staff sidebar and the page staff land on after signing in. The nav key, route (`/dashboard/reports`, `dashboard-reports`) and `ReportsView.vue` keep the name "reports". `metrics.managed_rooms` counts the rooms the Rooms page lists (not archived); the rest of the report still reads archived rooms' results.
  - System settings: `/api/settings/system` (admin can update)
  - End of school year (admin only): `GET /api/admin/year-end` proposes what closing the year would do (4th year and above graduate, everyone else moves up one level) and `POST /api/admin/year-end` applies only the students the admin sends back, each with the year level they were shown; a record that changed since is skipped. Logic in `app/Services/SchoolYearEndService.php`. Page: `frontend/src/views/dashboard/admin/YearEndView.vue` at `/dashboard/year-end`, opened from the Users page; it is a hidden nav module (`yearEnd`). Nothing about it is scheduled or automatic.
  - Student room details (`GET /api/rooms/{room}`) expose active rosters for room pages and current/archived exams separately; attempt availability is enforced by `scheduled_at`, archive state, and question set presence.

## Agent Working Style for This Repo

- Default to direct implementation.
- Avoid broad rediscovery unless files changed or task is unclear.
- For role/sidebar issues, inspect `routes/api.php` and `frontend/src/views/Dashboard.vue` first.
- Do not revert unrelated local changes.
