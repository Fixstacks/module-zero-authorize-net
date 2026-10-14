/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Authorize.Net Accept.js ("Custom Checkout Form") for Zero Checkout — plain JS.
 * Reads the card inputs (which have no `name`, so raw card data is never posted),
 * exchanges them via Accept.dispatchData() for an opaqueData token, fills the two
 * hidden fields and resubmits; Model\Standard::capture() charges with the token.
 * Config (API Login ID + Client Key, both public) is inlined by the template, so
 * nothing needs fetching or mounting.
 */
(function () {
    'use strict';

    // Settings rendered by the template as JSON (no inline script: strict CSP).
    var settingsEl = document.getElementById('zero-authorizenet-config');
    var settings = settingsEl ? JSON.parse(settingsEl.textContent) : {};

    var form = document.getElementById('checkout-form');
    var cardFields = document.getElementById('authorizenet-card-fields');
    if (!form || !cardFields || !settings.clientKey || !window.Accept) {
        return;
    }

    var errorBox = document.getElementById('authorizenet-error');
    var descriptorInput = document.getElementById('authnet_data_descriptor');
    var valueInput = document.getElementById('authnet_data_value');
    var submitButton = form.querySelector('button[type="submit"]');
    var numberField = document.getElementById('authnet-cc-number');
    var expirationField = document.getElementById('authnet-cc-expiration');
    var cvvField = document.getElementById('authnet-cc-cvv');

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    }

    function clearError() {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    function isAuthorizeNetChecked() {
        var checked = form.querySelector('input[name="payment_method"]:checked');
        return !!checked && checked.value === 'zero_authorizenet';
    }

    /**
     * Splits the single "MM/YYYY" field into the month/year Accept.js expects.
     *
     * @return {{month: string, year: string}|null}
     */
    function parseExpiration(value) {
        var match = /^\s*(\d{1,2})\s*\/\s*(\d{4})\s*$/.exec(value || '');
        if (!match) {
            return null;
        }
        return { month: match[1].padStart(2, '0'), year: match[2] };
    }

    function responseHandler(response) {
        if (response.messages.resultCode === 'Error') {
            var messages = response.messages.message || [];
            var text = messages.length > 0 ? messages[0].text : 'We couldn’t process that card.';
            showError(text);
            if (submitButton) {
                submitButton.disabled = false;
            }
            return;
        }

        descriptorInput.value = response.opaqueData.dataDescriptor;
        valueInput.value = response.opaqueData.dataValue;
        form.requestSubmit();
    }

    form.addEventListener('submit', function (event) {
        if (!isAuthorizeNetChecked()) {
            return;
        }

        // Second pass: requestSubmit() in responseHandler re-enters this handler.
        if (descriptorInput.value) {
            return;
        }

        var expiration = parseExpiration(expirationField.value);
        if (!numberField.value || !expiration || !cvvField.value) {
            event.preventDefault();
            showError('Please fill in your card number, expiration date, and CVV.');
            return;
        }

        event.preventDefault();
        clearError();
        if (submitButton) {
            submitButton.disabled = true;
        }

        window.Accept.dispatchData(
            {
                authData: {
                    clientKey: settings.clientKey,
                    apiLoginID: settings.apiLoginId
                },
                cardData: {
                    cardNumber: numberField.value.replace(/\s+/g, ''),
                    month: expiration.month,
                    year: expiration.year,
                    cardCode: cvvField.value
                }
            },
            responseHandler
        );
    });
})();
