/**
 * Show/hide for password fields.
 *
 * Progressive enhancement on purpose: the button is created here rather than
 * written into the HTML, so a browser with JavaScript off shows an ordinary
 * password field instead of a dead control that does nothing when pressed.
 */
(function () {
    'use strict';

    function label(shown) {
        return shown ? 'Hide' : 'Show';
    }

    function enhance(input) {
        if (input.dataset.toggleReady === '1') {
            return;
        }

        input.dataset.toggleReady = '1';

        var wrap = document.createElement('span');
        wrap.className = 'password-field';

        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-toggle';
        button.textContent = label(false);
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('aria-controls', input.id || '');

        button.addEventListener('click', function () {
            var shown = input.type === 'text';

            // Caret position survives the type change in most browsers, but
            // restoring it explicitly means the field is still usable if you
            // reveal the password halfway through typing it.
            var start = input.selectionStart;
            var end   = input.selectionEnd;

            input.type = shown ? 'password' : 'text';
            button.textContent = label(!shown);
            button.setAttribute('aria-pressed', shown ? 'false' : 'true');
            button.setAttribute('aria-label', (shown ? 'Show' : 'Hide') + ' password');

            try {
                input.setSelectionRange(start, end);
            } catch (e) {
                // Some browsers refuse setSelectionRange on a password input.
            }

            input.focus();
        });

        wrap.appendChild(button);

        // Re-hide on submit. Without this the password stays on screen after
        // sign-in, which matters on a shared screen or in a screenshot.
        var form = input.form;

        if (form) {
            form.addEventListener('submit', function () {
                input.type = 'password';
                button.textContent = label(false);
                button.setAttribute('aria-pressed', 'false');
                button.setAttribute('aria-label', 'Show password');
            });
        }
    }

    function init() {
        var inputs = document.querySelectorAll('input[type="password"]');

        for (var i = 0; i < inputs.length; i++) {
            enhance(inputs[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
