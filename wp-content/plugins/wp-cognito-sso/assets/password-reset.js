(function () {
    function setMessage(form, text, isError) {
        var existing = form.parentNode.querySelector('.wcsso-password-reset-message');
        if (!existing) {
            existing = document.createElement('p');
            existing.className = 'wcsso-password-reset-message';
            form.parentNode.insertBefore(existing, form);
        }

        existing.textContent = text;
        existing.classList.toggle('wcsso-password-reset-error', !!isError);
        existing.classList.toggle('wcsso-password-reset-success', !isError);
    }

    function onSubmit(event) {
        var form = event.target;
        if (!form.matches('[data-wcsso-password-reset-form]')) {
            return;
        }

        event.preventDefault();

        var button = form.querySelector('[type="submit"]');
        var originalText = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = wcssoPasswordReset.workingText || 'Updating password...';
        }

        fetch(wcssoPasswordReset.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: new FormData(form)
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                var message = payload && payload.data && payload.data.message
                    ? payload.data.message
                    : 'Unable to update your password.';

                setMessage(form, message, !payload.success);

                if (payload.success) {
                    form.reset();
                }
            })
            .catch(function () {
                setMessage(form, 'Unable to update your password. Please try again.', true);
            })
            .finally(function () {
                if (button) {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            });
    }

    document.addEventListener('submit', onSubmit);
})();
