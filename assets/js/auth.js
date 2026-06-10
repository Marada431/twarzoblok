// assets/js/auth.js

document.addEventListener('DOMContentLoaded', () => {

    // ── Przełącznik widoczności hasła ──────────────────────
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.previousElementSibling;
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🙈';
                btn.setAttribute('aria-label', 'Ukryj hasło');
            } else {
                input.type = 'password';
                btn.textContent = '👁';
                btn.setAttribute('aria-label', 'Pokaż hasło');
            }
        });
    });

    // ── Walidacja zgodności haseł w czasie rzeczywistym ────
    const passwordInput = document.getElementById('password');
    const confirmInput  = document.getElementById('password_confirm');

    if (passwordInput && confirmInput) {
        const checkMatch = () => {
            if (confirmInput.value && confirmInput.value !== passwordInput.value) {
                confirmInput.setCustomValidity('Hasła nie są identyczne.');
            } else {
                confirmInput.setCustomValidity('');
            }
        };
        confirmInput.addEventListener('input', checkMatch);
        passwordInput.addEventListener('input', checkMatch);
    }

    // ── Wskaźnik siły hasła ────────────────────────────────
    const strengthBar = document.getElementById('passwordStrength');

    if (passwordInput) {
        passwordInput.addEventListener('input', function () {
            const val  = this.value;
            const hint = this.closest('.form-group')?.querySelector('.field-hint');

            // Pasek siły
            if (strengthBar) {
                let strength = 0;
                if (val.length >= 8)          strength++;
                if (/[A-Z]/.test(val))        strength++;
                if (/[0-9]/.test(val))        strength++;
                if (/[^A-Za-z0-9]/.test(val)) strength++;

                const colors = ['', '#e53935', '#fb8c00', '#43a047', '#1e88e5'];
                const labels = ['', 'Słabe',   'Średnie', 'Dobre',   'Silne'];
                strengthBar.style.width      = (strength * 25) + '%';
                strengthBar.style.background = colors[strength] || '';
                strengthBar.title            = labels[strength] || '';
            }

            // Hint tekstowy (zachowanie istniejące)
            if (hint) {
                if (val.length === 0) {
                    hint.textContent = 'Min. 8 znaków, wielka litera, cyfra';
                    hint.style.color = '';
                    return;
                }
                const ok = val.length >= 8 && /[A-Z]/.test(val) && /[0-9]/.test(val);
                hint.textContent = ok ? '✓ Hasło spełnia wymagania' : 'Min. 8 znaków, wielka litera, cyfra';
                hint.style.color = ok ? '#338336' : '';
            }
        });
    }

    // ── Telefon: tylko cyfry, max 9 znaków ─────────────────
    const phoneInput = document.getElementById('phone');
    if (phoneInput) {
        phoneInput.addEventListener('input', () => {
            phoneInput.value = phoneInput.value.replace(/[^0-9]/g, '');
            if (phoneInput.value.length > 9) {
                phoneInput.value = phoneInput.value.slice(0, 9);
            }
        });
        phoneInput.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData)
                .getData('text')
                .replace(/[^0-9]/g, '')
                .slice(0, 9);
            phoneInput.value = pasted;
        });
    }

    // ── Kod pocztowy: auto-myślnik po 2 cyfrach ────────────
    const postalInput = document.getElementById('postal_code');
    if (postalInput) {
        postalInput.addEventListener('input', () => {
            let val = postalInput.value.replace(/[^0-9]/g, '');
            if (val.length > 2) {
                val = val.slice(0, 2) + '-' + val.slice(2, 5);
            }
            postalInput.value = val;
        });
    }

    // ── Scroll regulaminu (opcjonalnie blokuje checkbox) ───
    // Odkomentuj poniżej aby wymagać przewinięcia regulaminu:
    /*
    const termsBox      = document.getElementById('termsContent');
    const termsCheckbox = document.getElementById('terms_accepted');
    if (termsBox && termsCheckbox) {
        termsCheckbox.disabled = true;
        termsBox.addEventListener('scroll', () => {
            const scrolled = termsBox.scrollTop + termsBox.clientHeight;
            const total    = termsBox.scrollHeight;
            if (scrolled >= total - 20) {
                termsCheckbox.disabled = false;
            }
        });
    }
    */
});
