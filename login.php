<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Family Tree — Sign in</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__.'/style.css') ?>">
</head>
<body class="login-mode">

<div id="login-wrap">
  <div id="login-card">
    <h1>Family Tree</h1>
    <p class="login-sub">Enter your email to receive a sign-in link</p>

    <div id="step-email">
      <label for="login-email">Email address</label>
      <input type="email" id="login-email" autocomplete="email" placeholder="you@example.com" spellcheck="false" autofocus>
      <button id="btn-send" type="button">Send sign-in link</button>
      <div id="login-error" class="login-error" hidden></div>
    </div>

    <div id="step-code" hidden>
      <p class="login-hint">Check your email for the 3-digit code, or click the link in the email.</p>
      <label for="login-code">3-digit code</label>
      <input type="text" id="login-code" inputmode="numeric" pattern="[0-9]{3}"
             maxlength="3" placeholder="000" autocomplete="one-time-code">
      <button id="btn-verify" type="button">Verify code</button>
      <button id="btn-back" class="btn-ghost" type="button">Use a different email</button>
      <div id="login-error-code" class="login-error" hidden></div>
    </div>

    <div id="step-success" hidden>
      <p class="login-hint">Signed in. Loading…</p>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var SESSION_KEY = 'familytree:session';

  var elEmail      = document.getElementById('login-email');
  var elCode       = document.getElementById('login-code');
  var btnSend      = document.getElementById('btn-send');
  var btnVerify    = document.getElementById('btn-verify');
  var btnBack      = document.getElementById('btn-back');
  var stepEmail    = document.getElementById('step-email');
  var stepCode     = document.getElementById('step-code');
  var stepSuccess  = document.getElementById('step-success');
  var errEmail     = document.getElementById('login-error');
  var errCode      = document.getElementById('login-error-code');

  function showErr(el, msg) { el.textContent = msg; el.hidden = false; }
  function clearErr(el)     { el.hidden = true; el.textContent = ''; }

  function setLoading(btn, isLoading, label) {
    btn.disabled = isLoading;
    btn.textContent = isLoading ? 'Please wait…' : label;
  }

  btnSend.addEventListener('click', function () {
    var email = elEmail.value.trim();
    clearErr(errEmail);
    if (!email) { showErr(errEmail, 'Please enter your email address.'); return; }

    setLoading(btnSend, true, 'Send sign-in link');

    fetch('auth.php?action=request', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email }),
    })
    .then(function (res) {
      setLoading(btnSend, false, 'Send sign-in link');
      if (res.status === 429) {
        return res.json().then(function (d) {
          var wait = d.retry_after ? Math.ceil(d.retry_after / 60) : 10;
          showErr(errEmail, 'Too many requests — please wait ' + wait + ' minutes and try again.');
        });
      }
      // Always show the code step (we don't reveal whether the email is valid)
      stepEmail.hidden = true;
      stepCode.hidden  = false;
      elCode.focus();
    })
    .catch(function () {
      setLoading(btnSend, false, 'Send sign-in link');
      showErr(errEmail, 'Something went wrong. Please try again.');
    });
  });

  elEmail.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') btnSend.click();
  });

  btnVerify.addEventListener('click', function () {
    var code = elCode.value.replace(/\D/g, '');
    clearErr(errCode);
    if (code.length !== 3) { showErr(errCode, 'Please enter the 3-digit code from your email.'); return; }

    setLoading(btnVerify, true, 'Verify code');

    fetch('auth.php?action=verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: code }),
    })
    .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, d: d }; }); })
    .then(function (r) {
      setLoading(btnVerify, false, 'Verify code');
      if (!r.ok) {
        showErr(errCode, 'Invalid or expired code — please check your email and try again.');
        elCode.value = '';
        elCode.focus();
        return;
      }
      try { localStorage.setItem(SESSION_KEY, r.d.session); } catch (e) {}
      stepCode.hidden    = true;
      stepSuccess.hidden = false;
      window.location.replace('./');
    })
    .catch(function () {
      setLoading(btnVerify, false, 'Verify code');
      showErr(errCode, 'Something went wrong. Please try again.');
    });
  });

  // Auto-submit when all 3 digits are entered
  elCode.addEventListener('input', function () {
    if (this.value.replace(/\D/g, '').length === 3) btnVerify.click();
  });

  elCode.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') btnVerify.click();
  });

  btnBack.addEventListener('click', function () {
    stepCode.hidden  = true;
    stepEmail.hidden = false;
    elEmail.focus();
    clearErr(errCode);
  });
}());
</script>
</body>
</html>
