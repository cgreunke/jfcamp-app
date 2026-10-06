(function (Drupal) {
  'use strict';

  Drupal.behaviors.jfcampMatchingStatus = {
    attach(context) {
      const root = context.querySelector
        ? context.querySelector('#jfcamp-matching-live-status')
        : null;

      if (!root || root.dataset.pollingStarted === '1') {
        return;
      }
      root.dataset.pollingStarted = '1';

      const statusEl = root.querySelector('[data-jfcamp-status]');
      const phaseEl = root.querySelector('[data-jfcamp-phase]');
      const elapsedEl = root.querySelector('[data-jfcamp-elapsed]');
      const progressEl = root.querySelector('[data-jfcamp-progress]');
      const detailEl = root.querySelector('[data-jfcamp-detail]');
      const url = root.dataset.statusUrl;

      let stopped = false;
      let timer = null;

      const formatElapsed = (seconds) => {
        const value = Math.max(0, Number(seconds) || 0);
        const minutes = Math.floor(value / 60);
        const secs = Math.floor(value % 60);
        return `${minutes}:${String(secs).padStart(2, '0')}`;
      };

      const schedule = () => {
        if (!stopped) {
          timer = window.setTimeout(poll, 3000);
        }
      };

      const poll = async () => {
        if (stopped || !url) {
          return;
        }

        try {
          const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
          });

          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }

          const data = await response.json();

          if (statusEl) {
            statusEl.textContent = `Status: ${data.status_label || data.status || 'unbekannt'}`;
          }
          if (phaseEl) {
            phaseEl.textContent = data.phase_label || 'Matching wird berechnet';
          }
          if (elapsedEl) {
            elapsedEl.textContent = formatElapsed(data.elapsed_seconds);
          }
          if (progressEl) {
            progressEl.max = Math.max(1, Number(data.progress_total) || 7);
            progressEl.value = Math.max(0, Number(data.progress_current) || 0);
          }

          if (detailEl) {
            if (data.status === 'queued') {
              detailEl.textContent = 'Der Worker übernimmt den Lauf automatisch.';
            }
            else if (data.status === 'running') {
              detailEl.textContent = 'Die Seite bleibt benutzbar. Der Status wird automatisch aktualisiert.';
            }
            else if (data.status === 'completed') {
              detailEl.textContent = 'Berechnung abgeschlossen. Ergebnis wird geladen …';
            }
            else if (data.status === 'failed') {
              detailEl.textContent = data.error_message
                ? `Fehler: ${data.error_message}`
                : 'Der Matching-Lauf ist fehlgeschlagen.';
            }
          }

          if (data.terminal) {
            stopped = true;
            if (timer) {
              window.clearTimeout(timer);
            }
            window.setTimeout(() => window.location.reload(), 700);
            return;
          }
        }
        catch (error) {
          if (detailEl) {
            detailEl.textContent = 'Status konnte kurzzeitig nicht geladen werden. Neuer Versuch …';
          }
        }

        schedule();
      };

      poll();
    },
  };
})(Drupal);
