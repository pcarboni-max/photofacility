// Lightbox: navigazione sequenziale delle foto del giorno.
(function () {
  'use strict';
  var items = window.__ITEMS__ || [];
  if (!items.length) return;

  var lb = document.getElementById('lb');
  if (!lb) return;
  var wrap = lb.querySelector('.lb-imgwrap');
  var meta = lb.querySelector('.lb-meta');
  var cur = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function render(i) {
    cur = (i + items.length) % items.length;
    var it = items[cur];

    if (it.preview) {
      wrap.innerHTML = '<img alt="' + esc(it.name) + '" src="' + esc(it.preview) + '">';
    } else {
      wrap.innerHTML = '<span class="ph">' + esc(it.placeholder || 'Anteprima non disponibile') + '</span>';
    }

    var exifHtml = '';
    if (it.exif) {
      var parts = [];
      for (var k in it.exif) { if (Object.prototype.hasOwnProperty.call(it.exif, k)) {
        parts.push(esc(k) + ': ' + esc(it.exif[k]));
      } }
      if (parts.length) exifHtml = '<div class="exif">' + parts.join(' · ') + '</div>';
    }
    meta.innerHTML =
      '<div class="name">' + esc(it.name) + '</div>' +
      exifHtml +
      '<div><a href="' + esc(it.download) + '">⬇ Scarica full resolution</a></div>';
  }

  function open(i) { render(i); lb.hidden = false; document.body.style.overflow = 'hidden'; }
  function close() { lb.hidden = true; wrap.innerHTML = ''; document.body.style.overflow = ''; }

  document.querySelectorAll('.cell').forEach(function (el) {
    el.addEventListener('click', function () { open(parseInt(el.getAttribute('data-i'), 10) || 0); });
  });

  lb.querySelector('.lb-close').addEventListener('click', close);
  lb.querySelector('.lb-prev').addEventListener('click', function () { render(cur - 1); });
  lb.querySelector('.lb-next').addEventListener('click', function () { render(cur + 1); });
  lb.addEventListener('click', function (e) { if (e.target === lb) close(); });

  document.addEventListener('keydown', function (e) {
    if (lb.hidden) return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowLeft') render(cur - 1);
    else if (e.key === 'ArrowRight') render(cur + 1);
  });
})();
