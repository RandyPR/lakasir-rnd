{{--
  What a Livewire request that did not work looks like on screen.

  Livewire answers every failed request (a 403 from a session that ended, a 408 from a phone whose connection is not
  back yet, a 500) with a full-screen overlay that prints the body of the response. For a JSON or empty body that is
  a dark frame with nothing in it, and the page stays covered until it is reloaded. Here a failed request gets a
  short toast instead, and a session that has ended sends the cashier to the login page.

  The panel renders this on every page (TenantPanelProvider, PanelsRenderHook::SCRIPTS_AFTER).
--}}
<script data-livewire-resilience>
  (() => {
    if (window.lakasirResilience) return;
    window.lakasirResilience = true;

    const cfg = @js([
        // The login page has no session to lose: there a failure only gets its toast.
        'authenticated' => filament()->auth()->check(),
        // With APP_DEBUG a server error keeps Livewire's own overlay, the page a developer wants to read.
        'debug' => (bool) config('app.debug'),
        'pingUrl' => route('session.ping', absolute: false),
        // A quarter of the session lifetime, between one and ten minutes.
        'pingEvery' => max(1, min(10, intdiv((int) config('session.lifetime'), 4))) * 60 * 1000,
        'text' => [
            'endedTitle' => __('Session ended'),
            'endedBody' => __('You were signed out because the screen was not used for a long time. Taking you to the login page.'),
            'offlineTitle' => __('Connection problem'),
            'offlineBody' => __('The server could not be reached. Check the connection and try again.'),
            'deniedTitle' => __('Not allowed'),
            'deniedBody' => __('You are not allowed to do this.'),
            'errorTitle' => __('Something went wrong'),
            'errorBody' => __('The server could not finish this. Try again.'),
        ],
    ]);

    // The polls (wire:poll) and a bare $refresh ask for nothing the cashier did: when one fails there is nothing to say.
    const backgroundCalls = ['$refresh', 'syncBill'];
    let lastToast = 0;
    let leaving = false;

    // Drawn by hand, not with Filament's notifications: those are shown by a Livewire request, and a message about a
    // Livewire request that cannot get through would never arrive.
    const toast = (title, body, level = 'danger', always = false) => {
      const now = Date.now();
      if (! always && now - lastToast < 10000) return;
      lastToast = now;

      const dark = document.documentElement.classList.contains('dark');
      const colors = { danger: '#dc2626', warning: '#d97706' };

      let box = document.getElementById('lakasir-toast');
      if (box) box.remove();

      box = document.createElement('div');
      box.id = 'lakasir-toast';
      box.setAttribute('role', 'alert');
      // On a phone the top is where the search box and the category chips are, so the toast sits at the bottom, above
      // the order dock (the same rule the cashier's own toasts follow), unless the keyboard is up.
      const keyboard = window.visualViewport && window.visualViewport.height < window.innerHeight * 0.75;
      const low = ! window.matchMedia('(min-width: 768px)').matches && ! keyboard;
      const dock = document.querySelector('[data-testid="cart-dock"]');
      const dockHeight = dock && dock.getClientRects().length > 0 ? Math.ceil(dock.getBoundingClientRect().height) : 0;
      const place = low
        ? 'bottom:calc(env(safe-area-inset-bottom,0px) + ' + (dockHeight + 12) + 'px);'
        : 'top:12px;';

      box.style.cssText = 'position:fixed;z-index:2147483000;' + place + 'left:12px;right:12px;max-width:24rem;margin:0 auto;'
        + 'padding:12px 16px;border-radius:12px;font:14px/1.4 system-ui,sans-serif;cursor:pointer;'
        + 'box-shadow:0 10px 25px rgba(0,0,0,.25);border-left:6px solid ' + (colors[level] || colors.danger) + ';'
        + (dark ? 'background:#1f2937;color:#f9fafb;' : 'background:#fff;color:#111827;');

      const heading = document.createElement('div');
      heading.style.fontWeight = '600';
      heading.textContent = title;
      const message = document.createElement('div');
      message.style.marginTop = '2px';
      message.textContent = body;
      box.append(heading, message);

      box.addEventListener('click', () => box.remove());
      document.body.append(box);
      setTimeout(() => box.remove(), 6000);
    };

    const isBackground = (body) => {
      try {
        const { components } = JSON.parse(body);

        return components.length > 0 && components.every((component) =>
          Object.keys(component.updates || {}).length === 0
          && (component.calls || []).every((call) => backgroundCalls.includes(call.method)));
      } catch (e) {
        return false;
      }
    };

    // 'expired': the server says there is no session. 'unknown': it could not be asked (offline, busy).
    const sessionState = async () => {
      if (! cfg.authenticated) return 'alive';

      try {
        const response = await fetch(cfg.pingUrl, {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (response.status === 401 || response.redirected) return 'expired';

        return response.ok ? 'alive' : 'unknown';
      } catch (e) {
        return 'unknown';
      }
    };

    const endSession = () => {
      if (leaving) return;
      leaving = true;

      toast(cfg.text.endedTitle, cfg.text.endedBody, 'warning', true);

      // Reloaded a moment ago and still no session: leave the toast on screen instead of reloading in a circle.
      let last = 0;
      try { last = Number(sessionStorage.getItem('lakasir.sessionEndedAt') || 0); } catch (e) {}
      if (Date.now() - last < 30000) return;
      try { sessionStorage.setItem('lakasir.sessionEndedAt', String(Date.now())); } catch (e) {}

      // The reload is answered with a redirect to the login page, which brings the cashier back here afterwards.
      setTimeout(() => window.location.reload(), 1500);
    };

    const afterFailure = async (status, quiet) => {
      if (await sessionState() === 'expired') return endSession();
      if (quiet) return;

      if (status === 403) return toast(cfg.text.deniedTitle, cfg.text.deniedBody);
      if (status === 0 || status === 408 || (status >= 502 && status <= 504)) {
        return toast(cfg.text.offlineTitle, cfg.text.offlineBody);
      }

      toast(cfg.text.errorTitle, cfg.text.errorBody);
    };

    const install = () => {
      window.Livewire.hook('request', ({ options, fail }) => {
        const quiet = isBackground(options && options.body);

        fail(({ status, content, preventDefault }) => {
          const blank = ! content || ! String(content).trim();

          if (cfg.debug && status === 500 && ! blank) return;

          preventDefault();
          afterFailure(status, quiet);
        });
      });
    };

    if (window.Livewire && typeof window.Livewire.hook === 'function') install();
    else document.addEventListener('livewire:init', install, { once: true });

    if (cfg.authenticated) {
      const ping = async () => {
        if (document.visibilityState !== 'visible' || navigator.onLine === false) return;
        if (await sessionState() === 'expired') endSession();
      };

      // The screen stays on the counter all day: this keeps the session from timing out while it is on and in sight.
      setInterval(ping, cfg.pingEvery);

      // A phone that was asleep finds out at once, not on the cashier's next tap.
      let hiddenAt = null;
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
          hiddenAt = Date.now();

          return;
        }

        if (hiddenAt !== null && Date.now() - hiddenAt > 30000) ping();
        hiddenAt = null;
      });
      window.addEventListener('online', ping);
    }
  })();
</script>
