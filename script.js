// Mobile-Navigation
document.addEventListener('DOMContentLoaded', function () {
  var burger = document.querySelector('.burger');
  if (burger) {
    burger.addEventListener('click', function () {
      document.body.classList.toggle('nav-open');
    });
    document.querySelectorAll('.nav-links a').forEach(function (a) {
      a.addEventListener('click', function () {
        document.body.classList.remove('nav-open');
      });
    });
  }

  // Rotierendes Captcha-Wort (serverseitig gegen Whitelist geprüft)
  var words = ['KUPFER', 'ISAR', 'FRAUENKIRCHE', 'MARIENPLATZ', 'AUDIT', 'BEZIRK', 'GIESING', 'SCHWABING', 'ENGLISCHERGARTEN', 'RATHAUS', 'AUER', 'WIESN'];
  var wordEl = document.getElementById('captcha-word');
  var answerEl = document.getElementById('captcha-answer');
  if (wordEl && answerEl) {
    var word = words[Math.floor(Math.random() * words.length)];
    wordEl.textContent = word;
    answerEl.value = word;
  }

  // Statusmeldung nach Formularversand
  var params = new URLSearchParams(window.location.search);
  var status = params.get('status');
  var banner = document.getElementById('form-banner');
  if (banner && status) {
    if (status === 'success') {
      banner.textContent = 'Vielen Dank für Ihre Anfrage! Wir melden uns in Kürze bei Ihnen.';
      banner.className = 'banner banner-ok';
      banner.style.display = 'block';
    } else if (status === 'captcha') {
      banner.textContent = 'Das eingegebene Wort war leider nicht korrekt. Bitte versuchen Sie es erneut.';
      banner.className = 'banner banner-err';
      banner.style.display = 'block';
    } else if (status === 'error') {
      banner.textContent = 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut oder schreiben Sie uns direkt eine E-Mail.';
      banner.className = 'banner banner-err';
      banner.style.display = 'block';
    }
  }
});
