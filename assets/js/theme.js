(function () {
  const allowedBackgrounds = ['default', 'ocean', 'meadow', 'sunset'];

  fetch('api/settings.php', { credentials: 'same-origin' })
    .then(response => response.ok ? response.json() : Promise.reject(new Error('Unable to load theme')))
    .then(settings => {
      const background = allowedBackgrounds.includes(settings.siteBackground) ? settings.siteBackground : 'default';
      document.body.dataset.siteBackground = background;
    })
    .catch(() => {
      document.body.dataset.siteBackground = 'default';
    });
})();