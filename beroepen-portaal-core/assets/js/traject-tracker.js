(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var trackers = document.querySelectorAll('.bp-traject-tracker[data-progress]');
    trackers.forEach(function (tracker) {
      var value = Number(tracker.getAttribute('data-progress'));
      if (Number.isNaN(value)) {
        return;
      }

      value = Math.max(0, Math.min(100, value));
      var fill = tracker.querySelector('.bp-traject-progress-fill');
      if (fill) {
        fill.style.width = value + '%';
      }

      tracker.classList.add('is-ready');
    });
  });
})();
