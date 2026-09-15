/*
 * Website tracking snippet.
 *
 * Paste-once, then forget. It records page views and whatever else the site
 * chooses to report, against a random id the visitor's own browser generated.
 *
 * What it deliberately does NOT do:
 *
 *  - It does not fingerprint. The id is random, stored in this browser, and
 *    cleared when the visitor clears their storage. There is no canvas hash, no
 *    font enumeration, nothing assembled behind their back.
 *  - It does not read cookies it did not set, or touch any other script's data.
 *  - It does not send the query string. Real websites put session tokens,
 *    password reset links and email addresses in query strings, and none of that
 *    belongs in an analytics table.
 *  - It does not identify anybody. A visitor becomes a person only when they
 *    click a link in an email or fill in a form — something they chose to do.
 *
 * Usage:
 *   <script src="https://app.example.com/assets/js/track.js"
 *           data-key="pk_live_..." data-endpoint="https://app.example.com"></script>
 *   <script>growth.track('service_view', { service: 'boiler-repair' });</script>
 */
(function (window, document) {
  'use strict';

  var script   = document.currentScript;
  var key      = script ? script.getAttribute('data-key') : '';
  var endpoint = (script && script.getAttribute('data-endpoint')) || '';

  if (!key) { return; }

  var STORAGE_KEY = '_growth_id';
  var SESSION_KEY = '_growth_session';

  /* A random id, this browser's own. Never derived from anything about them. */
  function randomId() {
    if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }

    return 'a' + Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  function stored(store, name) {
    try {
      var value = store.getItem(name);
      if (!value) { value = randomId(); store.setItem(name, value); }
      return value;
    } catch (e) {
      // Private browsing, or storage switched off. Still track the page view,
      // just without continuity — which is the visitor's choice to make.
      return randomId();
    }
  }

  var anonymousId = stored(window.localStorage, STORAGE_KEY);
  var sessionId   = stored(window.sessionStorage, SESSION_KEY);

  function pathOnly(url) {
    try {
      var parsed = new URL(url, window.location.href);
      return parsed.origin + parsed.pathname;
    } catch (e) {
      return '';
    }
  }

  function utm(name) {
    try {
      return new URL(window.location.href).searchParams.get(name) || undefined;
    } catch (e) {
      return undefined;
    }
  }

  function send(eventName, properties) {
    var payload = {
      event_name:   eventName,
      anonymous_id: anonymousId,
      session_id:   sessionId,
      page_url:     pathOnly(window.location.href),
      referrer:     document.referrer ? pathOnly(document.referrer) : undefined,
      properties:   properties || undefined,
      utm_source:   utm('utm_source'),
      utm_medium:   utm('utm_medium'),
      utm_campaign: utm('utm_campaign')
    };

    var body = JSON.stringify(payload);

    // sendBeacon survives the page being closed, which is exactly when the last
    // and most interesting event usually fires.
    if (navigator.sendBeacon && eventName !== 'identify') {
      try {
        navigator.sendBeacon(
          endpoint + '/api/v1/events?key=' + encodeURIComponent(key),
          new Blob([body], { type: 'application/json' })
        );
        return;
      } catch (e) { /* fall through to fetch */ }
    }

    fetch(endpoint + '/api/v1/events', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + key },
      body: body,
      keepalive: true,
      mode: 'cors'
    }).catch(function () { /* never break the host page over analytics */ });
  }

  window.growth = {
    track: send,

    /*
     * Called by the site when somebody identifies themselves — after a form
     * submission, say. The contact's uuid comes from a link we generated, so a
     * visitor cannot claim to be somebody else by editing it.
     */
    identify: function (contactUuid, properties) {
      send('identify', Object.assign({ contact_uuid: contactUuid }, properties || {}));
    }
  };

  send('page_view');
})(window, document);
