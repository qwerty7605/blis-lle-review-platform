// Writes the commands and Console lines of the white box tests to a text file, so nobody retypes them from the PDF.
const fs = require('fs')
const path = require('path')

const cases = require('./cases-wb.cjs').filter((item) => !item.section)
const isCode = (line) => /^(api\(|Promise\.|for \(|document\.|localStorage|docker |cd |npx )/.test(line.trim())

const out = [
  'LNU LLE PLATFORM (BLIS) - WHITE BOX TESTS: COMMANDS AND CONSOLE LINES',
  'Copy from this file instead of retyping from the PDF. The Test IDs match Part B of BLIS-Test-Cases.pdf.',
  '',
  'CONSOLE HELPER (paste once per page load, after signing in as the account named in the test):',
  "api = (method, path, body) => fetch('/api' + path, { method, credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }).then(async (r) => console.log(r.status, await r.json().catch(() => '')))",
  '',
  'Words in capitals (EXAM, ROOM, ROOMB, ATTEMPT, BANK, Q1, OPT) stand for id numbers. Replace them before running the line.',
  '',
]

cases.forEach((item, index) => {
  const lines = item.d.split('\n').filter(isCode)
  if (!lines.length) return
  out.push(`WB_${String(index + 1).padStart(3, '0')}`)
  lines.forEach((line) => out.push(line))
  out.push('')
})

const target = path.join(process.argv[2] || __dirname, 'White-Box-Commands.txt')
fs.writeFileSync(target, out.join('\r\n'))
console.log('saved', target, out.length, 'lines')
