/**
 * AJAX filtering for the AI Bridge backend modules (assistant log, bot access
 * log). Turns the filter form and its pagination into in-place updates so the
 * module is never fully reloaded — which also avoids TYPO3 re-rendering the
 * module doc-header on a POST submit (the "duplicated header" issue).
 *
 * Progressive enhancement: without this module the forms still submit normally.
 *
 * Convention per module root (`.wn-ai-log`):
 *   - `[data-wn-ai-filter-form]`     the filter <form>
 *   - `[data-wn-ai-filter-results]`  the container replaced on every request
 *   - `[data-wn-ai-filter-reset]`    optional reset link
 *   - `a[data-wn-ai-page]`           pagination links inside the results
 */
class AjaxFilter {
  constructor(root) {
    this.root = root;
    this.form = root.querySelector('[data-wn-ai-filter-form]');
    this.results = root.querySelector('[data-wn-ai-filter-results]');
    if (!this.form || !this.results) {
      return;
    }

    this.controller = null;
    this.debounceTimer = null;

    // Submit → filter in place.
    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.reloadFromForm();
    });

    // Live filtering: selects/checkboxes/date pickers commit immediately, free
    // text is debounced so we don't fire a request on every keystroke.
    this.form.addEventListener('change', () => this.reloadFromForm());
    this.form.addEventListener('input', (event) => {
      if (event.target && event.target.matches('input[type="text"], input[type="search"]')) {
        this.debounce(() => this.reloadFromForm(), 400);
      }
    });

    // Reset link → load the plain module url.
    const reset = root.querySelector('[data-wn-ai-filter-reset]');
    if (reset) {
      reset.addEventListener('click', (event) => {
        event.preventDefault();
        if (typeof this.form.reset === 'function') {
          this.form.reset();
        }
        this.load(reset.getAttribute('href'));
      });
    }

    // Pagination lives inside the (replaced) results container → delegate.
    this.results.addEventListener('click', (event) => {
      const link = event.target.closest('a[data-wn-ai-page]');
      if (!link) {
        return;
      }
      event.preventDefault();
      this.load(link.getAttribute('href'));
    });
  }

  debounce(callback, delay) {
    window.clearTimeout(this.debounceTimer);
    this.debounceTimer = window.setTimeout(callback, delay);
  }

  /**
   * Build the target url from the form's action (which carries the module
   * route token) plus the current field values, resetting pagination.
   */
  reloadFromForm() {
    const url = new URL(this.form.getAttribute('action'), window.location.href);
    // Drop stale filter params but keep the route/token that identify the module.
    for (const key of [...url.searchParams.keys()]) {
      if (key !== 'token' && key !== 'route') {
        url.searchParams.delete(key);
      }
    }
    for (const [name, value] of new FormData(this.form).entries()) {
      if (value !== '') {
        url.searchParams.set(name, value);
      }
    }
    url.searchParams.delete('page');
    this.load(url.href);
  }

  async load(target) {
    const url = new URL(target, window.location.href);
    const displayUrl = url.href;
    url.searchParams.set('ajax', '1');

    // Cancel a still-running request (e.g. fast typing).
    if (this.controller) {
      this.controller.abort();
    }
    this.controller = new AbortController();

    this.results.classList.add('wn-ai-loading');
    try {
      const response = await fetch(url.href, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: this.controller.signal,
      });
      if (!response.ok) {
        throw new Error('Request failed: ' + response.status);
      }
      this.results.innerHTML = await response.text();
      // Keep the browser url in sync so a reload/bookmark preserves the filter.
      window.history.replaceState(null, '', displayUrl);
    } catch (error) {
      if (error.name !== 'AbortError') {
        // Fall back to a full navigation so the user still sees a result.
        window.location.href = displayUrl;
      }
    } finally {
      this.results.classList.remove('wn-ai-loading');
    }
  }
}

document.querySelectorAll('.wn-ai-log').forEach((root) => new AjaxFilter(root));

/**
 * Ask before a form that cannot be undone is submitted.
 *
 * Delegated from the document, because the results fragment is replaced on
 * every filter request — a listener bound to the forms themselves would be
 * thrown away with the markup that carried it. The message travels in
 * "data-wn-ai-confirm" rather than an inline onsubmit, which a Content
 * Security Policy drops.
 */
document.addEventListener('submit', (event) => {
  const form = event.target.closest('[data-wn-ai-confirm]');
  if (form !== null && !window.confirm(form.dataset.wnAiConfirm)) {
    event.preventDefault();
  }
});
