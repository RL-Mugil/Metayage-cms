import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global { interface Window { Pusher: typeof Pusher; Echo: Echo<'reverb'> } }

window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY || 'mypl-key',
  wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
  wsPort: parseInt(import.meta.env.VITE_REVERB_PORT || '8080'),
  wssPort: parseInt(import.meta.env.VITE_REVERB_PORT || '8080'),
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'https') === 'https',
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
});

export default echo;
