/**
 * Currency switch on the pricing cards.
 *
 * Every price is rendered server-side from the same catalogue billing reads, and
 * the inactive ones are simply hidden. Nothing is converted in the browser: an
 * exchange rate calculated on the client is a price nobody can be held to.
 */
(function () {
    'use strict';

    var buttons = document.querySelectorAll('.m-currency button[data-currency]');

    if (buttons.length === 0) {
        return;
    }

    function show(currency) {
        var prices = document.querySelectorAll('.m-plan__amount[data-price]');

        for (var i = 0; i < prices.length; i++) {
            prices[i].hidden = prices[i].getAttribute('data-price') !== currency;
        }

        for (var j = 0; j < buttons.length; j++) {
            buttons[j].setAttribute(
                'aria-pressed',
                buttons[j].getAttribute('data-currency') === currency ? 'true' : 'false'
            );
        }
    }

    for (var k = 0; k < buttons.length; k++) {
        buttons[k].addEventListener('click', function () {
            show(this.getAttribute('data-currency'));
        });
    }
})();
