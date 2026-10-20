#!/usr/bin/env node
/**
 * Dark mode for colours written straight into the stylesheets.
 *
 * Most of the app's CSS names its colours directly (`#fff`, `rgba(13, 21, 71,
 * 0.12)`) rather than through a variable, so there is no single palette to
 * swap. This script gives each such colour a dark counterpart without
 * changing what light mode draws:
 *
 *   background: #fff;   ->   background: var(--dk-bg-ffffff, #fff);
 *
 * In light mode `--dk-bg-ffffff` is never defined, so the fallback (the
 * original colour) is used exactly as before. `src/theme/dark-colors.css`
 * defines every `--dk-*` variable under `:root[data-theme='dark']`.
 *
 * The dark colour depends on what the colour is used FOR, read off the
 * property: a white background turns into a dark surface, while white text
 * (on a navy button) stays white; navy text turns light, while a navy button
 * stays navy. Roles: bg, fg (text), line (borders, rings), shadow.
 * Ambiguous brand tokens (`var(--lnu-navy)` and friends) are wrapped the
 * same way; the unambiguous ones (--ink, --surface, --border-soft, ...) are
 * redefined directly in style.css.
 *
 * Usage (from frontend/):
 *   node scripts/dark-theme.mjs           convert any new colours, rewrite dark-colors.css
 *   node scripts/dark-theme.mjs --check   exit 1 if a colour is not converted yet
 *
 * Hand-tuned dark values go in OVERRIDES below, keyed by variable name.
 */
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', 'src')
const OUT = join(ROOT, 'theme', 'dark-colors.css')
const CHECK = process.argv.includes('--check')

/** Hand-tuned dark values that the formulas below get wrong or merely adequate. */
const OVERRIDES = {
  // Navy as text becomes a soft periwinkle, as a fill it stays a navy that
  // still stands off the dark page.
  '--dk-fg-lnu-navy': '#c3c9f4',
  '--dk-fg-lnu-navy-deep': '#dfe3fa',
  '--dk-fg-lnu-navy-light': '#b4bcf0',
  '--dk-bg-lnu-navy': '#283593',
  '--dk-bg-lnu-navy-deep': '#1c2470',
  '--dk-bg-lnu-navy-light': '#3442a8',
  '--dk-line-lnu-navy': '#8f99e0',
  '--dk-line-lnu-navy-deep': '#8f99e0',
  '--dk-line-lnu-navy-light': '#8f99e0',
  '--dk-bg-lnu-white': '#1b1f2b',
  '--dk-bg-lnu-bg': '#11141c',
  '--dk-bg-lnu-gray': '#262b3a',
  '--dk-bg-lnu-gold-pale': '#2e2817',
  '--dk-fg-lnu-success': '#7fcf8a',
  '--dk-fg-lnu-danger': '#ff8a80',
  '--dk-fg-lnu-info': '#9fb6f2',
}

// Brand tokens whose role depends on where they are used. Light values are
// read from style.css so the formulas can work from them.
const ROLE_TOKENS = [
  'lnu-navy', 'lnu-navy-deep', 'lnu-navy-light', 'lnu-gold', 'lnu-gold-light', 'lnu-gold-pale',
  'lnu-bg', 'lnu-success', 'lnu-danger', 'lnu-danger-strong', 'lnu-danger-strong-hover',
  'lnu-gray', 'lnu-gray-dark', 'lnu-white', 'lnu-info',
]

// ---------------------------------------------------------------- colour maths

function parseColor(text) {
  const t = text.trim().toLowerCase()
  let m = t.match(/^#([0-9a-f]{3,8})$/)
  if (m) {
    let h = m[1]
    if (h.length === 3 || h.length === 4) h = [...h].map((c) => c + c).join('')
    if (h.length !== 6 && h.length !== 8) return null
    return {
      r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16),
      a: h.length === 8 ? Math.round((parseInt(h.slice(6, 8), 16) / 255) * 100) / 100 : 1,
    }
  }
  m = t.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,/]+([\d.]+%?))?\s*\)$/)
  if (m) {
    let a = m[4] === undefined ? 1 : m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4])
    return { r: +m[1], g: +m[2], b: +m[3], a }
  }
  if (t === 'white') return { r: 255, g: 255, b: 255, a: 1 }
  if (t === 'black') return { r: 0, g: 0, b: 0, a: 1 }
  return null
}

const toLinear = (c) => { c /= 255; return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
const fromLinear = (c) => 255 * (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055)

function toOklch({ r, g, b, a }) {
  const [R, G, B] = [toLinear(r), toLinear(g), toLinear(b)]
  const l = Math.cbrt(0.4122214708 * R + 0.5363325363 * G + 0.0514459929 * B)
  const m = Math.cbrt(0.2119034982 * R + 0.6806995451 * G + 0.1073969566 * B)
  const s = Math.cbrt(0.0883024619 * R + 0.2817188376 * G + 0.6299787005 * B)
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s
  const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s
  const Bb = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s
  return { L, C: Math.hypot(A, Bb), h: Math.atan2(Bb, A), a }
}

function oklchToRgb(L, C, h) {
  const A = C * Math.cos(h), Bb = C * Math.sin(h)
  const l = (L + 0.3963377774 * A + 0.2158037573 * Bb) ** 3
  const m = (L - 0.1055613458 * A - 0.0638541728 * Bb) ** 3
  const s = (L - 0.0894841775 * A - 1.291485548 * Bb) ** 3
  return [
    4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    -0.0041960863 * l - 0.7034186147 * m + 1.707695685 * s,
  ]
}

/** Back to sRGB, giving up chroma (never lightness) until it fits. */
function fromOklch({ L, C, h, a }) {
  let c = C
  let lin = oklchToRgb(L, c, h)
  while (c > 0 && lin.some((v) => v < -1e-4 || v > 1 + 1e-4)) {
    c = Math.max(0, c - 0.005)
    lin = oklchToRgb(L, c, h)
  }
  const [r, g, b] = lin.map((v) => Math.round(Math.min(255, Math.max(0, fromLinear(Math.min(1, Math.max(0, v)))))))
  return format({ r, g, b, a })
}

function format({ r, g, b, a }) {
  if (a >= 1) return '#' + [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')
  return `rgba(${r}, ${g}, ${b}, ${Math.round(a * 1000) / 1000})`
}

const isBlue = (h) => { const deg = ((h * 180) / Math.PI + 360) % 360; return deg > 230 && deg < 300 }

// ------------------------------------------------------------ the dark roles

const ROLES = {
  /** Surfaces and fills. Light surfaces go dark keeping their order (white,
   *  the most raised, stays the lightest dark); brand fills stay. */
  bg(c) {
    if (c.a >= 0.6) {
      if (c.L >= 0.75 && c.C >= 0.08) return c // a vivid fill (the gold buttons) stays
      if (c.L >= 0.75) return { ...c, L: 0.17 + (c.L - 0.75) * 0.26, C: Math.min(c.C * 0.8, 0.05) }
      return c
    }
    if (c.L >= 0.99 && c.C < 0.01) return c // a white glaze over a navy panel
    if (c.L >= 0.75) return { ...c, L: 0.17 + (c.L - 0.75) * 0.26, C: Math.min(c.C * 0.8, 0.05) } // a pale wash -> a dark one
    if (c.L < 0.5) return { ...c, L: 0.82, C: Math.min(c.C, 0.08) } // a navy tint -> a light tint
    return { ...c, L: Math.max(c.L, 0.72), a: Math.min(1, c.a * 1.25) } // a coloured tint
  },
  /** Text and icons: dark ink turns light, light ink stays. */
  fg(c) {
    if (c.L >= 0.72) return c
    return { ...c, L: 0.97 - c.L * 0.5, C: Math.min(c.C, isBlue(c.h) ? 0.07 : 0.13) }
  },
  /** Borders and rings: a light hairline becomes a dark one; ink-coloured lines turn light. */
  line(c) {
    if (c.a >= 0.6 && c.L >= 0.72) return { ...c, L: 0.27 + (c.L - 0.72) * 0.35, C: Math.min(c.C, 0.04) }
    return ROLES.fg(c)
  },
  /** Drop shadows go black and a little stronger; glows stay. */
  shadow(c) {
    if (c.L < 0.5) return { L: 0, C: 0, h: 0, a: Math.min(0.6, c.a * 2.2) }
    return c
  },
  /** fill/stroke and unnamed custom properties: decided by the colour itself. */
  auto(c) {
    return c.a >= 0.6 && c.L >= 0.72 ? ROLES.bg(c) : ROLES.fg(c)
  },
}

function roleOf(prop, value) {
  const p = prop.toLowerCase()
  if (p.startsWith('--')) {
    if (/shadow/.test(p)) return 'shadow'
    if (/border|line|rule|ring/.test(p)) return 'line'
    if (/bg|surface|tint|fill|track|background/.test(p)) return 'bg'
    if (/ink|text|label|muted|fg|color/.test(p)) return 'fg'
    return 'auto'
  }
  if (p === 'background' || p.startsWith('background-')) return 'bg'
  if (['color', 'caret-color', '-webkit-text-fill-color', 'accent-color'].includes(p)) return 'fg'
  if (p.startsWith('border') || p.startsWith('outline') || p.startsWith('text-decoration') || p.startsWith('column-rule')) return 'line'
  if (p === 'box-shadow' || p === 'text-shadow' || p === 'filter') return 'shadow'
  if (['fill', 'stroke', 'stop-color', 'flood-color', 'lighting-color'].includes(p)) return 'auto'
  return null
}

// ------------------------------------------------------------- the rewrite

const lightTokens = {}
{
  const css = readFileSync(join(ROOT, 'style.css'), 'utf8')
  for (const name of ROLE_TOKENS) {
    const m = css.match(new RegExp(`--${name}:\\s*([^;]+);`))
    if (m) lightTokens[name] = m[1].trim()
  }
}

const COLOR_RE = /#[0-9a-fA-F]{3,8}\b|rgba?\([^()]*\)|\bwhite\b|\bblack\b|var\(--([a-z0-9-]+)\)/g

const used = new Map() // variable -> dark value
const unconverted = []

function keyFor(c) {
  const hex = [c.r, c.g, c.b].map((v) => v.toString(16).padStart(2, '0')).join('')
  return c.a >= 1 ? hex : `${hex}-a${String(Math.round(c.a * 100)).padStart(2, '0')}`
}

function darkFor(role, color) {
  return fromOklch(ROLES[role](toOklch(color)))
}

/** Spans of `var(--dk-…)` already in a value, with their nested parentheses. */
function convertedSpans(value) {
  const spans = []
  let i = value.indexOf('var(--dk-')
  while (i !== -1) {
    let depth = 0, j = i
    for (; j < value.length; j++) {
      if (value[j] === '(') depth++
      else if (value[j] === ')' && --depth === 0) break
    }
    spans.push([i, j + 1])
    // Note what an earlier run made, so the dark file keeps it.
    const inner = value.slice(i, j + 1).match(/^var\((--dk-(bg|fg|line|shadow|auto)-[a-z0-9-]+),\s*(.*)\)$/s)
    if (inner) remember(inner[1], inner[2], inner[3])
    i = value.indexOf('var(--dk-', i + 1) // nested ones too
  }
  return spans
}

function remember(variable, role, original) {
  if (used.has(variable)) return
  if (OVERRIDES[variable]) return used.set(variable, OVERRIDES[variable])
  const tok = original.match(/^var\(--([a-z0-9-]+)[,)]/)
  const color = parseColor(tok ? lightTokens[tok[1]] ?? '' : original)
  if (color) used.set(variable, darkFor(role, color))
}


/** `var(--lnu-navy, #1a237e)`: a brand token written with a fallback is wrapped whole. */
function wrapTokensWithFallback(value, role, file, line) {
  const spans = convertedSpans(value)
  let out = ''
  let last = 0
  const re = /var\(--([a-z0-9-]+),/g
  let m
  while ((m = re.exec(value))) {
    const at = m.index
    if (!ROLE_TOKENS.includes(m[1]) || spans.some(([a, b]) => at >= a && at < b)) continue
    let depth = 0, j = at
    for (; j < value.length; j++) {
      if (value[j] === '(') depth++
      else if (value[j] === ')' && --depth === 0) break
    }
    const whole = value.slice(at, j + 1)
    const variable = `--dk-${role}-${m[1]}`
    remember(variable, role, `var(--${m[1]})`)
    if (CHECK) { unconverted.push(`${file}:${line}  ${whole}`); continue }
    out += value.slice(last, at) + `var(${variable}, ${whole})`
    last = j + 1
    re.lastIndex = j + 1
  }
  return out + value.slice(last)
}

/** One declaration value -> converted value. */
function convertValue(prop, value, file, line) {
  let role = roleOf(prop, value)
  if (!role) return value
  value = wrapTokensWithFallback(value, role, file, line)
  const spans = convertedSpans(value)
  const inSpan = (at) => spans.some(([a, b]) => at >= a && at < b)
  // A 0-blur shadow with a spread is drawn as a ring or border.
  return value.replace(COLOR_RE, (match, token, offset) => {
    if (inSpan(offset)) return match
    let r = role
    if (r === 'shadow') {
      const seg = value.slice(0, offset).split(',').pop()
      if (/^\s*(inset\s+)?-?[\d.]+(px)?\s+-?[\d.]+(px)?\s+0(px)?\s+[\d.]+/.test(seg)) r = 'line'
    }
    if (token) {
      if (!ROLE_TOKENS.includes(token)) return match
      const variable = `--dk-${r}-${token}`
      remember(variable, r, match)
      if (CHECK) { unconverted.push(`${file}:${line}  ${prop}: ${match}`); return match }
      return `var(${variable}, ${match})`
    }
    if ((match === 'white' || match === 'black') && !/^(color|background|background-color|fill|stroke|border|border-color|outline-color)$/.test(prop)) return match
    const color = parseColor(match)
    if (!color) return match
    const variable = `--dk-${r}-${keyFor(color)}`
    remember(variable, r, match)
    if (CHECK) { unconverted.push(`${file}:${line}  ${prop}: ${match}`); return match }
    return `var(${variable}, ${match})`
  })
}


/** A colour that stays as it is in dark mode when used as a fill (gold). */
function staysLightFill(text) {
  const tok = text.match(/^var\(--([a-z0-9-]+)\)$/)
  const color = parseColor(tok ? lightTokens[tok[1]] ?? '' : text)
  if (!color) return false
  const c = toOklch(color)
  return c.a >= 0.6 && c.L >= 0.75 && ROLES.bg(c) === c
}

/** Rule blocks that paint a fill which stays light: their text keeps its light colour too. */
function keepTextBlocks(masked) {
  const blocks = []
  const re = /\{([^{}]*)\}/g
  let m
  while ((m = re.exec(masked))) {
    const bg = m[1].match(/(?:^|[;\s])background(?:-color|-image)?\s*:\s*([^;]+)/)
    if (!bg) continue
    // Read the light colours: an earlier run's wrapping is looked through.
    const light = bg[1].replace(/var\(--dk-[a-z0-9-]+,\s*/g, '')
    const colors = light.match(/#[0-9a-fA-F]{3,8}\b|rgba?\([^()]*\)|var\(--[a-z0-9-]+\)/g) ?? []
    if (colors.length && colors.every(staysLightFill)) blocks.push([m.index, m.index + m[0].length])
  }
  return blocks
}

/** `var(--dk-…, X)` -> X, for a colour that should not change in dark mode. */
function unwrap(value) {
  let v = value
  for (;;) {
    const m = v.match(/var\(--dk-[a-z0-9-]+,\s*/)
    if (!m) return v
    let depth = 0, j = m.index
    for (; j < v.length; j++) {
      if (v[j] === '(') depth++
      else if (v[j] === ')' && --depth === 0) break
    }
    v = v.slice(0, m.index) + v.slice(m.index + m[0].length, j) + v.slice(j + 1)
  }
}

/** Rewrite every declaration in a stretch of CSS (comments left alone). */
function convertCss(css, file, lineOffset, { skipRootTokens }) {
  // Blank out comments for matching, keeping positions.
  const masked = css.replace(/\/\*[\s\S]*?\*\//g, (c) => c.replace(/[^\n]/g, ' '))
  const keep = keepTextBlocks(masked)
  const decl = /([-\w]+)\s*:\s*([^;{}]+?)(?=;|\s*})/g
  let out = ''
  let last = 0
  let m
  while ((m = decl.exec(masked))) {
    const [whole, prop, value] = m
    const start = m.index + whole.length - value.length
    const before = masked.slice(0, m.index)
    // The :root token block of style.css and the hand-written dark block are left as written.
    if (skipRootTokens && prop.startsWith('--') && /:root[^{]*\{[^}]*$/.test(before)) continue
    if (/data-theme=['"]?dark['"]?\][^{]*\{[^}]*$/.test(before)) continue
    const line = lineOffset + before.split('\n').length - 1
    const original = css.slice(start, start + value.length)
    const keepText = prop === 'color' && keep.some(([a, b]) => start > a && start < b)
    const converted = keepText ? unwrap(original) : convertValue(prop, original, file, line)
    if (converted !== original) {
      out += css.slice(last, start) + converted
      last = start + value.length
    }
  }
  return out + css.slice(last)
}

function walk(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? walk(path) : [path]
  })
}

let changedFiles = 0
for (const path of walk(ROOT)) {
  if (path === OUT) continue
  const rel = relative(ROOT, path)
  const text = readFileSync(path, 'utf8')
  let next = text
  if (path.endsWith('.css')) {
    next = convertCss(text, rel, 1, { skipRootTokens: rel === 'style.css' })
  } else if (path.endsWith('.vue')) {
    next = text.replace(/(<style[^>]*>)([\s\S]*?)(<\/style>)/g, (all, open, body, close, offset) => {
      const lineOffset = text.slice(0, offset).split('\n').length
      return open + convertCss(body, rel, lineOffset, { skipRootTokens: false }) + close
    })
  }
  if (next !== text && !CHECK) {
    writeFileSync(path, next)
    changedFiles++
  }
}

if (CHECK) {
  if (unconverted.length) {
    console.error(`${unconverted.length} colour(s) have no dark counterpart yet. Run: node scripts/dark-theme.mjs\n`)
    console.error(unconverted.slice(0, 40).join('\n'))
    process.exit(1)
  }
  console.log('Every colour has a dark counterpart.')
  process.exit(0)
}

const lines = [...used.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([k, v]) => `  ${k}: ${v};`)
writeFileSync(OUT, `/* Generated by scripts/dark-theme.mjs -- do not edit by hand; put a
   hand-tuned value in that script's OVERRIDES and run it again. Each variable
   is the dark-mode stand-in for one colour in one role (bg, fg, line,
   shadow); light mode never defines them, so it draws the original colour. */
:root[data-theme='dark'] {
${lines.join('\n')}
}
`)
console.log(`${changedFiles} file(s) converted, ${used.size} dark colour(s) in ${relative(join(ROOT, '..'), OUT)}`)
