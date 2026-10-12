// Builds the test case document: numbers the cases, resolves cross-references, writes the HTML, prints the PDF.
const fs = require('fs')
const path = require('path')

const parts = [
  { code: 'BB', title: 'PART A: BLACK BOX TESTING', note: 'Test the system the way its users see it, through the pages only. Run the modules in this order: later tests use the accounts, rooms and exams made by earlier ones.', cases: [...require('./cases-bb1.cjs'), ...require('./cases-bb2.cjs')] },
  { code: 'WB', title: 'PART B: WHITE BOX TESTING', note: 'Each test names the code it exercises and checks what happens inside: the server reply, the database rows, the cookie. Run Part B after Part A, on the local copy. Tools: a terminal in the project folder, the browser DevTools (F12) and phpMyAdmin.', cases: require('./cases-wb.cjs') },
]

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

// Number every case and remember each key's ID.
const ids = {}
for (const part of parts) {
  let n = 0
  for (const item of part.cases) {
    if (item.section) continue
    n += 1
    item.id = `${part.code}_${String(n).padStart(3, '0')}`
    if (ids[item.k]) throw new Error(`duplicate key ${item.k}`)
    ids[item.k] = item.id
  }
  part.count = n
}

const ref = (text) => String(text).replace(/@([a-z0-9-]+)@/g, (_, key) => {
  if (!ids[key]) throw new Error(`unknown reference @${key}@`)
  return ids[key]
})

const isCode = (line) => /^(api\(|Promise\.|for \(|document\.|localStorage|docker |cd |npx )/.test(line.trim())
const dataCell = (text) => ref(text).split('\n').map((line) => (isCode(line) ? `<code>${esc(line)}</code>` : `<span>${esc(line)}</span>`)).join('')
const stepsCell = (steps) => `<ol>${steps.map((step) => `<li>${esc(ref(step))}</li>`).join('')}</ol>`

const rows = (part) => part.cases.map((item) => {
  if (item.section) return `<tr class="section"><td colspan="8">${esc(item.section)}</td></tr>`
  return `<tr>
    <td class="id">${item.id}</td>
    <td>${esc(ref(item.o))}</td>
    <td>${esc(ref(item.p))}</td>
    <td class="data">${dataCell(item.d)}</td>
    <td class="steps">${stepsCell(item.s)}</td>
    <td>${esc(ref(item.e))}</td>
    <td></td>
    <td class="remarks"></td>
  </tr>`
}).join('\n')

const table = (part) => `
<section class="part">
  <div class="part-title">${esc(part.title)} <span>${part.count} test cases, ${part.code}_001 to ${part.code}_${String(part.count).padStart(3, '0')}</span></div>
  <p class="part-note">${esc(part.note)}</p>
  <table>
    <colgroup><col style="width:16mm"><col style="width:44mm"><col style="width:30mm"><col style="width:42mm"><col style="width:46mm"><col style="width:48mm"><col style="width:36mm"><col style="width:19mm"></colgroup>
    <thead><tr><th>Test ID</th><th>Overview</th><th>Precondition</th><th>Test Data</th><th>Test Steps</th><th>Expected Results</th><th>Actual Results</th><th>Remarks</th></tr></thead>
    <tbody>
${rows(part)}
    </tbody>
  </table>
</section>`

const total = parts.reduce((sum, part) => sum + part.count, 0)

const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>LNU LLE Platform (BLIS) Test Cases</title>
<style>
  @page { size: A4 landscape; margin: 9mm 8mm 12mm 8mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 9pt; line-height: 1.32; color: #111; }
  .title { background: #0b4f8f; color: #fff; text-align: center; padding: 9px 8px 8px; }
  .title h1 { margin: 0; font-size: 17pt; letter-spacing: 0.5px; }
  .title p { margin: 2px 0 0; font-size: 11pt; font-weight: bold; }
  .meta { display: flex; gap: 14px; border: 1px solid #0b4f8f; border-top: none; padding: 6px 10px; font-size: 9.5pt; }
  .meta div { flex: 1; }
  .meta b { display: inline-block; min-width: 0; }
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
  code { font-family: Consolas, "Courier New", monospace; font-size: 8pt; background: #f1f4f8; border-radius: 2px; padding: 0 2px; overflow-wrap: anywhere; word-break: break-word; }
  .helper { display: block; padding: 4px 6px; margin-top: 2px; border: 1px solid #c7d3e0; white-space: pre-wrap; }
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
  td.data span, td.data code { display: block; }
  td.data code { margin: 1px 0; }
  ol { margin: 0; padding-left: 15px; }
  .end { margin-top: 10px; font-size: 9.5pt; break-inside: avoid; }
  .end table { width: 120mm; }
  .end td { height: 8mm; }
</style></head>
<body>
  <div class="title"><h1>LNU LLE PLATFORM (BLIS)</h1><p>Test Case</p></div>
  <div class="meta">
    <div><b>Tester:</b> <span class="line"></span></div>
    <div><b>Date tested:</b> <span class="line"></span></div>
    <div><b>Total test cases:</b> ${total} (${parts[0].count} black box, ${parts[1].count} white box)</div>
  </div>

  <h2>Before you start</h2>
  <div class="intro">
    <div class="block"><h3>How to use this document</h3>
      <ul>
        <li>Run the tests from top to bottom. Later tests use the accounts, rooms and exams made by earlier ones. A Test ID inside a Precondition (for example ${ids['room-create']}) means that test must be done first.</li>
        <li>Write what really happened under <b>Actual Results</b> and write <b>PASSED</b> or <b>FAILED</b> under <b>Remarks</b>.</li>
        <li>Text inside "quotation marks" is the exact wording the system should show.</li>
        <li>When a test needs two accounts at the same time, open the second one in a private (incognito) window or in another browser.</li>
      </ul>
    </div>
    <div class="block"><h3>Where to test</h3>
      <p>System (local copy): <code>http://localhost:8080</code><br>Database viewer (phpMyAdmin), used in Part B: <code>http://localhost:8081</code></p>
      <p>"The site address" in the steps means the first address above. If you test on another copy of the system, ask the developer for its address and accounts.</p>
    </div>
    <div class="block"><h3>Accounts that already exist</h3>
      <table>
        <tr><th>Called in the tests</th><th>Sign in with</th><th>Password</th></tr>
        <tr><td>Admin</td><td>admin@example.com</td><td>pass</td></tr>
        <tr><td>Teacher A (name: Teacher User)</td><td>teacher@example.com</td><td>pass</td></tr>
        <tr><td>Teacher B</td><td>teacher1@example.com</td><td>pass</td></tr>
        <tr><td>Student (old account)</td><td>student@example.com or Student ID 2300001</td><td>pass</td></tr>
      </table>
    </div>
    <div class="block"><h3>Accounts you create during the tests</h3>
      <table>
        <tr><th>Called in the tests</th><th>Made in</th><th>Sign in with</th><th>Password</th></tr>
        <tr><td>Student One (Quality Tester One, 1st year)</td><td>${ids['users-create-s1']}</td><td>2400001</td><td>Test@1234</td></tr>
        <tr><td>Student Two (Quality Tester Two, 1st year)</td><td>${ids['users-create-s23']}</td><td>2400002</td><td>Test@1234</td></tr>
        <tr><td>Student Three (Quality Tester Three, 2nd year)</td><td>${ids['users-create-s23']}</td><td>2400003</td><td>Test@1234</td></tr>
        <tr><td>Registered student (Quality Registrant)</td><td>${ids['reg-valid']}</td><td>2400010</td><td>Test@1234, later New@12345</td></tr>
      </table>
    </div>
    <div class="block"><h3>Test file</h3>
      <p><code>docs/testing/Sample-Cataloging-10-items.docx</code> in the project folder. It holds 10 multiple-choice questions on Cataloging and Classification. The correct choice of each item is typed in red.</p>
      <table>
        <tr><th style="width:24mm;white-space:nowrap">Item</th><td>1</td><td>2</td><td>3</td><td>4</td><td>5</td><td>6</td><td>7</td><td>8</td><td>9</td><td>10</td></tr>
        <tr><th style="white-space:nowrap">Answer key</th><td>B</td><td>C</td><td>A</td><td>D</td><td>B</td><td>A</td><td>C</td><td>D</td><td>B</td><td>A</td></tr>
      </table>
      <p>The passing score everywhere in the system is 75%.</p>
    </div>
    <div class="block"><h3>Emails on the local copy</h3>
      <p>The local copy does not send real emails. Each email is written at the end of the file <code>storage/logs/laravel.log</code> in the project folder. Open the file, go to the bottom and copy the link from there. If a copied link contains <code>&amp;amp;</code>, change each one to <code>&amp;</code> before opening it.</p>
    </div>
    <div class="block"><h3>Tools for Part B (white box)</h3>
      <ul>
        <li><b>Terminal</b> opened in the project folder, for the commands.</li>
        <li><b>DevTools</b> (press F12 in the browser): the Network tab shows each request and its status number; the Console runs the lines below; Application shows the cookies.</li>
        <li><b>phpMyAdmin</b> to read the database tables.</li>
      </ul>
      <p>Before running a Console line, sign in as the account named in the test, open the Console and paste this helper once per page load. It calls the server as the signed-in user and prints the status number and the reply:</p>
      <code class="helper">api = (method, path, body) =&gt; fetch('/api' + path, { method, credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }).then(async (r) =&gt; console.log(r.status, await r.json().catch(() =&gt; '')))</code>
      <p>The helper and every command and Console line of Part B are also in the file <code>docs/testing/White-Box-Commands.txt</code>. Copy them from there instead of retyping them from this page.</p>
      <p>Words in capitals inside a Console line (EXAM, ROOM, ATTEMPT, BANK) stand for an id number. Read the number from the matching table in phpMyAdmin (exams, rooms, exam_attempts, question_banks) and type it in place of the word.</p>
    </div>
    <div class="block"><h3>After testing</h3>
      <p>The tests leave test accounts, rooms, exams and results in the database, and the End of School Year module changes year levels. Ask the developer to reset the test data before the system is used for real classes.</p>
    </div>
  </div>
${parts.map(table).join('\n')}
  <div class="end">
    <table>
      <tr><th style="width:40mm">Summary</th><th>Black box</th><th>White box</th><th>Total</th></tr>
      <tr><td>Test cases</td><td>${parts[0].count}</td><td>${parts[1].count}</td><td>${total}</td></tr>
      <tr><td>Passed</td><td></td><td></td><td></td></tr>
      <tr><td>Failed</td><td></td><td></td><td></td></tr>
      <tr><td>Not run</td><td></td><td></td><td></td></tr>
    </table>
  </div>
</body></html>`

const outDir = process.argv[2] || __dirname
fs.writeFileSync(path.join(__dirname, 'BLIS-Test-Cases.html'), html)
console.log(`cases: ${parts.map((p) => `${p.code} ${p.count}`).join(', ')} | total ${total}`)

;(async () => {
  const { chromium } = require(process.env.PW_PATH)
  const browser = await chromium.launch({ executablePath: process.env.PW_EXE })
  const page = await browser.newPage()
  await page.setContent(html, { waitUntil: 'load' })
  const target = path.join(outDir, 'BLIS-Test-Cases.pdf')
  await page.pdf({
    path: target,
    format: 'A4',
    landscape: true,
    printBackground: true,
    preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate: '<div style="width:100%;font-family:Arial;font-size:7.5pt;color:#444;padding:0 9mm;display:flex;justify-content:space-between;"><span>LNU LLE Platform (BLIS) Test Case</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>',
  })
  // Rows that are too tall or cells whose text runs outside them would be layout faults.
  const faults = await page.evaluate(() => {
    const out = []
    document.querySelectorAll('tbody td').forEach((td) => { if (td.scrollWidth > td.clientWidth + 1) out.push(td.parentElement.querySelector('.id')?.textContent + ': ' + td.textContent.slice(0, 40)) })
    return out
  })
  console.log('cells wider than their column:', faults.length, faults.slice(0, 8))
  await browser.close()
  console.log('saved', target, Math.round(fs.statSync(target).size / 1024) + ' KB')
})().catch((e) => { console.error('CRASH', e); process.exit(1) })
