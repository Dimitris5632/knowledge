<ul class="password-rules" id="password-rules">
    <?php foreach (password_rules() as $key => $label): ?>
        <li data-rule="<?= $key ?>"><?= e($label) ?></li>
    <?php endforeach; ?>
    <li data-rule="match">Οι δύο κωδικοί είναι ίδιοι</li>
</ul>

<script>
(function () {
    const password  = document.querySelector('input[name="password"]');
    const password2 = document.querySelector('input[name="password2"]');
    const items     = document.querySelectorAll('#password-rules li');
    const commonWords = <?= json_encode(array_map('normalize_answer', common_password_words())) ?>;

    const tests = {
        length:  function (p) { return p.length >= 8; },
        upper:   function (p) { return /\p{Lu}/u.test(p); },
        lower:   function (p) { return /\p{Ll}/u.test(p); },
        digit:   function (p) { return /[0-9]/.test(p); },
        special: function (p) { return /[^\p{L}0-9\s]/u.test(p); },
        common:  function (p) {
            const letters = p.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                             .replace(/ς/g, 'σ').replace(/[^\p{L}]/gu, '');
            return letters !== '' && !commonWords.includes(letters);
        },
        match:   function (p) { return p !== '' && p === password2.value; }
    };

    const counter = document.getElementById('pw-count');
    const userBox = document.querySelector('input[name="username"]');
    const accountName = <?= json_encode($account['username'] ?? $user['username'] ?? '') ?>;

    // Μετρητής ισχύος: μπαίνει κάτω από το πεδίο του κωδικού
    const meter = document.createElement('div');
    meter.className = 'pw-strength';
    meter.setAttribute('aria-live', 'polite');
    meter.innerHTML = '<div class="pw-bar"><span></span></div><small class="pw-label">Ισχύς κωδικού: –</small>';
    (counter ? counter.parentNode : password).insertAdjacentElement('afterend', meter);
    const bar   = meter.querySelector('.pw-bar span');
    const label = meter.querySelector('.pw-label');

    function strength(p) {
        if (p === '') { return null; }
        const required = ['length', 'upper', 'lower', 'digit', 'special', 'common'];
        const passed = required.filter(function (r) { return tests[r](p); }).length;
        const uname = (userBox ? userBox.value : accountName).trim().toLowerCase();
        const hasName = uname.length >= 3 && p.toLowerCase().includes(uname);

        let score = passed;                                   // 0-6 από τους κανόνες
        if (p.length >= 12) { score++; }                      // μεγάλος κωδικός = πολύ πιο δύσκολος
        if (p.length >= 16) { score++; }
        if (/(.)\1\1/.test(p)) { score--; }                   // aaa, 111
        if (/(0123|1234|2345|3456|4567|5678|6789|abcd|bcde|qwer|asdf)/i.test(p)) { score--; }

        if (passed < 6 || hasName) {
            return { level: 1, text: hasName ? 'Αδύναμος: περιέχει το username σου' : 'Αδύναμος' };
        }
        if (score >= 8) { return { level: 4, text: 'Πολύ ισχυρός 💪' }; }
        if (score >= 7) { return { level: 3, text: 'Ισχυρός' }; }
        return { level: 2, text: 'Μέτριος: μεγάλωσέ τον στους 12+ χαρακτήρες' };
    }

    function showStrength() {
        const s = strength(password.value);
        meter.className = 'pw-strength' + (s ? ' level-' + s.level : '');
        bar.style.width = s ? (s.level * 25) + '%' : '0';
        label.textContent = 'Ισχύς κωδικού: ' + (s ? s.text : '–');
    }

    function update() {
        if (counter) {
            counter.textContent = password.value.length + ' / 8';
            counter.classList.toggle('ok', password.value.length >= 8);
        }
        items.forEach(function (item) {
            const ok = tests[item.dataset.rule](password.value);
            item.classList.toggle('ok', ok);
        });
        showStrength();
    }

    password.addEventListener('input', update);
    password2.addEventListener('input', update);
    if (userBox) { userBox.addEventListener('input', showStrength); }
})();
</script>
