// Runs before the page draws, so someone in dark mode never sees a white
// flash while the app loads. A file, not an inline script: the production
// security policy only runs scripts served from this site. src/utils/theme.js
// takes over once the app starts.
(function () {
  try {
    var choice = localStorage.getItem('blis-theme')
    var dark = choice === 'dark' ||
      (choice === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
    if (dark) {
      document.documentElement.setAttribute('data-theme', 'dark')
      // The page colour too, for the moment before the stylesheet arrives
      // (a slow connection); style.css paints the same colour after it.
      document.documentElement.style.colorScheme = 'dark'
      document.documentElement.style.backgroundColor = '#11141c'
    }
  } catch (e) {
    // No storage: light, as before.
  }
})()
