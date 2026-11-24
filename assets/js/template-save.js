/* template-save.js — saving a template's PNG on an iPhone or iPad (Nikki, 2026-11-24).
 *
 * A web page cannot write into the Photos library, and a plain download on iOS (Chrome above all) lands in
 * Files or opens a bare preview. The nearest thing iOS allows is its own share sheet, whose "Save Image" puts
 * the picture in Photos. So on iOS the Download button fetches the PNG, then shows it with a Save to Photos
 * button: that second tap is what lets the share sheet open (the Share button tried on 2026-10-12 asked for
 * the sheet after the picture was made, by which time iOS no longer counted the tap, and it errored).
 * Touch and hold on the picture works as well. Every other browser keeps the ordinary download; so does iOS
 * when anything here fails (the form is then sent the plain way, which also shows the server's own message).
 *
 *   mhTemplateSave(form)   wire a builder form whose action answers with a PNG attachment
 */
(function () {
  var ua = navigator.userAgent || '';
  var IOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  function canShareFiles() {
    try { return !!navigator.canShare && navigator.canShare({ files: [new File([new Blob(['x'], { type: 'image/png' })], 'x.png', { type: 'image/png' })] }); }
    catch (e) { return false; }
  }
  function show(file) {
    var url = URL.createObjectURL(file), share = canShareFiles();
    var o = document.createElement('div'); o.className = 'sv-over'; o.setAttribute('role', 'dialog'); o.setAttribute('aria-label', 'Your picture is ready');
    var img = document.createElement('img'); img.src = url; img.alt = 'Your picture';
    var p = document.createElement('p');
    p.textContent = share ? 'Tap Save to Photos, then choose Save Image. You can also touch and hold the picture.'
                          : 'Touch and hold the picture, then choose Save to Photos.';
    o.appendChild(img);
    if (share) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'sp-btn'; b.textContent = 'Save to Photos';
      b.addEventListener('click', function () {
        navigator.share({ files: [file] }).catch(function (e) {
          if (e && e.name === 'AbortError') return;   // the person closed the sheet
          p.textContent = 'Touch and hold the picture, then choose Save to Photos.';
        });
      });
      o.appendChild(b);
    }
    o.appendChild(p);
    var x = document.createElement('button'); x.type = 'button'; x.className = 'sv-close'; x.textContent = 'Done';
    x.addEventListener('click', function () { o.remove(); URL.revokeObjectURL(url); });
    o.appendChild(x);
    document.body.appendChild(o);
  }
  window.mhTemplateSave = function (form) {
    if (!IOS || !form || !window.fetch || !window.File || form.dataset.mhSave) return;
    form.dataset.mhSave = '1';
    var busy = false;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;   // one picture at a time
      busy = true;
      var btns = document.querySelectorAll('button[type="submit"][form="' + form.id + '"], #' + form.id + ' button[type="submit"]');
      var words = [];
      btns.forEach(function (b, i) { words[i] = b.textContent; b.disabled = true; b.textContent = 'Making your picture…'; });
      function done() { busy = false; btns.forEach(function (b, i) { b.disabled = false; b.textContent = words[i]; }); }
      fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' }).then(function (r) {
        if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('image/png') !== 0) throw new Error('not a picture');
        var m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
        return r.blob().then(function (blob) { done(); show(new File([blob], m ? m[1] : 'mont-haus.png', { type: 'image/png' })); });
      }).catch(function () { done(); HTMLFormElement.prototype.submit.call(form); });
    });
  };
})();
