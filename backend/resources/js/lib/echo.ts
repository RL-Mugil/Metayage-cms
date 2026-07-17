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

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY || 'mypl-key',
  wsHost: unsafeProductionHost ? window.location.hostname : (configuredHost || window.location.hostname),
  wsPort,
  wssPort: wsPort,
  forceTLS,
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
});

export default echo;
