/*
 * Job application page (apply.php): reads the resume as soon as it is chosen, fills in the form, highlights what was
 * filled, shows how well the skills match the role, validates before submit. The page works without this script.
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-apply-form]');
  wireCopy();
  var done = document.getElementById('applyDone');
  if (done) done.focus();
  if (!form) return;
  form.classList.add('is-js');

  var drop = form.querySelector('[data-drop]');
  var file = form.querySelector('#resume');
  var note = form.querySelector('[data-parse-note]');
  var maxMb = parseFloat(form.getAttribute('data-max-mb')) || 5;
  var jobSkills = [];
  try { jobSkills = JSON.parse(form.getAttribute('data-job-skills') || '[]'); } catch (e) { /* none */ }
  var FIELDS = ['name', 'email', 'phone', 'current_location', 'linkedin_url', 'designation', 'current_employer', 'work_exp', 'notice_period', 'current_ctc', 'expected_ctc', 'education', 'key_skills'];
  var LABELS = { name: 'name', email: 'e-mail', phone: 'mobile', current_location: 'city', linkedin_url: 'LinkedIn', designation: 'designation', current_employer: 'company',
    work_exp: 'experience', notice_period: 'notice period', current_ctc: 'current CTC', expected_ctc: 'expected CTC', education: 'qualification', key_skills: 'skills' };
  var edited = {};   // fields the applicant typed in: never overwritten by a later resume
  var busy = false;

  function el(name) { return form.elements[name]; }
  function state(name) {
    drop.querySelectorAll('[data-state]').forEach(function (s) { s.hidden = s.getAttribute('data-state') !== name; });
    drop.classList.toggle('is-busy', name === 'busy');
    drop.classList.toggle('is-done', name === 'done');
  }
  function showNote(kind, icon, html) {
    note.className = 'apx-parse-note ' + kind;
    note.innerHTML = '<i class="' + icon + '"></i><div>' + html + '</div>';
    note.hidden = false;
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function kb(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  FIELDS.forEach(function (n) {
    var f = el(n);
    if (!f) return;
    f.addEventListener('input', function () {
      edited[n] = true;
      var wrap = f.closest('.ap-field');
      if (wrap && wrap.classList.contains('is-auto')) { wrap.classList.remove('is-auto'); var b = wrap.querySelector('.af-badge'); if (b) b.remove(); }
      clearErr(n);
    });
    if (f.value) edited[n] = true; // values kept after a failed submit belong to the applicant
  });

  /* ── Choosing / dropping the file ── */
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) {
    if (!e.dataTransfer || !e.dataTransfer.files.length) return;
    try { file.files = e.dataTransfer.files; } catch (x) { /* old browsers: read it anyway */ }
    read(e.dataTransfer.files[0]);
  });
  drop.addEventListener('click', function (e) { if (busy) e.preventDefault(); });
  file.addEventListener('change', function () { if (file.files.length) read(file.files[0]); });

  function read(f, retried) {
    clearErr('resume');
    var ext = (f.name.split('.').pop() || '').toLowerCase();
    if (['pdf', 'doc', 'docx'].indexOf(ext) < 0) return fail('Please choose a PDF, DOC or DOCX file.');
    if (f.size > maxMb * 1048576) return fail('This file is ' + kb(f.size) + '. Please choose a file under ' + maxMb + ' MB.');
    busy = true;
    state('busy');
    note.hidden = true;
    drop.querySelector('[data-busy-file]').textContent = f.name + ' · ' + kb(f.size);
    var bar = drop.querySelector('.apx-progress i');
    bar.style.width = '0';
    var fd = new FormData();
    fd.append('_token', el('_token').value);
    fd.append('resume', f);
    var xhr = new XMLHttpRequest();
    var creep;
    xhr.open('POST', form.getAttribute('data-parse-url'));
    xhr.setRequestHeader('X-Requested-With', 'fetch');
    xhr.upload.onprogress = function (e) { if (e.lengthComputable) bar.style.width = Math.round(e.loaded / e.total * 60) + '%'; };
    xhr.upload.onload = function () { // uploaded: the server is reading it now
      var w = 60;
      creep = setInterval(function () { w = Math.min(95, w + (95 - w) * 0.12); bar.style.width = w + '%'; }, 300);
    };
    xhr.onload = function () {
      clearInterval(creep);
      busy = false;
      var r = null;
      try { r = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
      if (r && r.token) {
        el('_token').value = r.token;
        if (!retried && xhr.status === 403) return read(f, true); // session expired: once more with the fresh token
      }
      if (!r || !r.ok) return fail((r && r.error) || 'We could not upload your resume. Please try again, or fill in the form yourself.');
      bar.style.width = '100%';
      // The server keeps this file for the application, so the form no longer needs to send it again.
      file.removeAttribute('name');
      file.removeAttribute('required');
      drop.querySelector('[data-file-name]').textContent = r.file.name;
      drop.querySelector('[data-file-note]').textContent = 'Attached · ' + kb(r.file.size);
      state('done');
      var filled = fill(r.fields || {});
      if (!r.read) {
        showNote('warn', 'ri-information-line', '<strong>Resume attached.</strong> We could not read text from this file (it may be a scanned image), so please fill in your details below.');
      } else if (filled.length) {
        showNote('ok', 'ri-magic-line', '<strong>We filled in ' + filled.length + ' field' + (filled.length > 1 ? 's' : '') + ' from your resume</strong> (' + esc(filled.map(function (n) { return LABELS[n]; }).join(', ')) + '). Please check them and complete anything missing.');
        var first = firstEmptyRequired();
        if (first) setTimeout(function () { first.focus({ preventScroll: false }); }, 400);
      } else {
        showNote('warn', 'ri-information-line', '<strong>Resume attached.</strong> We could not find your details in it automatically, so please fill in the form below.');
      }
      updateMatch();
    };
    xhr.onerror = function () { clearInterval(creep); busy = false; fail('Network problem while uploading. Please check your connection and try again.'); };
    xhr.send(fd);
  }

  function fail(msg) {
    busy = false;
    file.value = '';
    file.setAttribute('name', 'resume');
    state(file.hasAttribute('data-has-parsed') ? 'done' : 'empty');
    showNote('err', 'ri-error-warning-line', esc(msg));
  }

  function fill(fields) {
    var filled = [];
    FIELDS.forEach(function (n) {
      var v = fields[n], f = el(n);
      if (v === undefined || v === null || v === '' || !f || edited[n]) return;
      v = String(v);
      if (f.tagName === 'SELECT') {
        var ok = [].some.call(f.options, function (o) { return o.value === v || o.text === v; });
        if (!ok) return;
      }
      if (n === 'work_exp' && isNaN(parseFloat(v))) return;
      f.value = n === 'work_exp' ? String(Math.round(parseFloat(v) * 2) / 2) : v;
      var wrap = f.closest('.ap-field');
      if (wrap) {
        wrap.classList.add('is-auto', 'flash');
        setTimeout(function () { wrap.classList.remove('flash'); }, 1300);
        var label = wrap.querySelector('.form-label');
        if (label && !label.querySelector('.af-badge')) label.insertAdjacentHTML('beforeend', '<span class="af-badge"><i class="ri-magic-line"></i> from resume</span>');
      }
      clearErr(n);
      filled.push(n);
    });
    syncSuggest();
    return filled;
  }

  function firstEmptyRequired() {
    return [].find.call(form.querySelectorAll('[required]'), function (f) { return f.name && f.type !== 'checkbox' && f.type !== 'file' && !f.value.trim(); });
  }

  /* ── Skills: suggestions + match meter ── */
  var skillsBox = el('key_skills');
  function skillList() { return (skillsBox.value || '').split(/[,;\n|]+/).map(function (s) { return s.trim(); }).filter(Boolean); }
  function has(s) { var l = s.toLowerCase(); return skillList().some(function (x) { return x.toLowerCase() === l; }) || new RegExp('(^|[^a-z0-9+#])' + l.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '($|[^a-z0-9+#])', 'i').test(skillsBox.value); }
  function syncSuggest() {
    form.querySelectorAll('[data-skill]').forEach(function (b) { var on = has(b.getAttribute('data-skill')); b.classList.toggle('added', on); b.disabled = on; });
  }
  form.querySelectorAll('[data-skill]').forEach(function (b) {
    b.addEventListener('click', function () {
      var s = b.getAttribute('data-skill');
      if (has(s)) return;
      skillsBox.value = skillList().concat([s]).join(', ');
      edited.key_skills = true;
      clearErr('key_skills');
      syncSuggest();
      updateMatch();
    });
  });
  var matchBox = form.querySelector('[data-match]');
  function updateMatch() {
    if (!matchBox || !jobSkills.length) return;
    var hit = jobSkills.filter(has).length;
    matchBox.hidden = !skillsBox.value.trim();
    var pct = Math.round(hit / jobSkills.length * 100);
    matchBox.querySelector('.apx-match-bar i').style.width = pct + '%';
    matchBox.querySelector('[data-match-text]').innerHTML = 'Your skills match <strong>' + hit + ' of ' + jobSkills.length + '</strong> listed for this role' + (hit < jobSkills.length ? ' — add any you have' : ' 🎉');
  }
  skillsBox.addEventListener('input', function () { syncSuggest(); updateMatch(); });
  syncSuggest();
  updateMatch();

  /* ── Character counter ── */
  form.querySelectorAll('[data-count]').forEach(function (t) {
    var out = t.parentNode.querySelector('.ap-count');
    var max = +t.getAttribute('maxlength');
    function upd() { out.textContent = t.value.length ? t.value.length + ' / ' + max : ''; }
    t.addEventListener('input', upd); upd();
  });

  /* ── Validation & submit ── */
  function setErr(name, msg) {
    var box = form.querySelector('[data-err="' + name + '"]');
    if (box) box.textContent = msg;
    var f = name === 'resume' ? drop : (name === 'consent' ? form.querySelector('.ap-consent') : el(name));
    if (f && f.classList) f.classList.add('is-invalid');
  }
  function clearErr(name) {
    var box = form.querySelector('[data-err="' + name + '"]');
    if (box) box.textContent = '';
    var f = name === 'resume' ? drop : (name === 'consent' ? form.querySelector('.ap-consent') : el(name));
    if (f && f.classList) f.classList.remove('is-invalid');
  }
  el('consent').addEventListener('change', function () { clearErr('consent'); });

  form.addEventListener('submit', function (e) {
    if (busy) { e.preventDefault(); return; }
    var bad = [];
    var v = function (n) { return (el(n).value || '').trim(); };
    if (v('name').length < 2) { setErr('name', 'Enter your full name.'); bad.push('name'); }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v('email'))) { setErr('email', 'Enter a valid e-mail address.'); bad.push('email'); }
    if (v('phone').replace(/\D/g, '').length < 10) { setErr('phone', 'Enter a valid 10-digit mobile number.'); bad.push('phone'); }
    var ex = v('work_exp');
    if (ex !== '' && (isNaN(+ex) || +ex < 0 || +ex > 50)) { setErr('work_exp', 'Experience must be a number of years.'); bad.push('work_exp'); }
    if (v('key_skills').length < 2) { setErr('key_skills', 'List your key skills.'); bad.push('key_skills'); }
    if (!el('consent').checked) { setErr('consent', 'Please agree to the processing of your application.'); bad.push('consent'); }
    var hasFile = (file.getAttribute('name') && file.files.length) || drop.classList.contains('is-done');
    if (!hasFile) { setErr('resume', 'Upload your resume (PDF or Word).'); bad.push('resume'); }
    var alert = form.querySelector('[data-alert]');
    if (bad.length) {
      e.preventDefault();
      alert.querySelector('span').textContent = bad.length === 1 ? 'Please fix the highlighted field.' : 'Please fix the ' + bad.length + ' highlighted fields.';
      alert.hidden = false;
      var first = bad[0] === 'resume' ? drop : (bad[0] === 'consent' ? el('consent') : el(bad[0]));
      first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (first.focus) setTimeout(function () { first.focus({ preventScroll: true }); }, 300);
      return;
    }
    var btn = form.querySelector('[data-submit]');
    btn.classList.add('loading');
    btn.querySelector('span').textContent = 'Sending…';
  });

  // Server-side errors: bring the first one into view.
  var firstErr = [].find.call(form.querySelectorAll('.ap-err'), function (x) { return x.textContent.trim(); });
  if (firstErr) firstErr.closest('.ap-field, .apx-section').scrollIntoView({ block: 'center' });

  function wireCopy() {
    document.querySelectorAll('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        var t = b.getAttribute('data-copy');
        var ok = function () { var h = b.innerHTML; b.innerHTML = '<i class="ri-check-line"></i> Copied'; setTimeout(function () { b.innerHTML = h; }, 1600); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(ok);
        else { var i = document.createElement('textarea'); i.value = t; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); ok(); } catch (x) { /* ignore */ } i.remove(); }
      });
    });
  }
})();
