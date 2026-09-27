(() => {
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js', {scope: './'}).catch(() => {
      // The portal remains fully functional when a browser or host blocks PWA support.
    });
  });
})();
