import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global { interface Window { Pusher: typeof Pusher; Echo: Echo<'reverb'> } }

window.Pusher = Pusher;

const configuredHost = import.meta.env.VITE_REVERB_HOST?.trim();
const unsafeProductionHost = import.meta.env.PROD
  && (configuredHost === 'localhost' || configuredHost === '127.0.0.1');
const forceTLS = window.location.protocol === 'https:'
  || import.meta.env.VITE_REVERB_SCHEME === 'https';
const configuredPort = Number.parseInt(import.meta.env.VITE_REVERB_PORT || '', 10);
const wsPort = unsafeProductionHost
  ? 443
  : (Number.isFinite(configuredPort) ? configuredPort : (forceTLS ? 443 : 8080));

function xsrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : '';
}

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY || 'mypl-key',
  wsHost: unsafeProductionHost ? window.location.hostname : (configuredHost || window.location.hostname),
  wsPort,
  wssPort: wsPort,
  forceTLS,
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
  // Private/presence channels authorize against Laravel's /broadcasting/auth.
  // This is a Sanctum SPA (cookie session), so we post with credentials and
  // the freshly-read XSRF token rather than a meta-tag CSRF token.
  authorizer: (channel: { name: string }) => ({
    authorize: (socketId: string, callback: (error: boolean, data: unknown) => void) => {
      fetch('/broadcasting/auth', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
      })
        .then((res) => (res.ok ? res.json() : Promise.reject(res)))
        .then((data) => callback(false, data))
        .catch((error) => callback(true, error));
    },
  }),
});

export default echo;
