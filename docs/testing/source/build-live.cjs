// Builds the live-site edition of the test cases: black box only, run against the real website.
//
//   node build-live.cjs <output folder>
//
// Needs live-accounts.json beside this file (see live-accounts.example.json): the site address
// and the three test accounts. That file and the PDF this makes are not kept in git, because
// the PDF prints the accounts' passwords.
const fs = require('fs')
const path = require('path')

const accountsFile = path.join(__dirname, 'live-accounts.json')
if (!fs.existsSync(accountsFile)) {
  throw new Error('live-accounts.json is missing. Copy live-accounts.example.json to it and fill in the test accounts.')
}
const site = JSON.parse(fs.readFileSync(accountsFile, 'utf8'))

const TOKENS = {
  '%ADMIN_EMAIL%': site.admin.email,
  '%ADMIN_PASSWORD%': site.admin.password,
  '%TEACHER_EMAIL%': site.teacher.email,
  '%TEACHER_PASSWORD%': site.teacher.password,
  '%STUDENT_EMAIL%': site.student.email,
  '%STUDENT_PASSWORD%': site.student.password,
}

const cases = require('./cases-live.cjs')
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

// Number every case and remember each key's ID.
const ids = {}
let total = 0
for (const item of cases) {
  if (item.section) continue
  total += 1
  item.id = `WEB_${String(total).padStart(3, '0')}`
  if (ids[item.k]) throw new Error(`duplicate key ${item.k}`)
  ids[item.k] = item.id
}

const fill = (text) => String(text)
  .replace(/@([a-z0-9-]+)@/g, (_, key) => {
    if (!ids[key]) throw new Error(`unknown reference @${key}@`)
    return ids[key]
  })
  .replace(/%[A-Z_]+%/g, (token) => {
    if (!(token in TOKENS)) throw new Error(`unknown token ${token}`)
    return TOKENS[token]
  })

const dataCell = (text) => fill(text).split('\n').map((line) => `<span>${esc(line)}</span>`).join('')
const stepsCell = (steps) => `<ol>${steps.map((step) => `<li>${esc(fill(step))}</li>`).join('')}</ol>`

const rows = cases.map((item) => {
  if (item.section) return `<tr class="section"><td colspan="8">${esc(item.section)}</td></tr>`
  return `<tr>
    <td class="id">${item.id}</td>
    <td>${esc(fill(item.o))}</td>
    <td>${esc(fill(item.p))}</td>
    <td class="data">${dataCell(item.d)}</td>
    <td class="steps">${stepsCell(item.s)}</td>
    <td>${esc(fill(item.e))}</td>
    <td></td>
    <td class="remarks"></td>
  </tr>`
}).join('\n')

const lastId = `WEB_${String(total).padStart(3, '0')}`

const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>LNU LLE Platform (BLIS) Test Cases, live site</title>
<style>
  @page { size: A4 landscape; margin: 9mm 8mm 12mm 8mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 9pt; line-height: 1.32; color: #111; }
  .title { background: #0b4f8f; color: #fff; text-align: center; padding: 9px 8px 8px; }
  .title h1 { margin: 0; font-size: 17pt; letter-spacing: 0.5px; }
  .title p { margin: 2px 0 0; font-size: 11pt; font-weight: bold; }
  .meta { display: flex; gap: 14px; border: 1px solid #0b4f8f; border-top: none; padding: 6px 10px; font-size: 9.5pt; }
  .meta div { flex: 1; }
  .line { display: inline-block; border-bottom: 1px solid #333; width: 46mm; height: 11pt; vertical-align: bottom; }
  h2 { font-size: 11.5pt; margin: 11px 0 4px; color: #0b4f8f; }
  .intro { columns: 2; column-gap: 9mm; font-size: 9.5pt; }
  .intro .block { break-inside: avoid; margin-bottom: 7px; }
  .intro h3 { font-size: 10pt; margin: 0 0 2px; }
  .intro p, .intro ul { margin: 0 0 3px; }
  .intro ul { padding-left: 15px; }
  .intro table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 2px; }
  .intro td, .intro th { border: 1px solid #8fa9c4; padding: 2.5px 5px; text-align: left; vertical-align: top; }
  .intro th { background: #dbe9f6; }
  .intro .warning { border: 1.5px solid #b3261e; background: #fdecea; padding: 5px 8px; }
  .intro .warning h3 { color: #b3261e; }
  code { font-family: Consolas, "Courier New", monospace; font-size: 8.5pt; background: #f1f4f8; border-radius: 2px; padding: 0 2px; overflow-wrap: anywhere; }
  .part { break-before: page; }
  .part-title { background: #0b4f8f; color: #fff; font-weight: bold; font-size: 12.5pt; padding: 6px 9px; }
  .part-title span { float: right; font-size: 9.5pt; font-weight: normal; padding-top: 2px; }
  .part-note { margin: 4px 1px 5px; font-size: 9.5pt; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  thead { display: table-header-group; }
  th { background: #5b9bd5; color: #000; font-size: 9.5pt; padding: 4px 3px; border: 1px solid #1f3f63; }
  td { border: 1px solid #1f3f63; padding: 3.5px 4.5px; vertical-align: middle; overflow-wrap: anywhere; }
  tr { break-inside: avoid; }
  tr.section td { background: #6fa8dc; font-weight: bold; font-size: 10.5pt; padding: 4.5px 7px; break-after: avoid; }
  td.id { text-align: center; font-weight: bold; font-size: 8.5pt; white-space: nowrap; }
  td.remarks { background: #cfe2f3; }
  td.data span { display: block; }
  ol { margin: 0; padding-left: 15px; }
  .end { margin-top: 10px; font-size: 9.5pt; break-inside: avoid; }
  .end table { width: 90mm; }
  .end td { height: 8mm; }
</style></head>
<body>
  <div class="title"><h1>LNU LLE PLATFORM (BLIS)</h1><p>Test Case: live website</p></div>
  <div class="meta">
    <div><b>Tester:</b> <span class="line"></span></div>
    <div><b>Date tested:</b> <span class="line"></span></div>
    <div><b>Total test cases:</b> ${total} (black box)</div>
  </div>

  <h2>Before you start</h2>
  <div class="intro">
    <div class="block"><h3>How to use this document</h3>
      <ul>
        <li>These tests are run on the real website: <code>${esc(site.address)}</code>. "The site address" in the steps means this address.</li>
        <li>Run the tests from top to bottom. Later tests use the accounts, rooms and exams made by earlier ones. A Test ID inside a Precondition (for example ${ids['room-create']}) means that test must be done first.</li>
        <li>Write what really happened under <b>Actual Results</b> and write <b>PASSED</b> or <b>FAILED</b> under <b>Remarks</b>.</li>
        <li>Text inside "quotation marks" is the exact wording the system should show.</li>
        <li>When a test needs two accounts at the same time, open the second one in a private (incognito) window or in another browser.</li>
      </ul>
    </div>
    <div class="block warning"><h3>This is the real website. Take care.</h3>
      <ul>
        <li>Use only the accounts on this page and the ones you create during the tests. Do not edit, disable, archive or delete any other account, room or exam.</li>
        <li>Do not click <b>Review and confirm</b> on the End of School Year page. Closing the school year moves every real student up a year.</li>
        <li>Do not turn on <b>Enable maintenance mode</b> and do not untick <b>Allow public student registration</b> in Settings. Both affect every real user at once.</li>
        <li>If you put an announcement on the site for a test, clear it again in the test that follows.</li>
      </ul>
    </div>
    <div class="block"><h3>Test accounts on the website</h3>
      <table>
        <tr><th>Called in the tests</th><th>Sign in with</th><th>Password</th></tr>
        <tr><td>Admin</td><td>${esc(site.admin.email)}</td><td>${esc(site.admin.password)}</td></tr>
        <tr><td>Teacher A</td><td>${esc(site.teacher.email)}</td><td>${esc(site.teacher.password)}</td></tr>
        <tr><td>Student</td><td>${esc(site.student.email)}</td><td>${esc(site.student.password)}</td></tr>
      </table>
    </div>
    <div class="block"><h3>Accounts you create during the tests</h3>
      <table>
        <tr><th>Called in the tests</th><th>Made in</th><th>Sign in with</th><th>Password</th></tr>
        <tr><td>Student One (Quality Tester One, 1st year)</td><td>${ids['users-create-s1']}</td><td>9900001</td><td>Test@1234</td></tr>
        <tr><td>Student Two (Quality Tester Two, 1st year)</td><td>${ids['users-create-s23']}</td><td>9900002</td><td>Test@1234</td></tr>
        <tr><td>Student Three (Quality Tester Three, 2nd year)</td><td>${ids['users-create-s23']}</td><td>9900003</td><td>Test@1234</td></tr>
        <tr><td>Teacher B (Quality Teacher)</td><td>${ids['users-create-teacher']}</td><td>qa.teacher@example.com</td><td>Test@1234</td></tr>
        <tr><td>Registered student (Quality Registrant)</td><td>${ids['reg-valid']}</td><td>9900010 or YOUR-LNU-EMAIL</td><td>Test@1234, later New@12345</td></tr>
      </table>
      <p>If the website says one of these Student IDs or emails is already taken, tell the developer before going on.</p>
    </div>
    <div class="block"><h3>What you need</h3>
      <ul>
        <li><b>An LNU email you can open</b> that has no account on the website yet. It is written as <b>YOUR-LNU-EMAIL</b> in the tests. The website sends real emails to it: the verification link, up to three resends, and the password reset links.</li>
        <li><b>The test file</b> <code>Sample-Cataloging-10-items.docx</code>, from the developer. It holds 10 multiple-choice questions on Cataloging and Classification, with the correct choice of each item typed in red.</li>
        <li><b>Five more copies of the test file</b>, for the Estimated Outcome tests. Copy it five times and name the copies <code>QA-Reference.docx</code>, <code>QA-Management.docx</code>, <code>QA-Indexing.docx</code>, <code>QA-Selection.docx</code> and <code>QA-IT.docx</code>. Nothing inside them is changed.</li>
        <li><b>Microsoft Word</b>, for the two tests that ask for an edited copy of that file.</li>
        <li><b>A desktop browser</b> (Chrome). For the phone and tablet tests use a phone, or press F12 and click the phone icon.</li>
      </ul>
      <table>
        <tr><th style="width:24mm;white-space:nowrap">Item</th><td>1</td><td>2</td><td>3</td><td>4</td><td>5</td><td>6</td><td>7</td><td>8</td><td>9</td><td>10</td></tr>
        <tr><th style="white-space:nowrap">Answer key</th><td>B</td><td>C</td><td>A</td><td>D</td><td>B</td><td>A</td><td>C</td><td>D</td><td>B</td><td>A</td></tr>
      </table>
      <p>The passing score everywhere in the system is 75%.</p>
    </div>
    <div class="block"><h3>Which version of the website</h3>
      <p>This edition describes the website after the update of 14 October 2026: the Core Subject field when uploading questions, editing each question's topic and subtopic from the Edit Question Set window, the "Confirmed" tag status, School Year and Semester on an exam, Archive in the Delete Exam window, the Student list in the Export Center, and the new Estimated Outcome (locked until all 6 subject exams of a semester are done, then an early warning or a board readiness prediction) with its model versions on the Licensure page. If the tests for these fail, first ask the developer whether that update is on the website yet.</p>
    </div>
    <div class="block"><h3>What is not in this edition</h3>
      <p>The tests that cannot or should not be run on the real website are left out: the white box tests (they need the server and its database), maintenance mode, closing registration, closing the school year, and emailing reports to test addresses that do not exist. They are in the full edition, which is run on a local copy.</p>
    </div>
    <div class="block"><h3>After testing</h3>
      <p>The tests leave data on the website: the accounts in the second table, the room QA Room 1A with its exams and results, the six question banks and the Review Bot questions of Teacher A, and one licensure test entry if it was not removed. Give this list to the developer so it can be taken off the website.</p>
    </div>
  </div>

<section class="part">
  <div class="part-title">BLACK BOX TESTING ON THE LIVE WEBSITE <span>${total} test cases, WEB_001 to ${lastId}</span></div>
  <p class="part-note">Test the website the way its users see it, through the pages only. Run the modules in this order.</p>
  <table>
    <colgroup><col style="width:17mm"><col style="width:44mm"><col style="width:30mm"><col style="width:42mm"><col style="width:46mm"><col style="width:48mm"><col style="width:35mm"><col style="width:19mm"></colgroup>
    <thead><tr><th>Test ID</th><th>Overview</th><th>Precondition</th><th>Test Data</th><th>Test Steps</th><th>Expected Results</th><th>Actual Results</th><th>Remarks</th></tr></thead>
    <tbody>
${rows}
    </tbody>
  </table>
</section>
  <div class="end">
    <table>
      <tr><th style="width:40mm">Summary</th><th>Count</th></tr>
      <tr><td>Test cases</td><td>${total}</td></tr>
      <tr><td>Passed</td><td></td></tr>
      <tr><td>Failed</td><td></td></tr>
      <tr><td>Not run</td><td></td></tr>
    </table>
  </div>
</body></html>`

const outDir = process.argv[2] || __dirname
const htmlFile = process.argv[3] || path.join(__dirname, 'BLIS-Test-Cases-Live-Site.html')
fs.writeFileSync(htmlFile, html)
console.log(`cases: ${total}`)

;(async () => {
  const { chromium } = require(process.env.PW_PATH)
  const browser = await chromium.launch({ executablePath: process.env.PW_EXE })
  const page = await browser.newPage()
  await page.setContent(html, { waitUntil: 'load' })
  const target = path.join(outDir, 'BLIS-Test-Cases-Live-Site.pdf')
  await page.pdf({
    path: target,
    format: 'A4',
    landscape: true,
    printBackground: true,
    preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate: '<div style="width:100%;font-family:Arial;font-size:7.5pt;color:#444;padding:0 9mm;display:flex;justify-content:space-between;"><span>LNU LLE Platform (BLIS) Test Case: live website</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>',
  })
  const faults = await page.evaluate(() => [...document.querySelectorAll('.part tbody td')].filter((td) => td.scrollWidth > td.clientWidth + 1).map((td) => td.parentElement.querySelector('.id')?.textContent))
  console.log('cells wider than their column:', faults.length, faults.slice(0, 8))
  await browser.close()
  console.log('saved', target, Math.round(fs.statSync(target).size / 1024) + ' KB')
})().catch((e) => { console.error('CRASH', e); process.exit(1) })
