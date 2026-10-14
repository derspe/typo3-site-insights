/*
 * Redirect target of the Google consent screen (opened as popup by the Site Insights
 * settings). Hands code and state to the backend window through localStorage (same
 * origin only) and removes them from the address bar. The backend module picks them up,
 * removes them and completes the connection through the authenticated backend API.
 *
 * localStorage is used instead of window.opener, because Google's consent page sets a
 * Cross-Origin-Opener-Policy that disconnects the popup from the window that opened it.
 */
(function () {
  'use strict';
  var params = new URLSearchParams(window.location.search);
  var message = document.getElementById('message');
  window.history.replaceState(null, '', window.location.pathname);

  if (!params.get('state')) {
    message.textContent = 'Please start the connection from the Site Insights settings in the TYPO3 backend.';
    return;
  }
  try {
    window.localStorage.setItem('derspe-site-insights-oauth', JSON.stringify({
      code: params.get('code') || '',
      state: params.get('state') || '',
      error: params.get('error') || '',
      time: Date.now()
    }));
  } catch (e) {
    message.textContent = 'The result could not be handed over (storage blocked). Please allow site data for the TYPO3 backend and try again.';
    return;
  }
  message.textContent = 'Done – the connection is completed in the TYPO3 backend. This window closes automatically.';
  window.setTimeout(function () { window.close(); }, 1000);
})();
