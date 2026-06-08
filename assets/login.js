/**
 * Twarzoblok - Formularz logowania
 * Automatyczne wykrywanie typu danych i walidacja
 */

document.addEventListener('DOMContentLoaded', function() {
    const loginInput = document.getElementById('login_input');
    const passwordInput = document.getElementById('password');
    const loginForm = document.getElementById('loginForm');
    const inputHint = document.getElementById('inputHint');

    // Funkcja do wykrywania typu danych
    function detectInputType(value) {
        if (!value || value.trim() === '') {
            return 'unknown';
        }

        const trimmed = value.trim();

        // Sprawdzenie czy to email
        const emailRegex = /^[^\s@]+@([^\s@.,]+\.)+[^\s@.,]{2,}$/;
        if (emailRegex.test(trimmed)) {
            return 'email';
        }

        // Sprawdzenie czy to numer telefonu (zawiera cyfry i opcjonalnie +, -, spacje, nawiasy)
        const phoneRegex = /^[\+\-\s\(\)0-9]{6,}$/;
        const digitsOnly = trimmed.replace(/[^0-9]/g, '');
        if (phoneRegex.test(trimmed) && digitsOnly.length >= 6 && digitsOnly.length <= 15) {
            return 'phone';
        }

        // Sprawdzenie czy to nazwa użytkownika (litery, cyfry, podkreślenie, 3-50 znaków)
        const usernameRegex = /^[a-zA-Z0-9_]{3,50}$/;
        if (usernameRegex.test(trimmed)) {
            return 'username';
        }

        return 'unknown';
    }

    // Aktualizacja podpowiedzi
    function updateHint() {
        const value = loginInput.value;
        const type = detectInputType(value);

        if (value.trim() === '') {
            inputHint.innerHTML = 'Możesz użyć nazwy użytkownika, adresu e-mail lub numeru telefonu';
            inputHint.style.color = 'var(--text-muted)';
        } else {
            switch(type) {
                case 'email':
                    inputHint.innerHTML = '✓ Wykryto adres e-mail';
                    inputHint.style.color = 'var(--success)';
                    break;
                case 'phone':
                    inputHint.innerHTML = '✓ Wykryto numer telefonu';
                    inputHint.style.color = 'var(--success)';
                    break;
                case 'username':
                    inputHint.innerHTML = '✓ Wykryto nazwę użytkownika';
                    inputHint.style.color = 'var(--success)';
                    break;
                default:
                    inputHint.innerHTML = '⚠ Nieprawidłowy format. Wprowadź poprawny e-mail, numer telefonu lub nazwę użytkownika';
                    inputHint.style.color = '#c62828';
            }
        }
    }

    // Walidacja przed wysłaniem
    function validateForm(event) {
        const loginValue = loginInput.value.trim();
        const passwordValue = passwordInput.value;

        if (!loginValue) {
            event.preventDefault();
            alert('Wprowadź nazwę użytkownika, e-mail lub numer telefonu');
            loginInput.focus();
            return false;
        }

        if (!passwordValue) {
            event.preventDefault();
            alert('Wprowadź hasło');
            passwordInput.focus();
            return false;
        }

        const type = detectInputType(loginValue);
        if (type === 'unknown') {
            event.preventDefault();
            alert('Wprowadź poprawny e-mail, numer telefonu (min. 6 cyfr) lub nazwę użytkownika (3-50 znaków, litery/cyfry/podkreślenie)');
            loginInput.focus();
            return false;
        }

        // Dodatkowa walidacja dla numeru telefonu
        if (type === 'phone') {
            const digitsOnly = loginValue.replace(/[^0-9]/g, '');
            if (digitsOnly.length < 6 || digitsOnly.length > 15) {
                event.preventDefault();
                alert('Numer telefonu powinien zawierać od 6 do 15 cyfr');
                loginInput.focus();
                return false;
            }
        }

        return true;
    }

    // Event listeners
    loginInput.addEventListener('input', updateHint);
    loginForm.addEventListener('submit', validateForm);

    // Walidacja przy stracie fokusu
    loginInput.addEventListener('blur', function() {
        const type = detectInputType(this.value);
        if (this.value.trim() !== '' && type === 'unknown') {
            this.style.borderColor = '#c62828';
        } else {
            this.style.borderColor = 'var(--border-color)';
        }
    });

    loginInput.addEventListener('focus', function() {
        this.style.borderColor = 'var(--primary-color)';
    });

    // Inicjalizacja
    updateHint();
});

// Obsługa ewentualnego przekazania parametrów z URL (np. po rejestracji)
const urlParams = new URLSearchParams(window.location.search);
const registered = urlParams.get('registered');
if (registered === 'success') {
    alert('Rejestracja zakończona pomyślnie! Możesz się teraz zalogować.');
}