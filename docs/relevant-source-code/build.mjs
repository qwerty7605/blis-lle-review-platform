// Builds the Relevant Source Code appendix as a Word file, quoting the code
// exactly as it stands at a release tag. No packages needed: it writes the
// Word XML itself and zips it with Node's zlib.
//
//   node docs/relevant-source-code/build.mjs [tag]        (default v1.0.0)
//
// The excerpts and their descriptions are in listings.mjs. Line numbers in the
// appendix are the lines of each file at the tag, so a reader can find them in
// the repository at that release.
import { execFileSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import zlib from 'node:zlib'
import { SECTIONS } from './listings.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(HERE, '../..')
const TAG = process.argv[2] || 'v1.0.0'
const REPO = 'https://github.com/qwerty7605/blis-lle-review-platform'
const PRODUCT = 'LNU BLIS Platform'
const FULL_TITLE = "LNU BLIS Platform: Mock Librarians' Licensure Examination and Review System"
const AUTHORS = ['Eujan Eol Zeta', 'Julius Rey dela Peña', 'Wayne Martin Tabalanza', 'Prince Andrew Yan']
// Code is 9 pt Courier New: 5.4 pt (108 twips) a character.
const CHAR = 108
const OUT = path.join(HERE, `BLIS-Relevant-Source-Code-${TAG}.docx`)

const git = (...args) => execFileSync('git', args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
const commit = git('rev-list', '-n', '1', TAG).trim()
const tagDate = git('log', '-1', '--format=%cs', TAG).trim()

// ------------------------------------------------------------------ excerpts

function excerpt(listing) {
  const lines = git('show', `${TAG}:${listing.file}`).replace(/\r\n/g, '\n').split('\n')
  const parts = []
  listing.ranges.forEach(([from, to, starts], i) => {
    if (!lines[from - 1]?.includes(starts)) {
      throw new Error(`${listing.file}:${from} does not contain "${starts}" at ${TAG}. Update listings.mjs.`)
    }
    if (to > lines.length || to < from) throw new Error(`${listing.file}: bad range ${from}-${to}`)
    if (i > 0) parts.push({ omitted: [listing.ranges[i - 1][1] + 1, from - 1] })
    for (let n = from; n <= to; n++) parts.push({ n, code: lines[n - 1].replace(/\t/g, '    ') })
  })
  if (listing.omitAfter) parts.push({ omitted: [listing.ranges[listing.ranges.length - 1][1] + 1, listing.omitAfter] })
  return parts
}

const rangeText = (ranges) => ranges.map(([a, b]) => `${a}–${b}`).join(' and ')
const githubUrl = (file, ranges) => `${REPO}/blob/${TAG}/${file}#L${ranges[0][0]}-L${ranges[ranges.length - 1][1]}`

// ------------------------------------------------------------------ Word XML

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
const run = (text, rPr = '') => `<w:r>${rPr ? `<w:rPr>${rPr}</w:rPr>` : ''}<w:t xml:space="preserve">${esc(text)}</w:t></w:r>`

// Prose may carry <b>…</b>, rendered bold.
function richRuns(text) {
  return text.split(/(<b>.*?<\/b>)/).filter(Boolean)
    .map((part) => part.startsWith('<b>') ? run(part.slice(3, -4), '<w:b/>') : run(part)).join('')
}

const para = (inner, style, pPr = '') => `<w:p><w:pPr>${style ? `<w:pStyle w:val="${style}"/>` : ''}${pPr}</w:pPr>${inner}</w:p>`

const rels = []
function link(url, text) {
  const id = `rIdL${rels.length + 1}`
  rels.push(`<Relationship Id="${id}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="${esc(url)}" TargetMode="External"/>`)
  return `<w:hyperlink r:id="${id}" w:history="1">${run(text, '<w:rStyle w:val="Hyperlink"/>')}</w:hyperlink>`
}

// Characters of 9 pt Courier New that fit across the code box on A4 with
// 1-inch margins.
const LINE_CHARS = 74

// Splits a code line into pieces that fit, breaking after a space or a comma
// where it can; continuation pieces are indented under the line's own indent.
function wrap(code, room) {
  if (code.length <= room) return [code]
  const lead = code.match(/^ */)[0].length + 4
  const pieces = []
  let rest = code
  let first = true
  while (rest.length > (first ? room : room - lead)) {
    const limit = first ? room : room - lead
    let cut = Math.max(rest.lastIndexOf(' ', limit), rest.lastIndexOf(',', limit - 1) + 1)
    if (cut <= (first ? lead : 0) + 10) cut = limit
    pieces.push((first ? '' : ' '.repeat(lead)) + rest.slice(0, cut).trimEnd())
    rest = rest.slice(cut).trimStart()
    first = false
  }
  pieces.push(' '.repeat(lead) + rest)
  return pieces
}

const field = (instr, cached) =>
  `<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> ${instr} </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>${cached}<w:r><w:fldChar w:fldCharType="end"/></w:r>`

const body = []
const listOfListings = []
let listingNo = 0

body.push(para(run('Relevant Source Code'), 'Title'))
body.push(para(run(FULL_TITLE), 'Subtitle'))
body.push(para(run(`Version ${TAG.replace(/^v/, '')} (${tagDate})`), 'Subtitle'))

body.push(para(run('About this appendix'), 'Heading1'))
const intro = [
  `This appendix presents the parts of the ${PRODUCT}'s source code that carry the study: the prediction model behind the Estimated Outcome, the learning analytics, the reading and tagging of question sets, the handling of examinations, and the security measures. Routine code (page layouts, forms, styling and configuration) is not included.`,
  `Every excerpt is quoted exactly as it stands in release ${TAG} of the public repository (commit ${commit.slice(0, 7)}). The number at the start of each line is that line's number in its file at this release, so each excerpt can be found in the repository. Where lines are left out of an excerpt, the gap is marked with the lines it skips. Comments are those written in the code.`,
]
intro.forEach((t) => body.push(para(run(t))))
body.push(para(run('The complete source code of this version: ') + link(`${REPO}/tree/${TAG}`, `${REPO}/tree/${TAG}`)))

// The list of listings is a Word field over the "Listing" captions; Word fills
// in the page numbers when the document is opened (settings.xml asks it to).
body.push(para(run('List of Listings'), 'Heading1'))
const lolIndex = body.length
body.push('') // replaced below

SECTIONS.forEach((section, s) => {
  body.push(para(run(`${s + 1}. ${section.title}`), 'Heading1'))
  body.push(para(run(section.intro)))
  section.listings.forEach((listing) => {
    listingNo += 1
    listOfListings.push(`Listing ${listingNo}. ${listing.title}`)
    body.push(para(
      run('Listing ') + field('SEQ Listing \\* ARABIC', run(String(listingNo))) + run(`. ${listing.title}`),
      'Caption',
      '<w:keepNext/>',
    ))
    body.push(para(
      run('Source: ', '<w:i/>') + run(listing.file, '<w:rFonts w:ascii="Courier New" w:hAnsi="Courier New"/><w:sz w:val="20"/>') +
        run(`, lines ${rangeText(listing.ranges)}. `, '<w:i/>') + link(githubUrl(listing.file, listing.ranges), 'View on GitHub'),
      'SourceLine',
      '<w:keepNext/>',
    ))
    listing.text.forEach((t) => body.push(para(richRuns(t), 'ListingText', '<w:keepNext/>')))
    const parts = excerpt(listing)
    const width = String(Math.max(...parts.filter((p) => p.n).map((p) => p.n))).length
    const indent = `<w:ind w:left="${(width + 2) * CHAR}" w:hanging="${(width + 2) * CHAR}"/>`
    parts.forEach((p, i) => {
      const keep = (i < 2 ? '<w:keepNext/>' : '') + indent
      if (p.omitted) {
        const [a, b] = p.omitted
        body.push(para(run(`${' '.repeat(width)}  … lines ${a}–${b} omitted …`, '<w:i/><w:color w:val="666666"/>'), 'Code', keep))
      } else {
        // Lines too long for the page are wrapped here rather than by Word, so
        // they look the same in Word, LibreOffice and Google Docs: the rest of
        // the line continues under it, indented, with no line number.
        wrap(p.code, LINE_CHARS - width - 2).forEach((piece, k) => {
          const number = k === 0 ? String(p.n).padStart(width, ' ') : ' '.repeat(width)
          body.push(para(
            run(number + '  ', '<w:color w:val="8A8A8A"/>') + (piece ? run(piece) : ''),
            'Code',
            k === 0 ? keep : indent,
          ))
        })
      }
    })
    body.push(para('', 'AfterCode'))
  })
})

body[lolIndex] = listOfListings.map((entry, i) => {
  const cached = run(entry)
  return i === 0
    ? `<w:p><w:pPr><w:pStyle w:val="TableofFigures"/></w:pPr><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> TOC \\h \\z \\c "Listing" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>${cached}</w:p>`
    : para(cached, 'TableofFigures')
}).join('') + `<w:p><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>`

const A4 = '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>'
const documentXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>${body.join('')}<w:sectPr><w:footerReference w:type="default" r:id="rIdFooter"/>${A4}</w:sectPr></w:body></w:document>`

const footerXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:jc w:val="center"/></w:pPr>${field('PAGE', run('1'))}</w:p></w:ftr>`

const TNR = '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" w:cs="Times New Roman"/>'
const MONO = '<w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:eastAsia="Courier New" w:cs="Courier New"/>'
const box = '<w:pBdr><w:top w:val="single" w:sz="4" w:space="2" w:color="BFBFBF"/><w:left w:val="single" w:sz="4" w:space="4" w:color="BFBFBF"/><w:bottom w:val="single" w:sz="4" w:space="2" w:color="BFBFBF"/><w:right w:val="single" w:sz="4" w:space="4" w:color="BFBFBF"/></w:pBdr><w:shd w:val="clear" w:color="auto" w:fill="F6F6F6"/>'
// Code: 9 pt Courier New is 5.4 pt a character; a wrapped line continues under
// the code, past the line number (number width + 2 spaces).

const style = (id, name, pPr, rPr, extra = '') =>
  `<w:style w:type="paragraph" w:styleId="${id}"><w:name w:val="${name}"/><w:basedOn w:val="Normal"/>${extra}<w:pPr>${pPr}</w:pPr><w:rPr>${rPr}</w:rPr></w:style>`

const stylesXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
<w:docDefaults><w:rPrDefault><w:rPr>${TNR}<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="en-PH"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="276" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr></w:pPrDefault></w:docDefaults>
<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>
${style('Title', 'Title', '<w:jc w:val="center"/><w:spacing w:before="0" w:after="120"/>', '<w:b/><w:sz w:val="36"/><w:szCs w:val="36"/>', '<w:qFormat/>')}
${style('Subtitle', 'Subtitle', '<w:jc w:val="center"/><w:spacing w:after="60"/>', '<w:sz w:val="24"/>', '<w:qFormat/>')}
${style('Heading1', 'heading 1', '<w:keepNext/><w:spacing w:before="360" w:after="160"/><w:jc w:val="left"/><w:outlineLvl w:val="0"/>', '<w:b/><w:sz w:val="28"/><w:szCs w:val="28"/>', '<w:next w:val="Normal"/><w:qFormat/>')}
${style('Caption', 'caption', '<w:keepNext/><w:spacing w:before="320" w:after="60"/><w:jc w:val="left"/>', '<w:b/><w:sz w:val="24"/>', '<w:qFormat/>')}
${style('SourceLine', 'Listing Source', '<w:keepNext/><w:spacing w:after="80"/><w:jc w:val="left"/>', '<w:sz w:val="22"/>')}
${style('ListingText', 'Listing Description', '<w:keepNext/><w:spacing w:after="120"/>', '')}
${style('Code', 'Code', `${box}<w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/><w:jc w:val="left"/>`, `${MONO}<w:sz w:val="18"/><w:szCs w:val="18"/>`)}
${style('AfterCode', 'After Code', '<w:spacing w:before="0" w:after="120"/>', '<w:sz w:val="12"/>')}
${style('TableofFigures', 'table of figures', '<w:tabs><w:tab w:val="right" w:leader="dot" w:pos="9026"/></w:tabs><w:spacing w:after="60"/><w:jc w:val="left"/>', '')}
<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:rPr><w:color w:val="1F4E9C"/><w:u w:val="single"/></w:rPr></w:style>
</w:styles>`

const settingsXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:updateFields w:val="true"/><w:defaultTabStop w:val="720"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>`

const now = new Date().toISOString().replace(/\.\d+Z$/, 'Z')
const coreXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Relevant Source Code: ${esc(PRODUCT)} ${esc(TAG)}</dc:title><dc:creator>${esc(AUTHORS.join('; '))}</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">${now}</dcterms:created></cp:coreProperties>`

const files = {
  '[Content_Types].xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>`,
  '_rels/.rels': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>`,
  'word/_rels/document.xml.rels': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rIdSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/><Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>${rels.join('')}</Relationships>`,
  'word/document.xml': documentXml,
  'word/styles.xml': stylesXml,
  'word/settings.xml': settingsXml,
  'word/footer1.xml': footerXml,
  'docProps/core.xml': coreXml,
}

// ------------------------------------------------------------------ zip

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
  let c = n
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
  return c >>> 0
})
const crc32 = (buf) => {
  let c = 0xffffffff
  for (const b of buf) c = CRC_TABLE[(c ^ b) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

function zip(entries) {
  const local = []
  const central = []
  let offset = 0
  for (const [name, content] of Object.entries(entries)) {
    const data = Buffer.from(content, 'utf8')
    const packed = zlib.deflateRawSync(data)
    const nameBuf = Buffer.from(name, 'utf8')
    const crc = crc32(data)
    const head = Buffer.alloc(30)
    head.writeUInt32LE(0x04034b50, 0); head.writeUInt16LE(20, 4); head.writeUInt16LE(0x0800, 6); head.writeUInt16LE(8, 8)
    head.writeUInt32LE(0, 10); head.writeUInt32LE(crc, 14); head.writeUInt32LE(packed.length, 18); head.writeUInt32LE(data.length, 22)
    head.writeUInt16LE(nameBuf.length, 26); head.writeUInt16LE(0, 28)
    local.push(head, nameBuf, packed)
    const dir = Buffer.alloc(46)
    dir.writeUInt32LE(0x02014b50, 0); dir.writeUInt16LE(20, 4); dir.writeUInt16LE(20, 6); dir.writeUInt16LE(0x0800, 8); dir.writeUInt16LE(8, 10)
    dir.writeUInt32LE(0, 12); dir.writeUInt32LE(crc, 16); dir.writeUInt32LE(packed.length, 20); dir.writeUInt32LE(data.length, 24)
    dir.writeUInt16LE(nameBuf.length, 28); dir.writeUInt32LE(offset, 42)
    central.push(dir, nameBuf)
    offset += head.length + nameBuf.length + packed.length
  }
  const centralBuf = Buffer.concat(central)
  const end = Buffer.alloc(22)
  end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(Object.keys(entries).length, 8); end.writeUInt16LE(Object.keys(entries).length, 10)
  end.writeUInt32LE(centralBuf.length, 12); end.writeUInt32LE(offset, 16)
  return Buffer.concat([...local, centralBuf, end])
}

writeFileSync(OUT, zip(files))
console.log(`saved ${path.relative(ROOT, OUT)}: ${listingNo} listings from ${TAG} (${commit.slice(0, 7)})`)
