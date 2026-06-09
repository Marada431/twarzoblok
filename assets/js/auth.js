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

    // ── Walidacja siły hasła w czasie rzeczywistym ─────────
    if (passwordInput) {
        passwordInput.addEventListener('input', function () {
            const val = this.value;
            const hint = this.closest('.form-group')?.querySelector('.field-hint');
            if (!hint) return;
            if (val.length === 0) {
                hint.textContent = 'Min. 8 znaków, wielka litera, cyfra';
                hint.style.color = '';
                return;
            }
            const ok = val.length >= 8 && /[A-Z]/.test(val) && /[0-9]/.test(val);
            hint.textContent = ok ? '✓ Hasło spełnia wymagania' : 'Min. 8 znaków, wielka litera, cyfra';
            hint.style.color = ok ? '#338336' : '';
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
