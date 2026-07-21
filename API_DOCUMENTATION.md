# SamChats API Documentation

This is the complete reference for building a mobile client (or any other client) against the SamChats backend. It covers every REST endpoint, the realtime WebSocket layer (Pusher Channels), WebRTC call signaling, Sampay in-chat payments, and push notifications.

Everything the web chat client (`resources/js/chat.js`) does is available here — this API is not a subset of the web app's capabilities.

## Table of contents

1. [Overview](#1-overview)
2. [Authentication](#2-authentication)
3. [Users & profile](#3-users--profile)
4. [Contacts / address book](#4-contacts--address-book)
5. [Chats, messages & groups](#5-chats-messages--groups)
6. [Realtime (WebSocket / Pusher)](#6-realtime-websocket--pusher)
7. [Calls (WebRTC signaling)](#7-calls-webrtc-signaling)
8. [Statuses (stories)](#8-statuses-stories)
9. [Sampay in-chat payments](#9-sampay-in-chat-payments)
10. [Push notifications](#10-push-notifications)
11. [Known limitations / pre-launch checklist](#11-known-limitations--pre-launch-checklist)

---

## 1. Overview

- **Base URL**: `{APP_URL}/api` (e.g. `http://10.253.52.73:8000/api` in this dev environment).
- **Auth**: Laravel Sanctum bearer tokens. Send `Authorization: Bearer {token}` on every request except registration, OTP request/verify, and the Sampay OAuth callback.
- **Content type**: `application/json` for JSON bodies, `multipart/form-data` for endpoints that accept file uploads (profile photo, chat attachments, group image, status media). Always send `Accept: application/json`.
- **IDs**: Most resources (users, chats, messages, calls, statuses) use UUID primary keys. `status_views` and `device_tokens` use auto-increment integer IDs.
- **Errors**: Validation errors return `422` with Laravel's standard `{"message": "...", "errors": {"field": ["..."]}}` shape. Most domain errors return `400`/`403`/`404`/`503` with `{"error": "..."}` or `{"message": "..."}` (inconsistent key naming across older/newer controllers — check both when parsing error responses).

---

## 2. Authentication

Phone number + OTP, no password. Flow:

```
POST /api/auth/register        (create the account)
POST /api/auth/request-otp     (trigger an OTP send)
POST /api/auth/verify-otp      (exchange OTP for a bearer token)
```

### `POST /auth/register`
```json
{
  "first_name": "Comfort",
  "middle_name": null,
  "last_name": "Chambeshi",
  "username": "comfort_c",
  "phone_number": "+260968793843",
  "email": "comfortchambeshi4@gmail.com"
}
```
→ `201` `{"message": "Registration successful", "user": {...}}`. `username`/`phone_number`/`email` must be unique.

### `POST /auth/request-otp`
```json
{"phone_number": "+260968793843"}
```
→ `200` `{"message": "OTP sent successfully", "phone_number": "..."}`, or `403` if the number isn't registered.

> **⚠️ OTP is currently mocked.** No SMS is actually sent (`AuthController::requestOtp` just logs it), and `verifyOtp` accepts the hardcoded code **`123456`** for any registered number. See [§11](#11-known-limitations--pre-launch-checklist) — wiring up a real SMS provider (Twilio/AWS SNS/etc.) is a pre-launch requirement, not something this pass implemented.

### `POST /auth/verify-otp`
```json
{"phone_number": "+260968793843", "otp": "123456"}
```
→ `200` `{"message": "Authenticated successfully", "user": {...}, "token": "1|abcdef..."}`. Store `token` and send it as `Authorization: Bearer {token}` from here on. Returns `403` if the account is blocked (`is_blocked`).

### `POST /auth/logout` *(auth required)*
Revokes the current access token (`{"message": "Logged out successfully"}`).

### `GET /user` *(auth required)*
Returns the authenticated user's full record — cheap way to validate a stored token on app start.

---

## 3. Users & profile

| Method | Path | Purpose |
|---|---|---|
| POST | `/user/profile` | Update your own profile |
| GET | `/users/search?q=` | Search users by phone/username (omit `q` to list first 50) |
| GET | `/users/{user_id}` | Fetch any user by ID |
| GET | `/users/{user_id}/online-status` | Is this user online (last-seen within 2 minutes) |
| POST | `/user/online` | Heartbeat — call periodically to keep your own presence fresh |
| GET | `/users/privacy` / `POST /users/privacy` | Status (story) privacy settings |
| GET | `/users/blocked` | List users you've blocked |
| POST | `/users/{user_id}/block` / `DELETE /users/{user_id}/block` | Block / unblock |

### `POST /user/profile` *(multipart if uploading a photo)*
```
first_name, middle_name, last_name, email, username, about_status  (all optional/"sometimes")
photo   (file, image, max 10MB)
```
→ `{"message": "Profile updated successfully", "user": {...}}`. `photo` is stored to the `profile_photos` disk and `photo_url` is set to its public URL.

### `GET /users/search?q=comfort`
Searches `phone_number` and `username` (substring match). Response objects intentionally omit `phone_number` (privacy) and include `saved_name` — the caller's address-book override name for that user, if any (`null` otherwise). This `saved_name` field is injected consistently across search, chat participants, call caller/receiver, and statuses — always prefer it over the raw name for display when present.

### Presence model — **mobile clients must actively call these**
The web client relies entirely on a Pusher **presence channel** (`Echo.join('app')`) for online/offline status and never calls `/user/online` or `/users/{id}/online-status`. A mobile app is not realistically going to hold a presence-channel connection open in the background, so it should instead:
- Call `POST /user/online` on app foreground and periodically while active (e.g. every 60–90s), and
- Call `GET /users/{id}/online-status` when displaying a specific user's status (it reports online if `last_seen_at` is within the last 2 minutes).

### Privacy (`status_privacy`)
`GET /users/privacy` → `{"status_privacy": "everyone|contacts|selected|exclude", "status_privacy_list": [ids], "status_privacy_usernames": [names]}`.
`POST /users/privacy` body: `{"status_privacy": "selected", "status_privacy_list": ["user-id-or-username", ...]}` — list accepts either IDs or usernames, resolved server-side to IDs.

### Blocking
`POST /users/{user_id}/block` / `DELETE /users/{user_id}/block`. Blocking prevents either side from sending messages in their direct chat (`403` from `storeMessage`) and is reflected in `GET /chats` via `blocked_user_ids` and in `GET /chats/{id}` via `is_blocked`/`blocked_by_me`.

---

## 4. Contacts / address book

This is the WhatsApp-style "saved name" address book, separate from the users table.

| Method | Path | Purpose |
|---|---|---|
| GET | `/contacts` | List your saved contacts |
| POST | `/contacts` | Save a single contact (`contact_user_id`, `custom_name`) |
| PUT | `/contacts/{id}` | Rename a saved contact |
| DELETE | `/contacts/{id}` | Remove a saved contact |
| POST | `/contacts/sync` | **Bulk phone-book import** — the endpoint mobile should use |

### `POST /contacts/sync` — phone-book import
This is the primary mobile onboarding flow: read the device's phone contacts, send them here, and get back which of them are on SamChats (auto-saved to the address book with the phone's contact name).
```json
{
  "contacts": [
    {"phone_number": "+260968793843", "name": "Comfort C."},
    {"phone_number": "+260971234567", "name": "Jane Banda"}
  ]
}
```
→ `{"synced_count": 1, "contacts": [{...contact row with contactUser relation...}]}`. Phone numbers are normalized (`[^0-9+]` stripped) before matching.

> **Note:** an earlier version of this API had two competing contact-sync endpoints (`POST /users/contacts`, which didn't persist anything, and this one). The non-persisting duplicate has been removed — `/contacts/sync` is now the single canonical endpoint for phone-book import.

---

## 5. Chats, messages & groups

### Inbox & chat lifecycle

| Method | Path | Purpose |
|---|---|---|
| GET | `/chats` | Inbox list (supports `?filter=unread\|groups` and `?search=`) |
| POST | `/chats` | Get-or-create a direct chat (`{"user_id": "..."}`) |
| GET | `/chats/{chat_id}` | Chat detail + paginated messages (50/page), auto-marks unread as read |
| DELETE | `/chats/{chat_id}` | Leave/delete a chat for yourself |
| POST | `/chats/{chat_id}/mute` | Toggle mute for yourself |
| POST | `/chats/{chat_id}/typing` | Broadcast a typing indicator |

`GET /chats` response: `{"chats": [...], "blocked_user_ids": [...]}`. Each chat includes `group` (null for direct chats), `participants` (with `.user`, each carrying `saved_name`), and the last message preloaded.

`GET /chats/{chat_id}`: opening a chat **auto-marks all unread messages from others as read** and broadcasts `MessagesRead` — don't call `/messages/{id}/read` again for messages you just fetched this way; that endpoint is for marking a message read as it arrives live over the socket while the chat is already open (see [§6](#6-realtime-websocket--pusher)).

### Messages

| Method | Path | Purpose |
|---|---|---|
| POST | `/chats/{chat_id}/messages` | Send a message (multipart if `attachment` present) |
| POST | `/messages/{message_id}/read` | Mark one message read (for messages received live via the socket) |
| DELETE | `/messages/{message_id}?type=me\|everyone` | Delete a single message |
| DELETE | `/chats/{chat_id}/messages/bulk` | Bulk delete (`{"message_ids": [...], "type": "me\|everyone"}`) |
| DELETE | `/chats/{chat_id}/messages` | Clear entire chat history |
| POST | `/chats/{chat_id}/messages/forward` | Forward an existing message (`{"message_id": "..."}`) into another chat |

`POST /chats/{chat_id}/messages`:
```
message_type   required, e.g. "text" | "image" | "video" | "audio" | "file"
content        text body (optional if sending an attachment)
attachment     file, max 10MB (optional)
metadata       optional JSON object, merged with attachment metadata (media_url, file_name, mime_type)
quoted_message_id  optional, for replies
```
Returns `403` if the chat is direct and either side has blocked the other. On success, broadcasts `MessageSent` to the chat channel and every participant's personal channel (see [§6](#6-realtime-websocket--pusher)), and — if the recipient has registered device tokens — triggers a push notification (see [§10](#10-push-notifications)).

`type=me` vs `type=everyone` on delete: `me` only hides the message for you (per-user `deleted_messages` marker); `everyone` hard-deletes the row and is sender-only.

`POST /messages/{message_id}/read`: requires you to actually be a participant in the message's chat (returns `404` otherwise). Idempotent — calling it twice for the same message doesn't create duplicate receipts. Broadcasts `MessagesRead` (`{"message_ids": [id], "status": "read"}`) — the same event `GET /chats/{chat_id}` uses for its bulk auto-read, so clients only need one listener for both cases.

### Typing indicator — **mobile must use the REST endpoint**
`POST /chats/{chat_id}/typing` `{"is_typing": true}` broadcasts `UserTyping` on `chat.{chat_id}`. The **web client does not use this endpoint** — it uses a raw Pusher client-whisper (peer-to-peer over the socket) instead, which is not reliably delivered to native mobile Pusher clients. Mobile apps should call this REST endpoint to signal typing; it works regardless of client platform.

### Groups

| Method | Path | Purpose |
|---|---|---|
| POST | `/groups` | Create a group (`{"group_name": "...", "user_ids": [...]}`) |
| PUT | `/chats/{chat_id}/group` | Update `group_name` / `only_admins_can_post` (admin only) |
| POST | `/chats/{chat_id}/group/image` | Upload group photo, multipart `group_image` (admin only) |
| POST | `/chats/{chat_id}/leave` | Leave a group |
| POST | `/chats/{chat_id}/participants` | Add participants (admin only, `{"user_ids": [...]}`) |
| PUT | `/chats/{chat_id}/participants/{user_id}/role` | Toggle admin role (admin only, `{"is_admin": true}`) |
| DELETE | `/chats/{chat_id}/participants/{user_id}` | Remove a participant (admin only, can't remove self) |

The group creator is automatically an admin. `Group.group_image_url` is a computed public URL — use it directly, don't construct it from `group_image`.

---

## 6. Realtime (WebSocket / Pusher)

The backend uses **Pusher Channels** (the hosted Pusher Cloud service) — not a self-hosted WebSocket server. This replaced an earlier Laravel Reverb setup; Reverb is no longer part of this project (no process to run, no server resources to provision for it). Any Pusher-protocol client library (`pusher-js`, `laravel-echo`, or native Pusher SDKs for iOS/Android) works — point it at Pusher Cloud instead of a self-hosted host/port.

### Connection

```
Key:     value of PUSHER_APP_KEY / VITE_PUSHER_APP_KEY
Cluster: value of PUSHER_APP_CLUSTER / VITE_PUSHER_APP_CLUSTER (e.g. mt1)
useTLS:  true
```
Native Pusher SDKs (iOS/Android) connect using just the app key and cluster — no custom host/port needed, since this now targets Pusher's own infrastructure rather than a self-hosted server.

### Auth endpoint — **not Laravel's default**
Private/presence channels need an authorizer call. This backend exposes it at:

```
POST /api/broadcasting/auth
Authorization: Bearer {token}
Body: channel_name, socket_id   (form-encoded, as sent by the Pusher/Echo client)
```

This is **not** Laravel's built-in `/broadcasting/auth` (which relies on the session guard) — it's a custom controller (`BroadcastAuthController`) that authenticates via the same Sanctum bearer token as every other API call. Point your WebSocket client's `authEndpoint`/`authorizer` at this full path with the `Authorization` header attached, the same as any other API request.

### Channels

| Channel | Type | Who can subscribe |
|---|---|---|
| `user.{user_id}` | private | only that user |
| `chat.{chat_id}` | private | participants of that chat |
| `call.{call_id}` | private | the call's caller/receiver, or any participant of the call's group chat |
| `app` | presence | any authenticated user — used for global online/offline tracking |

Subscribe to your own `user.{id}` channel for personal notifications (new messages in any chat, incoming calls) and to `chat.{id}` for the chat currently open.

### Events

| Event | Channel(s) | Payload | Fired when |
|---|---|---|---|
| `MessageSent` | `chat.{chat_id}`, `user.{participant_id}` (each participant) | `{"message": {...full message row, incl. sender & receipts if loaded...}}` | A message is sent, forwarded, a call-log entry is created, or a Sampay payment message is created/updated |
| `MessagesRead` | `chat.{chat_id}` | `{"message_ids": [...], "status": "read"}` | A message (or batch, on chat open) is marked read |
| `UserTyping` | `chat.{chat_id}` | `{"user_id": "...", "is_typing": true\|false}` | `POST /chats/{id}/typing` |
| `IncomingCall` | `user.{receiver_id}` (1:1) or `user.{each_group_participant}` except caller | full `call` object | `POST /calls` |
| `CallAnswered` | `user.{caller_id}` | full `call` object | `POST /calls/{id}/accept` |
| `CallDeclined` | `user.{target_id}` | `{"call": {...}, "target_id": "..."}` | `POST /calls/{id}/decline` or `/end` — also used to tell the other party to hang up |
| `CallSignal` | `call.{call_id}` | arbitrary signaling payload; **event name is dynamic** — see below | WebRTC join/offer/answer/candidate relay |

> **Note on a fixed bug:** an older version of `markAsRead()` broadcast a `MessageDelivered` event with a hardcoded `status: "delivered"`, even though it had just recorded a **read** receipt — misleading and now removed. Single-message reads now broadcast the same `MessagesRead` event as the bulk auto-read-on-open path, so there is exactly one event to listen for.

### `CallSignal` — dynamic event name
`CallSignal::broadcastAs()` returns whatever `type` key is in the payload, so on the wire this shows up as a *named* event, not literally `"CallSignal"`. The two names you'll see:
- **`.client-user-joined`** — `{userId, userName, userPhoto, targetId?}`, sent via `POST /calls/{id}/join`.
- **`.client-webrtc-signal`** — `{offer|answer|candidate, targetId, senderId, senderName, senderPhoto}`, sent via `POST /calls/{id}/offer|answer|candidate` (or the generic `/calls/{id}/signal`).

(The leading dot is how Pusher-protocol clients denote a custom/client-style event name — listen with `channel.listen('.client-user-joined', ...)` etc., exactly as `resources/js/chat.js` does.)

---

## 7. Calls (WebRTC signaling)

Calls are **pure browser/native WebRTC** — peer-to-peer mesh (every participant connects directly to every other participant), no SFU/media server. Signaling (offer/answer/ICE candidates) is relayed **through this REST API + broadcast**, not through raw Pusher "client events," specifically so that native mobile Pusher clients — which don't reliably support whisper/client-events — can interoperate with the web client. This means mobile and web calls are fully compatible using the same signaling endpoints below.

### Lifecycle

| Method | Path | Purpose |
|---|---|---|
| POST | `/calls` | Initiate (`{"receiver_id"\|"chat_id", "call_type": "audio\|video"}`) |
| POST | `/calls/{call_id}/accept` | Accept |
| POST | `/calls/{call_id}/decline` | Decline (1:1 only — group calls: frontend just ignores a decline from one participant) |
| POST | `/calls/{call_id}/end` | End (1:1 only) |
| GET | `/calls/active` | Your calls with `ended_at IS NULL` |
| GET | `/calls/{call_id}` | Single call detail |
| GET | `/calls` | Full call history |
| DELETE | `/calls` | Clear your call history |

Ending or declining a 1:1 call creates a `call_log` message in the underlying chat (creating a direct chat if one didn't already exist) with `metadata: {call_id, call_type, status, duration}`, and broadcasts `MessageSent` for it — call history shows up in the chat thread automatically, no separate fetch needed.

### Signaling (mesh join + WebRTC handshake)

| Method | Path | Purpose |
|---|---|---|
| POST | `/calls/{call_id}/join` | Announce yourself joining a (group) call — relays `.client-user-joined` |
| POST | `/calls/{call_id}/offer` | Send an SDP offer to `target_id` |
| POST | `/calls/{call_id}/answer` | Send an SDP answer to `target_id` |
| POST | `/calls/{call_id}/candidate` | Send an ICE candidate to `target_id` |
| POST | `/calls/{call_id}/signal` | Generic passthrough (`{"signal_data": {...}}`) for anything not covered above |

Typical flow for a 1:1 call:
1. Caller: `POST /calls` → gets `call.id`, subscribes to `call.{call.id}` and its own `user.{id}` channel.
2. Receiver gets `IncomingCall` on `user.{id}`, subscribes to `call.{call.id}`, `POST /calls/{id}/accept`.
3. Caller gets `CallAnswered`, creates an `RTCPeerConnection`, creates an offer, `POST /calls/{id}/offer` with `target_id` = receiver.
4. Receiver gets the relayed offer over `call.{id}` (`.client-webrtc-signal`), sets remote description, creates an answer, `POST /calls/{id}/answer`.
5. Both sides exchange ICE candidates via `POST /calls/{id}/candidate` as they're discovered.
6. Either side: `POST /calls/{id}/end` (or `/decline` before answering).

Group calls follow the same pattern but every new joiner calls `/join` first, and every existing participant responds by initiating an offer to the new joiner (full mesh).

> **⚠️ No TURN server is configured** — only public Google STUN servers (`stun:stun.l.google.com:19302`, `stun1...`). This works for most NATs but will fail for users behind symmetric NATs/strict carrier-grade NATs (common on mobile networks). Adding a TURN server (e.g. coturn) is an infrastructure change, not something this pass touched — flagging it as a pre-launch item, especially important for mobile since carrier NAT behavior is less predictable than home/office WiFi.

---

## 8. Statuses (stories)

24-hour disappearing stories, WhatsApp-style.

| Method | Path | Purpose |
|---|---|---|
| GET | `/statuses` | All non-expired statuses visible to you, grouped by poster |
| POST | `/statuses` | Create a status |
| DELETE | `/statuses/{status_id}` | Delete your own status |
| POST | `/statuses/{status_id}/view` | Mark viewed (no-op for your own) |
| GET | `/statuses/{status_id}/views` | Who viewed your status (owner only) |

`POST /statuses` — multipart if uploading media:
```
type              required: text | image | video
content           text content, or omitted if uploading media
media             file (jpeg/png/jpg/gif/mp4/mov/webm, max 50MB) — overrides `type` based on detected mime
background_color  optional, for text statuses
```
Display duration is fixed server-side: 5000ms for text/image, 15000ms for video.

### Privacy
Controlled by the poster's `status_privacy` (see [§3](#3-users--profile)):
- `everyone` — visible to all.
- `contacts` — visible only to users who have the poster saved in *their* address book (checked via the `contacts` table, not mutual).
- `selected` — visible only to users in `status_privacy_list`.
- `exclude` — visible to the poster's contacts *except* those in `status_privacy_list`.

`GET /statuses` response groups results by poster and injects `saved_name` per-poster, same convention as everywhere else.

---

## 9. Sampay in-chat payments

Sampay is a separate payments platform (`SAMPAY_BASE_URL`, OAuth2 client credentials). Each user links their own Sampay account once; payment requests are then sent as special chat messages (`message_type: "payment_request"`) whose `metadata` acts as a state machine.

### Account linking

| Method | Path | Purpose |
|---|---|---|
| GET | `/sampay/link` | Get an OAuth `authorization_url` to open in a browser/webview |
| GET | `/sampay/callback` | *(public, OAuth redirect target only — not called directly by clients)* |
| GET | `/sampay/status` | `{"is_linked": bool, "sampay_account": {"username", "mobile_number"}}` |
| DELETE | `/sampay/unlink` | Remove the linked account |

Mobile flow: `GET /sampay/link` → open `authorization_url` in an in-app browser/webview → Sampay redirects to `/api/sampay/callback` → backend exchanges the code, stores the account, and redirects to `{APP_URL}/app?sampay_linked=1`. A mobile client should intercept that final redirect (deep link / custom scheme, or just detect the `sampay_linked`/`sampay_error` query param if using a webview) and then poll `GET /sampay/status` to confirm.

### In-chat payment requests (direct chats only)

| Method | Path | Purpose |
|---|---|---|
| POST | `/chats/{chat_id}/sampay/validate-recipient` | Pre-flight validation only, doesn't create anything |
| POST | `/chats/{chat_id}/sampay/request-chat` | **Primary flow** — validates then creates the request + chat message |
| POST | `/chats/{chat_id}/sampay/sync-status` | Poll Sampay for status updates on recent pending requests in this chat |
| POST | `/chats/{chat_id}/messages/{message_id}/sampay/approve` | Target user approves a `pending_approval` request |
| POST | `/chats/{chat_id}/messages/{message_id}/sampay/reject` | Target user rejects a `pending_approval` request |
| POST | `/chats/{chat_id}/sampay/request` | *(legacy variant, kept for backward compatibility — prefer `request-chat`)* |

`POST /chats/{chat_id}/sampay/request-chat`:
```json
{
  "amount": 150.00,
  "recipient_type": "personal",
  "recipient_account": "0968793843",
  "purpose": "Dinner split",
  "remarks": "optional"
}
```
Requires the caller to have a linked Sampay account (`sampay/link` first) and the chat to be direct. On success, creates a `payment_request` message and broadcasts `MessageSent`. The message's `metadata.status` progresses through: `pending` → `pending_approval` / `submitted_to_sampay` → `approved` / `rejected` / `failed`, updated either by the recipient calling approve/reject, or by `sync-status` polling the Sampay API (rate-limited to once per 7 seconds per message to avoid hammering the upstream API — call `sync-status` periodically, e.g. every 5-10s, while a chat with pending requests is open).

> **⚠️ Sampay `access_token` is stored in plaintext** in the `sampay_accounts` table (no encryption cast). Worth encrypting before production if that table could ever be exposed (backup leak, DB access, etc.) — not changed in this pass since it's a data-at-rest/infra decision with its own tradeoffs, flagging for awareness.

---

## 10. Push notifications

Device push (new in this pass) fires for two events: a new chat message, and an incoming call. It's implemented with **Firebase Cloud Messaging (FCM)**, which covers both Android and iOS.

### Registering a device

| Method | Path | Purpose |
|---|---|---|
| POST | `/user/device-token` | Register a token (`{"token": "...", "platform": "ios\|android\|web"}`) |
| DELETE | `/user/device-token` | Unregister (`{"token": "..."}`) — call this on logout |

Call `POST /user/device-token` after obtaining an FCM registration token on app start / whenever it refreshes. Multiple tokens per user are supported (multiple devices); tokens are deduplicated by exact value.

### What triggers a push, and payload shape

**New message** (`data` payload merged into every push):
```json
{"type": "message", "chat_id": "...", "message_id": "..."}
```
Title = sender's display name (or `"{group name}: {sender name}"` for groups). Body = the message text, or a type-specific placeholder for non-text messages (`"📷 Photo"`, `"💰 Payment request"`, `"📞 Call"`, etc.) — never sent to the message's own sender.

**Incoming call**:
```json
{"type": "incoming_call", "call_id": "...", "call_type": "audio|video", "caller_id": "...", "caller_name": "...", "caller_photo": "...", "chat_id": ""}
```
This is intentionally a rich `data` payload (not just a notification banner) so the mobile app can render a native full-screen incoming-call UI, the same way WhatsApp/Messenger do — parse `type: "incoming_call"` and route to your call screen instead of showing a generic notification.

**Suppressing pushes for the open chat is a client-side responsibility.** The server pushes to every other participant unconditionally — it has no reliable way to know what screen a mobile client is currently showing. Standard pattern: keep receiving the push for badge-count/data-sync purposes, but suppress the visible banner locally if the payload's `chat_id` matches the chat currently open in the foreground.

### Setup (server-side)
1. Create a Firebase project (if you don't have one) and generate a service-account JSON key: Firebase Console → Project Settings → Service Accounts → Generate new private key.
2. Place the JSON file somewhere readable by the app (e.g. `storage/app/firebase-credentials.json` — keep it out of version control).
3. Set `FIREBASE_CREDENTIALS=/absolute/path/to/that/file.json` in `.env`.
4. Make sure a queue worker is running (`php artisan queue:work`, or `composer run dev` which already includes it) — push sends are queued (`ShouldQueue`) so they never block the API response.

Until `FIREBASE_CREDENTIALS` is set, push sending silently no-ops (with a logged warning) — nothing breaks, notifications are just not delivered. This is the current state of this dev environment.

### Delivery failure cleanup
If FCM reports a token as invalid/unregistered (app uninstalled, token rotated, etc.), that `device_tokens` row is deleted automatically — no manual cleanup needed.

---

## 11. Known limitations / pre-launch checklist

Things that are real gaps or infra decisions, called out explicitly rather than silently worked around:

- **OTP is mocked** (`123456` accepted for any registered number, no SMS sent). Needs a real provider (Twilio Verify, AWS SNS, Firebase Auth phone, etc.) before production use. Left as-is per product decision — this doc should be updated once that's wired up.
- **No TURN server** for WebRTC calls — STUN-only. Mobile carrier NAT traversal may fail for some users; add a TURN server (e.g. coturn) as an infra task.
- **Sampay `access_token` stored in plaintext.** Consider an encrypted cast on `SampayAccount::access_token` before production.
- **Schema has unused columns/features**: `groups.invite_link_hash` exists but no invite-link generation/redemption endpoint is implemented; there's no starred-messages or broadcast-lists feature. Not required for parity with the current web app (which also doesn't have them), noting in case mobile scope expands.
- **Push notifications are new** and mobile-only — the web client doesn't need them since it stays socket-connected. If you add more triggers later (e.g. push on new status, on group-add), follow the pattern in `app/Listeners/` (a queued listener on the relevant broadcast event, calling `App\Services\PushNotificationService::sendToUser()`).
