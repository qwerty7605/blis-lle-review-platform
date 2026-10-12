import axios from 'axios'
import { noteServerTime } from '@/utils/clock'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: true,
})

/*
 * What to do when the server says the session is over. Set once, from
 * main.js, because doing it needs the router and the sign-in store and both
 * of those import this file.
 */
let onSessionEnded = null

export function setSessionEndedHandler(handler) {
  onSessionEnded = handler
}

// The exact words of the 403 a disabled account gets from the API.
const ACCOUNT_DISABLED_MESSAGE = 'Account is disabled.'

// A 401 from these is an ordinary answer (a wrong password, "nobody is signed
// in"), not a session ending under a page that was using it.
function isAuthRequest(config) {
  return /\/auth\//.test(String(config?.url ?? ''))
}

api.interceptors.response.use(
  (res) => {
    noteServerTime(res.headers?.date)

    return res
  },
  (err) => {
    noteServerTime(err.response?.headers?.date)

    // The sign-in expired, or was ended from somewhere else. Every request
    // from here on would fail the same way and the page would sit there
    // showing errors until it was reloaded; send the reader to sign in again
    // instead.
    if (err.response?.status === 401 && !isAuthRequest(err.config)) {
      onSessionEnded?.('ended')
    }

    // An account switched off while it was signed in is told so once, with a
    // 403, and its session is removed with that answer (see $ensureActive in
    // routes/api.php).
    if (err.response?.status === 403 && err.response?.data?.message === ACCOUNT_DISABLED_MESSAGE) {
      onSessionEnded?.('disabled')
    }

    return Promise.reject(err)
  },
)

export default api
