import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
    // None of our backend events (IncomingCall, MessageSent, etc.) override
    // broadcastAs(), so Laravel/Pusher broadcasts them under their bare
    // class name on the wire (e.g. "IncomingCall"). Echo's default
    // namespace ("App.Events") makes .listen('IncomingCall', ...) bind to
    // "App\Events\IncomingCall" instead, which never matches — silently
    // breaking every .listen() call in this app (calls, live messages,
    // typing, read receipts). Empty namespace = listen for the exact name
    // given, matching what's actually on the wire.
    namespace: '',
});
