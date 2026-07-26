import './echo';
import * as E2ee from './crypto';
let currentChatId = null;
let currentChatType = 'direct';
let selectedGroupUsers = [];
let loadedChatData = null;
let currentChatChannelName = null;
let onlineUsers = new Set();
let blockedUsersSet = new Set();
let loadedInboxChats = [];
let activeSidebarFilter = 'all';
// Chat IDs where the other participant is currently typing — drives the
// "typing..." preview in the chat list, kept separate from `loadedInboxChats`
// since it's live/ephemeral and shouldn't touch the cached REST data.
let typingChats = new Set();
let selectedPaymentTargetUserId = null;
let paymentStatusPollInterval = null;
let recipientAccountIntlInput = null;
let iAmTyping = false;
let typingStopTimer = null;
let selectedMeetingInvitees = [];
let currentEmailAccount = null;
let currentEmailPage = 1;
let currentEmailHasMore = false;
let currentEmailLoadingMore = false;
let currentReplyToEmailId = null;
let currentReplyAllMode = false;
let composeAttachments = []; // File objects picked for the compose form, accumulated across multiple "+ Add" clicks

// A user counts as "online" if they're a live member of the `presence-app`
// channel (instant, but only ever populated by clients that join it — the
// mobile app doesn't, it only heartbeats `last_seen_at` instead) OR their
// `last_seen_at` is fresh, mirroring the same 2-minute rule the backend uses
// in UserController::onlineStatus and the Flutter app uses client-side. This
// fallback is what makes mobile users show as online on the web client.
function isUserOnline(user) {
    if (!user) return false;
    if (onlineUsers.has(user.id ?? user.user_id)) return true;
    if (!user.last_seen_at) return false;
    return (Date.now() - new Date(user.last_seen_at).getTime()) < 2 * 60 * 1000;
}

// Mirrors the Flutter app's ChatDetailNotifier.onComposerTextChanged: notify
// the server on the first keystroke, then again when input stops for 2s —
// via the same POST /chats/{id}/typing + UserTyping broadcast the mobile app
// uses, instead of the old Pusher-whisper-only approach that never reached it.
function setTyping(hasText) {
    if (!currentChatId) return;
    if (hasText && !iAmTyping) {
        iAmTyping = true;
        postTyping(true);
    }
    clearTimeout(typingStopTimer);
    if (hasText) {
        typingStopTimer = setTimeout(() => {
            iAmTyping = false;
            postTyping(false);
        }, 2000);
    } else if (iAmTyping) {
        iAmTyping = false;
        postTyping(false);
    }
}

function postTyping(isTyping) {
    if (!currentChatId) return;
    fetch(`/api/chats/${currentChatId}/typing`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${window.API_TOKEN}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ is_typing: isTyping })
    }).catch(() => {});
}

/* ========================================================
   E2EE — mirrors lib/core/crypto/e2ee_service.dart. Device keypair lives in
   localStorage (weaker than the mobile app's secure-storage keychain — a
   documented limitation of the browser environment, see the project plan),
   chat keys are cached in-memory + localStorage. Server never sees plaintext
   keys or message content for an encrypted chat.
======================================================== */
let e2eeDeviceId = null;
let e2eePrivateKeyBase64 = null;
const e2eeChatKeyCache = new Map(); // chatId -> Uint8Array

function e2eeUuidV4() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
}

async function ensureDeviceRegistered() {
    e2eeDeviceId = localStorage.getItem('e2ee_device_id');
    e2eePrivateKeyBase64 = localStorage.getItem('e2ee_private_key');

    let publicKeyBase64;
    if (e2eePrivateKeyBase64) {
        publicKeyBase64 = E2ee.toBase64(await E2ee.publicKeyFromPrivateKeyBase64(e2eePrivateKeyBase64));
    } else {
        const { privateKey, publicKey } = await E2ee.generateKeyPair();
        e2eePrivateKeyBase64 = E2ee.toBase64(privateKey);
        e2eeDeviceId = e2eeDeviceId || e2eeUuidV4();
        localStorage.setItem('e2ee_private_key', e2eePrivateKeyBase64);
        localStorage.setItem('e2ee_device_id', e2eeDeviceId);
        publicKeyBase64 = E2ee.toBase64(publicKey);
    }

    try {
        await fetch('/api/device-keys', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ device_id: e2eeDeviceId, public_key: publicKeyBase64, platform: 'web' })
        });
    } catch (e) {
        console.error('E2EE device registration failed', e);
    }
}

/**
 * Idempotent — createOrGetDirectChat-style endpoints return an existing chat
 * on repeat calls, so this runs on every open of a chat, not just genuinely
 * new ones. If this device already has a key for the chat, one was already
 * distributed (by this device or another participant's) — generating a new
 * one here would silently orphan everyone else's copy.
 *
 * Critically, this also bails out if the CHAT already has a key established
 * by ANY device (not just this one) — a device whose local identity resets
 * (cleared browser storage/a new browser profile — a brand-new device_id/
 * keypair, indistinguishable server-side from a genuinely new device) has no
 * local key either, but the chat itself is very much already keyed. Without
 * this check such a device would generate and upload a competing key,
 * overwriting every other participant's grant and permanently orphaning
 * every message already encrypted under the real one — confirmed to
 * actually happen this way for a real chat. A reset device just has to
 * wait — the next message anyone else sends reseals the real key to it via
 * healMissingGrants.
 */
async function distributeNewChatKey(chatId, participantUserIds) {
    if (await getChatKey(chatId)) return;
    if (await chatHasEstablishedKey(chatId)) return;

    const chatKey = await E2ee.generateChatKey();
    const grants = [];
    for (const userId of participantUserIds) {
        const deviceKeys = await fetchDeviceKeysForUser(userId);
        for (const device of deviceKeys) {
            const sealed = await E2ee.sealToPublicKey(chatKey, device.public_key);
            grants.push({ user_id: userId, device_id: device.device_id, sealed_key: sealed });
        }
    }
    if (grants.length === 0) return; // nobody has a registered device key yet — chat stays plaintext

    await uploadChatKeyGrants(chatId, grants);
    e2eeChatKeyCache.set(chatId, chatKey);
    localStorage.setItem(`e2ee_chat_key_${chatId}`, E2ee.toBase64(chatKey));
}

async function chatHasEstablishedKey(chatId) {
    try {
        const res = await fetch(`/api/chats/${chatId}/keys/exists`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        if (!res.ok) return false;
        const data = await res.json();
        return data.exists === true;
    } catch (e) {
        return false;
    }
}

/**
 * Reseals this chat's key to any participant device that doesn't have a
 * grant for it yet — the self-healing counterpart to
 * distributeNewChatKey/distributeKeyToNewMember. A device only ever gets a
 * grant at the moment a chat's key is first created, or when it's added as
 * a participant/new device — nothing re-checks this afterwards, so a device
 * whose local identity resets permanently loses access to that chat's key
 * otherwise. Called from tryEncryptForChat on every send; cheap (the
 * backend only ever returns genuinely missing devices) and
 * self-correcting — the next message sent by anyone who still has access
 * repairs every other participant's stale/missing devices.
 */
async function healMissingGrants(chatId) {
    const chatKey = e2eeChatKeyCache.get(chatId);
    if (!chatKey) return;

    try {
        const res = await fetch(`/api/chats/${chatId}/keys/missing-devices`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        if (!res.ok) return;
        const data = await res.json();
        const missing = data.devices || [];
        if (missing.length === 0) return;

        const grants = [];
        for (const device of missing) {
            const sealed = await E2ee.sealToPublicKey(chatKey, device.public_key);
            grants.push({ user_id: device.user_id, device_id: device.device_id, sealed_key: sealed });
        }
        await uploadChatKeyGrants(chatId, grants);
    } catch (e) {
        // Best-effort — never let a healing attempt block/fail the actual send.
    }
}

/** Reseals an already-shared chat key to a newly-added member's devices. */
async function distributeKeyToNewMember(chatId, newUserId) {
    const chatKey = await getChatKey(chatId);
    if (!chatKey) return;

    const deviceKeys = await fetchDeviceKeysForUser(newUserId);
    if (deviceKeys.length === 0) return;

    const grants = [];
    for (const device of deviceKeys) {
        const sealed = await E2ee.sealToPublicKey(chatKey, device.public_key);
        grants.push({ user_id: newUserId, device_id: device.device_id, sealed_key: sealed });
    }
    await uploadChatKeyGrants(chatId, grants);
}

/** Null means no key yet for this device — caller should treat the chat as not-yet-encrypted. */
async function getChatKey(chatId) {
    if (e2eeChatKeyCache.has(chatId)) return e2eeChatKeyCache.get(chatId);

    const stored = localStorage.getItem(`e2ee_chat_key_${chatId}`);
    if (stored) {
        const key = E2ee.fromBase64(stored);
        e2eeChatKeyCache.set(chatId, key);
        return key;
    }

    if (!e2eeDeviceId || !e2eePrivateKeyBase64) return null;
    try {
        const res = await fetch(`/api/chats/${chatId}/keys/mine?device_id=${encodeURIComponent(e2eeDeviceId)}`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        if (!res.ok) return null;
        const data = await res.json();
        if (!data.sealed_key) return null;
        const keyBytes = await E2ee.unseal(data.sealed_key, e2eePrivateKeyBase64);
        e2eeChatKeyCache.set(chatId, keyBytes);
        localStorage.setItem(`e2ee_chat_key_${chatId}`, E2ee.toBase64(keyBytes));
        return keyBytes;
    } catch (e) {
        return null;
    }
}

const e2eePendingKeyRequests = new Set();

/**
 * Like getChatKey, but when THIS device has no grant yet for a chat that's
 * already keyed — the exact state right after clearing browser storage or
 * logging in fresh — asks every other currently-connected device (across
 * every participant, including this same user's other sessions) to reseal
 * it right now, and waits briefly for the reply, instead of leaving every
 * message in the chat permanently stuck behind "Unable to decrypt this
 * message" until someone happens to send something new. Mirrors
 * E2eeService.ensureChatKeyAvailable in the Flutter app — web previously had
 * no equivalent of this pull-side recovery at all, only the (also newly
 * added) push-side healMissingGrants.
 */
async function ensureChatKeyAvailable(chatId) {
    let key = await getChatKey(chatId);
    if (key) return key;
    if (!(await chatHasEstablishedKey(chatId))) return null; // genuinely unencrypted chat — not an error

    if (!e2eePendingKeyRequests.has(chatId)) {
        e2eePendingKeyRequests.add(chatId);
        fetch(`/api/chats/${chatId}/keys/request`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        }).catch(() => {}).finally(() => e2eePendingKeyRequests.delete(chatId));
    }

    for (let i = 0; i < 4; i++) {
        await new Promise(resolve => setTimeout(resolve, 2000));
        key = await getChatKey(chatId);
        if (key) return key;
    }
    return null;
}

async function fetchDeviceKeysForUser(userId) {
    try {
        const res = await fetch(`/api/users/${userId}/device-keys`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await res.json();
        return data.device_keys || [];
    } catch (e) {
        return [];
    }
}

async function uploadChatKeyGrants(chatId, grants) {
    await fetch(`/api/chats/${chatId}/keys`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${window.API_TOKEN}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ grants })
    });
}

/** Returns null (leave as plaintext) if this chat has no key yet. */
async function tryEncryptForChat(chatId, plainText) {
    const key = await getChatKey(chatId);
    if (!key) return null;
    // Fire-and-forget: opportunistically repairs any participant device
    // missing a grant (see healMissingGrants) — never blocks or fails the
    // actual send.
    healMissingGrants(chatId);
    return E2ee.encryptMessage(plainText, key);
}

/**
 * Same never-show-raw-ciphertext rule as MessagesRepository.decryptIfNeeded
 * on the Flutter side — a placeholder instead of base64 gibberish whenever
 * decryption can't happen, whatever the reason.
 */
// Recurses into msg.quoted_message — a reply's quoted snippet is a full
// nested message with its own (possibly still-encrypted) content, and was
// previously never decrypted here at all, so the quoted-message block
// rendered raw base64 ciphertext for any reply to an encrypted message
// instead of the actual quoted text (same bug fixed in the Flutter app's
// message_decryptor.dart — decryptChatMessage).
async function decryptMessageIfNeeded(msg) {
    if (msg.quoted_message) {
        msg = { ...msg, quoted_message: await decryptMessageIfNeeded(msg.quoted_message) };
    }

    // metadata comes back as either a JSON string or an already-parsed
    // object depending on the endpoint — same defensive check used
    // elsewhere in this file (e.g. call-log message rendering).
    const meta = typeof msg.metadata === 'string' ? JSON.parse(msg.metadata || '{}') : (msg.metadata || {});
    const isEncrypted = meta.encrypted === true || meta.encrypted === 'true';
    if (!isEncrypted || !msg.content) return msg;
    try {
        const key = await ensureChatKeyAvailable(msg.chat_id);
        if (!key) return { ...msg, content: '🔒 Unable to decrypt this message' };
        const decrypted = await E2ee.decryptMessage(msg.content, key);
        return { ...msg, content: decrypted };
    } catch (e) {
        return { ...msg, content: '🔒 Unable to decrypt this message' };
    }
}

function sanitizeIntlAccountInput(raw) {
    if (!raw) return '';
    // Keep only digits and plus sign, remove spaces and any other formatting chars.
    let sanitized = raw.replace(/\s+/g, '').replace(/[^\d+]/g, '');
    // Allow plus only at the beginning.
    if (sanitized.includes('+')) {
        sanitized = (sanitized.startsWith('+') ? '+' : '') + sanitized.replace(/\+/g, '');
    }
    return sanitized;
}

function normalizePersonalRecipientAccount(rawValue) {
    const cleaned = sanitizeIntlAccountInput(rawValue);
    if (!cleaned) {
        return { isValid: false, number: '', reason: 'Recipient account is required.' };
    }

    // If intl-tel-input is available, normalize with selected dial code.
    if (recipientAccountIntlInput) {
        let candidate = cleaned;
        if (!candidate.startsWith('+')) {
            const countryData = recipientAccountIntlInput.getSelectedCountryData?.() || {};
            const dialCode = countryData.dialCode ? `+${countryData.dialCode}` : '';
            candidate = dialCode ? `${dialCode}${candidate}` : candidate;
        }

        try {
            recipientAccountIntlInput.setNumber(candidate);
        } catch (_) {
            // Ignore setNumber issues; fallback validation below will handle it.
        }

        const intlValid = typeof recipientAccountIntlInput.isValidNumber === 'function'
            ? recipientAccountIntlInput.isValidNumber()
            : false;
        const intlNumber = typeof recipientAccountIntlInput.getNumber === 'function'
            ? sanitizeIntlAccountInput(recipientAccountIntlInput.getNumber() || candidate)
            : candidate;

        if (intlValid && /^\+\d{9,15}$/.test(intlNumber)) {
            return { isValid: true, number: intlNumber, reason: '' };
        }

        // Fallback: accept E.164-like digit lengths even when intl utils are not fully loaded.
        if (/^\+\d{9,15}$/.test(intlNumber)) {
            return { isValid: true, number: intlNumber, reason: '' };
        }

        return { isValid: false, number: intlNumber, reason: 'Enter a valid international phone number for personal recipient account.' };
    }

    // Last-resort fallback without intl library instance.
    if (/^\+\d{9,15}$/.test(cleaned)) {
        return { isValid: true, number: cleaned, reason: '' };
    }

    return { isValid: false, number: cleaned, reason: 'Enter a valid international phone number for personal recipient account.' };
}

const audioContext = new (window.AudioContext || window.webkitAudioContext)();
function playNotificationSound() {
    const settings = JSON.parse(localStorage.getItem('samchats_settings') || '{"mute_sounds":false,"enter_send":true}');
    if (settings.mute_sounds) return;

    try {
        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
        oscillator.frequency.exponentialRampToValueAtTime(1200, audioContext.currentTime + 0.1);
        
        gainNode.gain.setValueAtTime(0, audioContext.currentTime);
        gainNode.gain.linearRampToValueAtTime(0.3, audioContext.currentTime + 0.02);
        gainNode.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.3);
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.start();
        oscillator.stop(audioContext.currentTime + 0.3);
    } catch (e) {
        console.error("Audio playback failed", e);
    }
}

let ringtoneInterval = null;
function startRingtone() {
    stopRingtone();
    const playRing = () => {
        try {
            if (audioContext.state === 'suspended') audioContext.resume();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(440, audioContext.currentTime); 
            oscillator.frequency.setValueAtTime(480, audioContext.currentTime + 0.1);
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.5, audioContext.currentTime + 0.05);
            gainNode.gain.setValueAtTime(0.5, audioContext.currentTime + 0.8);
            gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 1.0);
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 1.0);
            
            const osc2 = audioContext.createOscillator();
            const gain2 = audioContext.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(440, audioContext.currentTime + 1.2);
            osc2.frequency.setValueAtTime(480, audioContext.currentTime + 1.3);
            gain2.gain.setValueAtTime(0, audioContext.currentTime + 1.2);
            gain2.gain.linearRampToValueAtTime(0.5, audioContext.currentTime + 1.25);
            gain2.gain.setValueAtTime(0.5, audioContext.currentTime + 2.0);
            gain2.gain.linearRampToValueAtTime(0, audioContext.currentTime + 2.2);
            osc2.connect(gain2);
            gain2.connect(audioContext.destination);
            osc2.start(audioContext.currentTime + 1.2);
            osc2.stop(audioContext.currentTime + 2.2);
        } catch(e) { console.error("Ringtone failed", e); }
    };
    playRing();
    ringtoneInterval = setInterval(playRing, 4000);
}

function stopRingtone() {
    if (ringtoneInterval) {
        clearInterval(ringtoneInterval);
        ringtoneInterval = null;
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    const query = new URLSearchParams(window.location.search);
    if (query.get('sampay_linked') === '1') {
        alert('Sampay account linked successfully.');
        openPaymentsModal();
        window.history.replaceState({}, document.title, window.location.pathname);
    } else if (query.get('sampay_error')) {
        alert(`Sampay linking failed: ${query.get('sampay_error').replace(/_/g, ' ')}`);
        openPaymentsModal();
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // Theme setup
    const savedTheme = localStorage.getItem('theme') || 'light';
    if (savedTheme === 'dark') {
        document.body.classList.add('dark-theme');
        updateThemeIcon('dark');
    }

    Echo.join('app')
        .here((users) => {
            onlineUsers = new Set(users.map(u => u.id));
            fetchInbox();
            if (typeof updateActiveChatStatus === 'function') updateActiveChatStatus();
        })
        .joining((user) => {
            onlineUsers.add(user.id);
            fetchInbox();
            if (typeof updateActiveChatStatus === 'function') updateActiveChatStatus();
        })
        .leaving((user) => {
            onlineUsers.delete(user.id);
            fetchInbox();
            if (typeof updateActiveChatStatus === 'function') updateActiveChatStatus();
        });

    // Keep `last_seen_at` fresh the same way the mobile app's HeartbeatService
    // does, so mobile users can see this web session as online via the
    // `last_seen_at` fallback in isUserOnline() — the presence channel alone
    // only makes web-to-web sessions visible to each other.
    const sendHeartbeat = () => {
        fetch('/api/user/online', {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        }).catch(() => {});
    };
    sendHeartbeat();
    setInterval(sendHeartbeat, 60000);

    // Best-effort: generates this browser's E2EE keypair on first run (or
    // loads it from localStorage) and makes sure the backend has the
    // current public key. Never blocks page load — chats just stay
    // unencrypted until this lands.
    ensureDeviceRegistered().catch(e => console.error('E2EE device registration failed', e));

    window.SAVED_CONTACTS = {};
    fetchContacts().then(() => fetchInbox());
    
    document.getElementById('btn-send').addEventListener('click', sendMessage);
    
    const msgInput = document.getElementById('message-input');
    msgInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            const settings = JSON.parse(localStorage.getItem('samchats_settings') || '{"mute_sounds":false,"enter_send":true}');
            if (settings.enter_send && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        }
    });

    // Toggle Send vs Mic button, and notify the server (not just a Pusher
    // whisper) that we're typing, so the mobile app — which only listens for
    // the server-broadcast UserTyping event, not client whispers — sees it too.
    msgInput.addEventListener('input', () => {
        if (msgInput.value.trim().length > 0) {
            document.getElementById('btn-mic').style.display = 'none';
            document.getElementById('btn-send').style.display = 'flex';
        } else {
            document.getElementById('btn-mic').style.display = 'flex';
            document.getElementById('btn-send').style.display = 'none';
        }
        setTyping(msgInput.value.trim().length > 0);
    });

    const recipientAccountInput = document.getElementById('chat-payment-recipient-account');
    const recipientTypeInput = document.getElementById('chat-payment-recipient-type');
    if (recipientAccountInput && window.intlTelInput) {
        recipientAccountIntlInput = window.intlTelInput(recipientAccountInput, {
            initialCountry: 'zm',
            nationalMode: false,
            autoPlaceholder: 'aggressive',
            strictMode: false,
            formatAsYouType: true,
            separateDialCode: false,
            loadUtilsOnInit: 'https://cdn.jsdelivr.net/npm/intl-tel-input@24.5.0/build/js/utils.js'
        });
    }

    if (recipientTypeInput && recipientAccountInput) {
        recipientTypeInput.addEventListener('change', () => {
            const isBusiness = recipientTypeInput.value === 'business';
            if (isBusiness) {
                recipientAccountInput.type = 'text';
                recipientAccountInput.placeholder = 'Account #';
            } else {
                recipientAccountInput.type = 'tel';
                recipientAccountInput.placeholder = 'Account #';
            }
            recipientAccountInput.value = '';
        });

        recipientAccountInput.addEventListener('input', () => {
            if (recipientTypeInput.value !== 'personal') return;
            const cleaned = sanitizeIntlAccountInput(recipientAccountInput.value);
            if (cleaned !== recipientAccountInput.value) {
                const cursorPos = recipientAccountInput.selectionStart;
                recipientAccountInput.value = cleaned;
                if (cursorPos !== null) {
                    recipientAccountInput.setSelectionRange(Math.min(cursorPos, cleaned.length), Math.min(cursorPos, cleaned.length));
                }
            }
        });

        recipientAccountInput.addEventListener('blur', () => {
            if (recipientTypeInput.value !== 'personal') return;
            recipientAccountInput.value = sanitizeIntlAccountInput(recipientAccountInput.value);
        });
    }

    // Profile Settings
    document.getElementById('my-profile-pic').addEventListener('click', openProfileModal);
    document.getElementById('btn-close-profile').addEventListener('click', closeProfileModal);
    document.getElementById('btn-save-profile').addEventListener('click', saveProfile);

    // Search Logic
    const searchInput = document.getElementById('search-input');
    let searchTimeout = null;
    searchInput.addEventListener('input', (e) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => handleSearch(e.target.value), 400);
    });

    // New Chat Icon
    document.getElementById('btn-new-chat').addEventListener('click', () => {
        openNewChatPanel();
    });

    // Calls Panel
    document.getElementById('btn-calls').addEventListener('click', () => {
        openCallsPanel();
    });
    document.getElementById('btn-close-calls').addEventListener('click', () => {
        document.getElementById('calls-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('calls-panel').style.display = 'none', 300);
    });

    // Meetings Panel
    document.getElementById('btn-open-meetings').addEventListener('click', (e) => {
        e.stopPropagation();
        document.getElementById('sidebar-dropdown').classList.remove('active');
        openMeetingsPanel();
    });
    document.getElementById('btn-close-meetings').addEventListener('click', () => {
        document.getElementById('meetings-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('meetings-panel').style.display = 'none', 300);
    });
    document.getElementById('btn-schedule-meeting').addEventListener('click', openScheduleMeetingPanel);
    document.getElementById('btn-close-schedule-meeting').addEventListener('click', () => {
        document.getElementById('schedule-meeting-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('schedule-meeting-panel').style.display = 'none', 300);
    });
    document.getElementById('btn-submit-schedule-meeting').addEventListener('click', submitScheduleMeeting);

    // Email Panels
    refreshEmailUnreadBadge();
    document.getElementById('btn-open-email').addEventListener('click', openEmailAccountsPanel);
    document.getElementById('btn-close-email-accounts').addEventListener('click', () => {
        document.getElementById('email-accounts-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('email-accounts-panel').style.display = 'none', 300);
    });
    document.getElementById('btn-add-email-account').addEventListener('click', openConnectEmailPanel);
    document.getElementById('btn-close-connect-email').addEventListener('click', () => {
        document.getElementById('connect-email-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('connect-email-panel').style.display = 'none', 300);
    });
    document.getElementById('email-provider-input').addEventListener('change', updateEmailAppPasswordHelp);
    document.getElementById('btn-toggle-email-custom-fields').addEventListener('click', toggleEmailCustomFields);
    document.getElementById('email-address-input').addEventListener('input', () => {
        const isCustom = document.getElementById('email-provider-input').value === 'custom';
        const detailOpen = document.getElementById('email-custom-fields').style.display !== 'none';
        if (isCustom && !detailOpen) applyEmailPresetFromAddress();
    });
    document.getElementById('btn-submit-connect-email').addEventListener('click', submitConnectEmail);
    document.getElementById('btn-close-email-inbox').addEventListener('click', () => {
        document.getElementById('email-inbox-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('email-inbox-panel').style.display = 'none', 300);
    });
    document.getElementById('btn-compose-email').addEventListener('click', () => openComposeEmailPanel({}));
    document.getElementById('btn-refresh-email-inbox').addEventListener('click', () => {
        document.getElementById('email-inbox-list').innerHTML = '<div class="loading-text">Checking for new mail...</div>';
        loadEmailInboxPage(true);
    });
    document.getElementById('btn-close-email-detail').addEventListener('click', () => {
        document.getElementById('email-detail-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('email-detail-panel').style.display = 'none', 300);
    });
    document.getElementById('btn-close-compose-email').addEventListener('click', () => {
        document.getElementById('compose-email-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('compose-email-panel').style.display = 'none', 300);
    });
    document.getElementById('btn-submit-compose-email').addEventListener('click', submitComposeEmail);
    document.getElementById('btn-toggle-compose-cc').addEventListener('click', () => {
        const ccInput = document.getElementById('compose-email-cc');
        ccInput.style.display = ccInput.style.display === 'none' ? 'block' : 'none';
    });
    document.getElementById('btn-add-compose-attachment').addEventListener('click', () => {
        document.getElementById('compose-email-attachments-input').click();
    });
    document.getElementById('compose-email-attachments-input').addEventListener('change', (e) => {
        for (const file of e.target.files) {
            composeAttachments.push(file);
        }
        e.target.value = ''; // allow re-picking the same file again later
        renderComposeAttachments();
    });
    document.getElementById('btn-reply-mode-single').addEventListener('click', () => {
        currentReplyAllMode = false;
        updateReplyModeButtons();
    });
    document.getElementById('btn-reply-mode-all').addEventListener('click', () => {
        currentReplyAllMode = true;
        updateReplyModeButtons();
    });
    document.getElementById('email-inbox-list').addEventListener('scroll', () => {
        const list = document.getElementById('email-inbox-list');
        if (list.scrollTop + list.clientHeight >= list.scrollHeight - 200) {
            loadEmailInboxPage();
        }
    });

    document.getElementById('btn-clear-call-logs').addEventListener('click', async () => {
        if (!confirm("Are you sure you want to clear all call logs?")) return;
        await fetch('/api/calls', {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}` }
        });
        document.getElementById('call-logs-list').innerHTML = '<div class="loading-text">No recent updates</div>';
    });

    // Emoji Picker
    const emojiPicker = document.getElementById('emoji-picker');
    document.getElementById('btn-smiley').addEventListener('click', () => {
        document.getElementById('sticker-picker').style.display = 'none';
        emojiPicker.style.display = emojiPicker.style.display === 'none' ? 'block' : 'none';
    });
    emojiPicker.addEventListener('emoji-click', event => {
        msgInput.value += event.detail.unicode;
        msgInput.dispatchEvent(new Event('input')); // trigger toggle
    });

    // Sticker Picker — curated big-emoji tray (no sticker art assets exist;
    // kept identical to the mobile client's kStickerEmojis list for parity).
    const STICKER_EMOJIS = [
        '🎉', '❤️', '😂', '😍', '😢', '😮', '🙏', '🔥',
        '👍', '👏', '🥳', '😎', '🤔', '😴', '🤗', '😇',
        '🥰', '😜', '🤯', '💯', '✨', '🎂', '🌈', '☕',
    ];
    const stickerPicker = document.getElementById('sticker-picker');
    stickerPicker.style.display = 'none';
    STICKER_EMOJIS.forEach(emoji => {
        const btn = document.createElement('div');
        btn.textContent = emoji;
        btn.style.cssText = 'font-size: 28px; text-align: center; cursor: pointer; border-radius: 8px; padding: 4px 0;';
        btn.addEventListener('click', () => sendSticker(emoji));
        stickerPicker.appendChild(btn);
    });
    document.getElementById('btn-sticker').addEventListener('click', () => {
        emojiPicker.style.display = 'none';
        const isOpen = stickerPicker.style.display !== 'none';
        stickerPicker.style.display = isOpen ? 'none' : 'grid';
    });

    // File Uploads
    // Attachment Picker Menu
    document.getElementById('btn-paperclip').addEventListener('click', (e) => {
        e.stopPropagation();
        const menu = document.getElementById('attachment-menu');
        menu.style.display = menu.style.display === 'none' ? 'flex' : 'none';
    });

    const chatPaymentBtn = document.getElementById('btn-chat-payment');
    if (chatPaymentBtn) {
        chatPaymentBtn.addEventListener('click', () => {
            openChatPaymentModal();
        });
    }

    const closeChatPaymentBtn = document.getElementById('btn-close-chat-payment-modal');
    if (closeChatPaymentBtn) {
        closeChatPaymentBtn.addEventListener('click', () => {
            closeChatPaymentModal();
        });
    }

    const closeChatPaymentRecipientBtn = document.getElementById('btn-close-chat-payment-recipient-modal');
    if (closeChatPaymentRecipientBtn) {
        closeChatPaymentRecipientBtn.addEventListener('click', () => {
            closeChatPaymentRecipientModal();
        });
    }

    const submitChatPaymentBtn = document.getElementById('btn-submit-chat-payment');
    if (submitChatPaymentBtn) {
        submitChatPaymentBtn.addEventListener('click', async () => {
            setButtonBusy(submitChatPaymentBtn, true, 'Processing…');
            try {
                await submitChatPaymentRequest();
            } finally {
                setButtonBusy(submitChatPaymentBtn, false);
            }
        });
    }

    const chatPaymentPurposeSelect = document.getElementById('chat-payment-purpose');
    if (chatPaymentPurposeSelect) {
        chatPaymentPurposeSelect.addEventListener('change', () => {
            const otherGroup = document.getElementById('chat-payment-purpose-other-group');
            const otherInput = document.getElementById('chat-payment-purpose-other');
            if (chatPaymentPurposeSelect.value === 'other') {
                otherGroup.style.display = 'block';
                otherInput.focus();
            } else {
                otherGroup.style.display = 'none';
                otherInput.value = '';
            }
        });
    }

    const chatMessagesEl = document.getElementById('chat-messages');
    if (chatMessagesEl) {
        chatMessagesEl.addEventListener('click', async (event) => {
            const approveBtn = event.target.closest('.btn-payment-approve');
            if (approveBtn) {
                const messageId = approveBtn.getAttribute('data-message-id');
                setButtonBusy(approveBtn, true, 'Processing…');
                try {
                    await approveChatPaymentRequest(messageId);
                } finally {
                    setButtonBusy(approveBtn, false);
                }
                return;
            }

            const rejectBtn = event.target.closest('.btn-payment-reject');
            if (rejectBtn) {
                const messageId = rejectBtn.getAttribute('data-message-id');
                setButtonBusy(rejectBtn, true, 'Processing…');
                try {
                    await rejectChatPaymentRequest(messageId);
                } finally {
                    setButtonBusy(rejectBtn, false);
                }
            }
        });
    }

    document.addEventListener('click', (e) => {
        const menu = document.getElementById('attachment-menu');
        const btn = document.getElementById('btn-paperclip');
        if (menu && menu.style.display === 'flex' && !menu.contains(e.target) && !btn.contains(e.target)) {
            menu.style.display = 'none';
        }
    });

    document.getElementById('btn-attach-doc').addEventListener('click', () => {
        document.getElementById('attachment-menu').style.display = 'none';
        document.getElementById('file-upload-doc').click();
    });

    document.getElementById('btn-attach-media').addEventListener('click', () => {
        document.getElementById('attachment-menu').style.display = 'none';
        document.getElementById('file-upload-media').click();
    });

    let pendingAttachments = [];
    let activePreviewIndex = 0;

    const renderAttachmentPreview = () => {
        const panel = document.getElementById('attachment-preview-panel');
        const stage = document.getElementById('preview-stage');
        const thumbnailList = document.getElementById('preview-thumbnail-list');
        
        if (pendingAttachments.length === 0) {
            panel.style.display = 'none';
            return;
        }
        
        panel.style.display = 'flex';
        thumbnailList.innerHTML = '';
        
        const activeFile = pendingAttachments[activePreviewIndex].file;
        const activeType = pendingAttachments[activePreviewIndex].type;
        
        // Render main stage
        if (activeType === 'image') {
            stage.innerHTML = `<img src="${URL.createObjectURL(activeFile)}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px;">`;
        } else if (activeType === 'video') {
            stage.innerHTML = `<video controls src="${URL.createObjectURL(activeFile)}" style="max-width: 100%; max-height: 100%; border-radius: 8px; outline: none;"></video>`;
        } else {
            stage.innerHTML = `<div style="text-align: center; color: white;">
                <div style="font-size: 4rem; margin-bottom: 10px;">📄</div>
                <div style="font-size: 1.1rem; word-break: break-all;">${escapeHTML(activeFile.name)}</div>
                <div style="font-size: 0.9rem; color: var(--text-muted); margin-top: 5px;">${(activeFile.size / 1024 / 1024).toFixed(2)} MB</div>
            </div>`;
        }
        
        // Render thumbnails
        pendingAttachments.forEach((att, index) => {
            const thumb = document.createElement('div');
            thumb.className = `preview-thumbnail ${index === activePreviewIndex ? 'active' : ''}`;
            
            if (att.type === 'image') {
                thumb.innerHTML = `<img src="${URL.createObjectURL(att.file)}" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit;">`;
            } else if (att.type === 'video') {
                thumb.innerHTML = `<video src="${URL.createObjectURL(att.file)}" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit;"></video><div style="position: absolute; font-size: 1.5rem; color: white; background: rgba(0,0,0,0.3); width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border-radius: inherit;">▶</div>`;
            } else {
                thumb.innerHTML = `<div style="font-size: 1.5rem;">📄</div>`;
            }
            
            thumb.onclick = () => {
                activePreviewIndex = index;
                renderAttachmentPreview();
            };
            
            // Add a remove button to the thumbnail
            const removeBtn = document.createElement('div');
            removeBtn.innerHTML = '×';
            removeBtn.style.cssText = 'position: absolute; top: -5px; right: -5px; background: rgba(0,0,0,0.7); color: white; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; display: none;';
            thumb.appendChild(removeBtn);
            
            thumb.onmouseenter = () => removeBtn.style.display = 'flex';
            thumb.onmouseleave = () => removeBtn.style.display = 'none';
            
            removeBtn.onclick = (e) => {
                e.stopPropagation();
                pendingAttachments.splice(index, 1);
                if (activePreviewIndex >= pendingAttachments.length) activePreviewIndex = Math.max(0, pendingAttachments.length - 1);
                renderAttachmentPreview();
            };
            
            thumb.style.position = 'relative';
            thumbnailList.appendChild(thumb);
        });
    };

    const handleFileUpload = (e) => {
        const files = Array.from(e.target.files);
        if (!files.length) return;
        
        files.forEach(file => {
            let type = 'document';
            if (file.type.startsWith('image/')) type = 'image';
            else if (file.type.startsWith('video/')) type = 'video';
            
            pendingAttachments.push({ file, type });
        });
        
        activePreviewIndex = pendingAttachments.length - files.length; // Focus first newly added file
        renderAttachmentPreview();
        e.target.value = ''; // reset input
    };
    
    document.getElementById('btn-close-preview').addEventListener('click', () => {
        pendingAttachments = [];
        document.getElementById('attachment-preview-panel').style.display = 'none';
    });
    
    document.getElementById('btn-add-more-attachments').addEventListener('click', () => {
        // Trigger media by default if adding more, or we can just trigger the menu
        document.getElementById('attachment-menu').style.display = 'flex';
    });

    document.getElementById('btn-send-attachments').addEventListener('click', async () => {
        const attachmentsToSend = [...pendingAttachments];
        pendingAttachments = [];
        document.getElementById('attachment-preview-panel').style.display = 'none';
        
        for (const att of attachmentsToSend) {
            await uploadMedia(att.file, att.type);
        }
    });

    document.getElementById('file-upload-doc').addEventListener('change', handleFileUpload);
    document.getElementById('file-upload-media').addEventListener('change', handleFileUpload);

    // Voice Notes (Mic)
    let mediaRecorder = null;
    let audioChunks = [];
    let isRecordingRequested = false;
    let recordingStartTime = 0;
    const btnMic = document.getElementById('btn-mic');
    
    btnMic.addEventListener('mousedown', async (e) => {
        if (e.button !== 0) return;
        isRecordingRequested = true;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            if (!isRecordingRequested) {
                // Released before mic was ready
                stream.getTracks().forEach(track => track.stop());
                return;
            }
            mediaRecorder = new MediaRecorder(stream);
            recordingStartTime = Date.now();
            mediaRecorder.start();
            audioChunks = [];
            
            mediaRecorder.addEventListener("dataavailable", event => {
                if (event.data.size > 0) audioChunks.push(event.data);
            });
            
            mediaRecorder.addEventListener("stop", async () => {
                const duration = Date.now() - recordingStartTime;
                stream.getTracks().forEach(track => track.stop()); // release mic
                
                // If the recording is too short (less than 1 second), discard it
                if (duration < 1000 || audioChunks.length === 0) {
                    console.log("Recording too short, discarded.");
                    return;
                }
                
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                await uploadMedia(audioBlob, 'voice');
            });
            btnMic.style.color = '#FF5722'; // Recording color
        } catch(e) {
            console.error('Microphone access denied', e);
            isRecordingRequested = false;
        }
    });
    
    const stopRecording = () => {
        isRecordingRequested = false;
        if (mediaRecorder && mediaRecorder.state === "recording") {
            mediaRecorder.stop();
            btnMic.style.color = 'var(--text-icon)';
        }
    };

    btnMic.addEventListener('mouseup', stopRecording);
    btnMic.addEventListener('mouseleave', stopRecording);
    btnMic.addEventListener('touchend', stopRecording);
    btnMic.addEventListener('touchcancel', stopRecording);
    
    btnMic.addEventListener('touchstart', (e) => {
        e.preventDefault(); // Prevent duplicate mousedown
        btnMic.dispatchEvent(new MouseEvent('mousedown', { button: 0 }));
    }, { passive: false });

    // 3 Dots Menus & Dropdowns
    const sidebarDropdown = document.getElementById('sidebar-dropdown');
    document.getElementById('btn-sidebar-menu').addEventListener('click', (e) => {
        e.stopPropagation();
        chatDropdown.classList.remove('active');
        sidebarDropdown.classList.toggle('active');
    });
    
    // New Group Click
    document.getElementById('btn-new-group').addEventListener('click', (e) => {
        e.stopPropagation();
        sidebarDropdown.classList.remove('active');
        openNewGroupPanel();
    });

    const openPaymentsBtn = document.getElementById('btn-open-payments');
    if (openPaymentsBtn) {
        openPaymentsBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            openPaymentsModal();
            sidebarDropdown.classList.remove('active');
        });
    }

    const linkSampayBtn = document.getElementById('btn-link-sampay');
    if (linkSampayBtn) {
        linkSampayBtn.addEventListener('click', () => {
            linkSampay();
        });
    }

    const unlinkSampayBtn = document.getElementById('btn-unlink-sampay');
    if (unlinkSampayBtn) {
        unlinkSampayBtn.addEventListener('click', () => {
            unlinkSampay();
        });
    }

    const closePaymentsBtn = document.getElementById('btn-close-payments-modal');
    if (closePaymentsBtn) {
        closePaymentsBtn.addEventListener('click', () => {
            closePaymentsModal();
        });
    }

    const chatDropdown = document.getElementById('chat-dropdown');
    document.getElementById('btn-chat-menu').addEventListener('click', (e) => {
        e.stopPropagation();
        sidebarDropdown.classList.remove('active');
        chatDropdown.classList.toggle('active');
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', () => {
        sidebarDropdown.classList.remove('active');
        chatDropdown.classList.remove('active');
    });

    // Right Sidebar (Contact Info)
    document.getElementById('chat-header-info-box').addEventListener('click', openContactInfo);
    document.getElementById('btn-contact-info').addEventListener('click', openContactInfo);

    // Right Sidebar (In-Chat Search)
    document.getElementById('btn-chat-search').addEventListener('click', openChatSearch);

    document.getElementById('btn-close-right-sidebar').addEventListener('click', () => {
        document.getElementById('right-sidebar').style.display = 'none';
    });

    // Sidebar Filters
    document.querySelectorAll('.filter-pill').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
            e.target.classList.add('active');
            activeSidebarFilter = e.target.getAttribute('data-filter');
            renderChatList();
        });
    });
    
    // Group Photo Upload
    const groupPhotoInput = document.getElementById('group-photo-input');
    if (groupPhotoInput) {
        document.getElementById('btn-group-photo-upload').addEventListener('click', () => {
            groupPhotoInput.click();
        });
        groupPhotoInput.addEventListener('change', async (e) => {
            if (e.target.files && e.target.files[0] && loadedChatData && loadedChatData.chat_type === 'group') {
                const file = e.target.files[0];
                const formData = new FormData();
                formData.append('group_image', file);
                
                try {
                    const response = await fetch(`/api/chats/${loadedChatData.id}/group/image`, {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${window.API_TOKEN}`
                        },
                        body: formData
                    });
                    
                    if (response.ok) {
                        const data = await response.json();
                        document.getElementById('group-info-img').src = data.group_image_url;
                        // Refresh active chat header
                        document.getElementById('active-chat-img').src = data.group_image_url;
                        // Refresh inbox list
                        fetchInbox();
                    } else {
                        alert('Failed to update group image. You might not be the admin.');
                    }
                } catch (err) {
                    console.error('Error updating group image', err);
                }
            }
        });
    }

    // Leave Group
    const btnLeaveGroup = document.getElementById('btn-leave-group');
    if (btnLeaveGroup) {
        btnLeaveGroup.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to leave this group?')) return;
            try {
                const res = await fetch(`/api/chats/${currentChatId}/leave`, {
                    method: 'POST',
                    headers: { 'Authorization': `Bearer ${window.API_TOKEN}` }
                });
                if (res.ok) {
                    document.getElementById('right-sidebar').style.display = 'none';
                    document.getElementById('chat-panel').style.display = 'none';
                    document.getElementById('empty-state').style.display = 'flex';
                    fetchInbox();
                }
            } catch (err) { console.error('Failed to leave group', err); }
        });
    }

    // In-Chat Search logic
    document.getElementById('in-chat-search-input').addEventListener('input', (e) => {
        const query = e.target.value.toLowerCase();
        const messages = document.querySelectorAll('.message-row');
        let count = 0;
        
        messages.forEach(row => {
            const contentEl = row.querySelector('.message-content');
            if (contentEl) {
                const text = contentEl.textContent.toLowerCase();
                if (text.includes(query) && query.length > 0) {
                    row.style.display = 'flex';
                    row.style.background = 'rgba(0, 168, 132, 0.2)'; // Highlight
                    count++;
                } else if (query.length === 0) {
                    row.style.display = 'flex';
                    row.style.background = 'transparent';
                } else {
                    row.style.display = 'none';
                }
            }
        });
        
        const resultsEl = document.getElementById('in-chat-search-results');
        if (query.length === 0) resultsEl.innerText = "Search for messages in this chat.";
        else resultsEl.innerText = `${count} messages found.`;
    });

    // Chat Menu API logic
    
    // 2. Select Messages
    let isSelectionMode = false;
    
    document.querySelectorAll('.dropdown-item').forEach(el => {
        if (el.innerText === 'Select messages') {
            el.addEventListener('click', () => {
                document.getElementById('chat-dropdown').classList.remove('active');
                isSelectionMode = true;
                document.getElementById('chat-messages').classList.add('selection-mode');
                document.querySelector('.chat-composer').style.display = 'none';
                document.getElementById('selection-action-bar').style.display = 'flex';
                updateSelectionCount();
            });
        }
        if (el.innerText === 'Close chat') {
            el.addEventListener('click', () => {
                document.getElementById('chat-dropdown').classList.remove('active');
                document.getElementById('chat-panel').style.display = 'none';
                document.getElementById('empty-state').style.display = 'flex';
                document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
                currentChatId = null;
                if (window.echoChannel) {
                    window.Echo.leave(`chat.${window.echoChannel}`);
                }
            });
        }
        if (el.innerText === 'Delete chat') {
            el.addEventListener('click', async () => {
                document.getElementById('chat-dropdown').classList.remove('active');
                if (!currentChatId) return;
                if (!confirm("Delete this entire chat? This cannot be undone.")) return;
                
                try {
                    await fetch(`/api/chats/${currentChatId}`, {
                        method: 'DELETE',
                        headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
                    });
                    document.getElementById('chat-panel').style.display = 'none';
                    document.getElementById('empty-state').style.display = 'flex';
                    currentChatId = null;
                    fetchInbox();
                } catch(e) {
                    console.error("Failed to delete chat", e);
                }
            });
        }
    });

    document.getElementById('chat-messages').addEventListener('click', (e) => {
        if (isSelectionMode) {
            const row = e.target.closest('.message-row');
            if (row) {
                const cb = row.querySelector('.msg-select-checkbox');
                if (cb && e.target !== cb) {
                    cb.checked = !cb.checked;
                }
                updateSelectionCount();
            }
        }
    });

    function updateSelectionCount() {
        const count = document.querySelectorAll('.msg-select-checkbox:checked').length;
        document.getElementById('selection-count').innerText = count;
    }

    document.getElementById('btn-cancel-selection').addEventListener('click', () => {
        isSelectionMode = false;
        document.getElementById('chat-messages').classList.remove('selection-mode');
        document.querySelector('.chat-composer').style.display = 'flex';
        document.getElementById('selection-action-bar').style.display = 'none';
        document.querySelectorAll('.msg-select-checkbox').forEach(cb => cb.checked = false);
    });

    document.getElementById('btn-delete-selection').addEventListener('click', async () => {
        const checked = Array.from(document.querySelectorAll('.msg-select-checkbox:checked'));
        if (checked.length === 0) return;
        
        const type = confirm("Delete for everyone? (Click OK for Everyone, Cancel for just Me)") ? 'everyone' : 'me';
        const messageIds = checked.map(cb => cb.dataset.msgId);
        
        try {
            await fetch(`/api/chats/${currentChatId}/messages/bulk`, {
                method: 'DELETE',
                headers: { 
                    'Authorization': `Bearer ${window.API_TOKEN}`, 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json' 
                },
                body: JSON.stringify({ message_ids: messageIds, type: type })
            });
            
            checked.forEach(cb => {
                const row = cb.closest('.message-row');
                if (row) row.remove();
            });
            
            document.getElementById('btn-cancel-selection').click(); // exit selection mode
        } catch(e) {
            console.error("Failed to bulk delete", e);
        }
    });

    // Clear Chat API logic
    document.getElementById('btn-clear-chat').addEventListener('click', async () => {
        if (!currentChatId) return;
        if (!confirm("Are you sure you want to clear all messages in this chat?")) return;
        
        try {
            await fetch(`/api/chats/${currentChatId}/messages`, {
                method: 'DELETE',
                headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
            });
            document.getElementById('chat-messages').innerHTML = ''; // Wipe UI instantly
            lastMessageData = null;
            lastMessageNode = null;
            fetchInbox(); // Refresh inbox to show cleared last message
        } catch(e) {
            console.error("Failed to clear chat", e);
        }
    });

    document.getElementById('btn-block-user').addEventListener('click', () => {
        if (!loadedChatData || loadedChatData.chat_type !== 'direct') return;
        if (loadedChatData.blocked_by_me) {
            unblockUser();
        } else {
            if (confirm("Are you sure you want to block this user?")) {
                blockUser();
            }
        }
        document.getElementById('chat-dropdown').classList.remove('active');
    });

    async function blockUser() {
        const otherUser = loadedChatData.participants.find(p => p.user_id !== window.APP_USER.id);
        if (!otherUser) return;
        
        try {
            await fetch(`/api/users/${otherUser.user_id}/block`, {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
            });
            blockedUsersSet.add(otherUser.user_id);
            // Reload chat to refresh UI state
            loadChat(currentChatId, document.getElementById('active-chat-name').innerText, currentChatType, loadedChatData);
            fetchInbox();
            updateActiveChatStatus();
        } catch(e) {
            console.error("Failed to block user", e);
        }
    }

    async function unblockUser() {
        const otherUser = loadedChatData.participants.find(p => p.user_id !== window.APP_USER.id);
        if (!otherUser) return;
        
        try {
            await fetch(`/api/users/${otherUser.user_id}/block`, {
                method: 'DELETE',
                headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
            });
            blockedUsersSet.delete(otherUser.user_id);
            // Reload chat to refresh UI state
            loadChat(currentChatId, document.getElementById('active-chat-name').innerText, currentChatType, loadedChatData);
            fetchInbox();
            updateActiveChatStatus();
        } catch(e) {
            console.error("Failed to unblock user", e);
        }
    }

    // Status Overlay Logic
    document.getElementById('btn-status').addEventListener('click', () => {
        document.getElementById('status-overlay').style.display = 'flex';
        fetchStatuses();
    });
    document.getElementById('btn-close-status').addEventListener('click', () => {
        document.getElementById('status-overlay').style.display = 'none';
        stopStatusViewer();
    });
    
    // Initialize Status UI logic (binding all modal/viewer buttons)
    initStatusUI();

    // Calling Logic
    document.getElementById('btn-chat-phone').addEventListener('click', () => initiateCall('audio'));
    document.getElementById('btn-chat-video').addEventListener('click', () => initiateCall('video'));
    
    document.getElementById('btn-call-decline').addEventListener('click', declineCall);
    document.getElementById('btn-call-accept').addEventListener('click', acceptCall);
    document.getElementById('btn-call-end').addEventListener('click', endCall);
    document.getElementById('btn-call-mute').addEventListener('click', toggleMute);
    
    // Listen for incoming calls globally
    Echo.private(`user.${window.APP_USER.id}`)
        .listen('IncomingCall', (e) => handleIncomingCall(e.call))
        .listen('CallAnswered', (e) => handleCallAnswered(e.call))
        .listen('CallDeclined', (e) => handleCallDeclined(e.call))
        .listen('MessageSent', (e) => {
            if (e.message.sender_id !== window.APP_USER.id) {
                playNotificationSound();
            }
            fetchInbox(); // Refresh to update last message and re-sort
        })
        .listen('UserTyping', (e) => {
            // Drives the "typing…" chat-list preview for chats other than the
            // one currently open (that one's handled by listenToChat's own
            // UserTyping binding on the chat-specific channel).
            if (e.user_id === window.APP_USER.id || !e.chat_id) return;
            if (e.is_typing) {
                typingChats.add(e.chat_id);
            } else {
                typingChats.delete(e.chat_id);
            }
            renderChatList();
        })
        .listen('ChatKeyGrantRequested', (e) => {
            // Another of this chat's devices (often this very user, on
            // their phone or another tab) is asking to be resealed —
            // web previously never listened for this at all, so it could
            // only ever be healed by luck (someone unrelated sending a new
            // message). No-op if this device doesn't hold the chat's key
            // itself, including hearing its own request echoed back.
            healMissingGrants(e.chat_id);
        })
        .listen('NewEmailReceived', (e) => {
            // Silently refresh the inbox list if it's open for the account
            // that just got new mail — no toast, matches how MessageSent
            // just triggers fetchInbox() above rather than alerting. The
            // header badge refreshes regardless of which panel is open.
            if (currentEmailAccount && currentEmailAccount.id === e.email_account_id) {
                loadEmailInboxPage(true);
            }
            refreshEmailUnreadBadge();
        });

    // New Group Panel Logic
    document.getElementById('btn-close-new-group').addEventListener('click', () => {
        document.getElementById('new-group-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('new-group-panel').style.display = 'none', 300);
    });
    document.getElementById('btn-close-group-name').addEventListener('click', () => {
        document.getElementById('new-group-name-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('new-group-name-panel').style.display = 'none', 300);
    });
    document.getElementById('btn-new-group-next').addEventListener('click', () => {
        document.getElementById('new-group-name-panel').style.display = 'flex';
        setTimeout(() => document.getElementById('new-group-name-panel').style.transform = 'translateX(0)', 10);
        document.getElementById('new-group-name-input').focus();
    });
    document.getElementById('btn-create-group-submit').addEventListener('click', createGroupSubmit);

    // New Chat Panel Logic
    document.getElementById('btn-close-new-chat').addEventListener('click', () => {
        document.getElementById('new-chat-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('new-chat-panel').style.display = 'none', 300);
    });

    // Theme Toggle Logic
    document.getElementById('btn-theme-toggle').addEventListener('click', () => {
        const isDark = document.body.classList.toggle('dark-theme');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark ? 'dark' : 'light');
    });
    
    // New Chat Search Filtering
    // New Chat Search Filtering via API
    let newChatSearchTimeout = null;
    document.getElementById('new-chat-search').addEventListener('input', (e) => {
        clearTimeout(newChatSearchTimeout);
        const query = e.target.value.trim();
        newChatSearchTimeout = setTimeout(() => {
            const listEl = document.getElementById('new-chat-contact-list');
            listEl.innerHTML = '<div class="loading-text">Searching...</div>';
            
            fetch(`/api/users/search?q=${encodeURIComponent(query)}`, {
                headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(data => {
                listEl.innerHTML = '';
                if (data.users.length === 0) {
                    listEl.innerHTML = '<div class="loading-text">No matches found.</div>';
                    return;
                }
                data.users.forEach(user => {
                    if (user.id === window.APP_USER.id) return;
                    const item = document.createElement('div');
                    item.className = 'chat-item contact-item';
                    item.onclick = () => {
                        document.getElementById('new-chat-panel').style.transform = 'translateX(-100%)';
                        setTimeout(() => document.getElementById('new-chat-panel').style.display = 'none', 300);
                        createChat(user.id, getUserDisplayName(user));
                    };
                    
                    item.innerHTML = `
                        <div class="chat-item-pic-wrapper">
                            <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}" class="chat-item-pic">
                        </div>
                        <div class="chat-item-content">
                            <div class="chat-item-header">
                                <span class="chat-name">${escapeHTML(getUserDisplayName(user))}</span>
                            </div>
                            <div class="chat-item-msg">@${escapeHTML(user.username)}</div>
                        </div>
                    `;
                    listEl.appendChild(item);
                });
            })
            .catch(err => {
                console.error(err);
                listEl.innerHTML = '<div class="loading-text text-danger">Search failed.</div>';
            });
        }, 400);
    });
});

function updateThemeIcon(theme) {
    const svg = document.querySelector('#btn-theme-toggle svg');
    if (!svg) return;
    
    if (theme === 'dark') {
        // Sun icon for dark mode (click to go light)
        svg.innerHTML = `<circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>`;
    } else {
        // Moon icon for light mode (click to go dark)
        svg.innerHTML = `<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>`;
    }
}

async function openNewGroupPanel() {
    selectedGroupUsers = [];
    const nextBtn = document.getElementById('btn-new-group-next');
    nextBtn.classList.remove('visible');
    
    const panel = document.getElementById('new-group-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);
    
    const listEl = document.getElementById('new-group-contact-list');
    listEl.innerHTML = '<div class="loading-text">Loading contacts...</div>';
    
    try {
        const response = await fetch(`/api/users/search?q=`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        listEl.innerHTML = '';
        
        data.users.forEach(user => {
            if (user.id === window.APP_USER.id) return; // Skip self

            const item = document.createElement('div');
            item.className = 'chat-item contact-item';
            item.onclick = () => {
                const idx = selectedGroupUsers.indexOf(user.id);
                if (idx > -1) {
                    selectedGroupUsers.splice(idx, 1);
                    item.classList.remove('selected');
                    item.querySelector('.contact-item-checkbox').innerHTML = '';
                } else {
                    selectedGroupUsers.push(user.id);
                    item.classList.add('selected');
                    item.querySelector('.contact-item-checkbox').innerHTML = '✓';
                }
                
                if (selectedGroupUsers.length > 0) {
                    nextBtn.classList.add('visible');
                } else {
                    nextBtn.classList.remove('visible');
                }
            };
            
            item.innerHTML = `
                <div class="contact-item-checkbox"></div>
                <div class="chat-item-pic-wrapper">
                    <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}" class="chat-item-pic">
                </div>
                <div class="chat-info">
                    <div class="chat-info-top">
                        <span class="chat-name">${escapeHTML(getUserDisplayName(user))}</span>
                    </div>
                    <div class="chat-info-bottom">
                        <span class="chat-last-msg">${escapeHTML(user.about_status || 'Available')}</span>
                    </div>
                </div>
            `;
            listEl.appendChild(item);
        });
    } catch(e) {
        listEl.innerHTML = '<div class="loading-text text-danger">Failed to load contacts.</div>';
    }
}

async function openNewChatPanel() {
    const panel = document.getElementById('new-chat-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);
    
    const listEl = document.getElementById('new-chat-contact-list');
    listEl.innerHTML = '<div class="loading-text">Loading contacts...</div>';
    
    try {
        const response = await fetch(`/api/users/search?q=`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        listEl.innerHTML = '';
        
        data.users.forEach(user => {
            if (user.id === window.APP_USER.id) return;
            const item = document.createElement('div');
            item.className = 'chat-item contact-item';
            item.onclick = () => {
                document.getElementById('new-chat-panel').style.transform = 'translateX(-100%)';
                setTimeout(() => document.getElementById('new-chat-panel').style.display = 'none', 300);
                createChat(user.id, getUserDisplayName(user));
            };
            
            item.innerHTML = `
                <div class="chat-item-pic-wrapper">
                    <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}" class="chat-item-pic">
                </div>
                <div class="chat-info" style="border:none;">
                    <div class="chat-info-top">
                        <span class="chat-name">${escapeHTML(getUserDisplayName(user))}</span>
                    </div>
                    <div class="chat-info-bottom">
                        <span class="chat-last-msg">${escapeHTML(user.about_status || 'Available')}</span>
                    </div>
                </div>
            `;
            listEl.appendChild(item);
        });
    } catch(e) {
        listEl.innerHTML = '<div class="loading-text text-danger">Failed to load contacts.</div>';
    }
}

async function createGroupSubmit() {
    const nameInput = document.getElementById('new-group-name-input');
    const groupName = nameInput.value.trim();
    if (!groupName || selectedGroupUsers.length === 0) return;
    
    try {
        const response = await fetch(`/api/groups`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ group_name: groupName, user_ids: selectedGroupUsers })
        });
        
        const data = await response.json();
        if (response.ok) {
            // Close panels
            document.getElementById('new-group-name-panel').style.transform = 'translateX(-100%)';
            document.getElementById('new-group-panel').style.transform = 'translateX(-100%)';
            setTimeout(() => {
                document.getElementById('new-group-name-panel').style.display = 'none';
                document.getElementById('new-group-panel').style.display = 'none';
            }, 300);
            nameInput.value = '';
            
            await fetchInbox();
            loadChat(data.chat.id, groupName, 'group', data.chat);
        }
    } catch (e) {
        console.error("Create group failed", e);
    }
}

function openContactInfo() {
    const paneInfo = document.getElementById('pane-contact-info');
    const paneGroup = document.getElementById('pane-group-info');
    const paneSearch = document.getElementById('pane-chat-search');
    const sidebar = document.getElementById('right-sidebar');
    
    paneSearch.style.display = 'none';
    sidebar.style.display = 'flex';
    document.getElementById('chat-dropdown').classList.remove('active');

    if (loadedChatData.chat_type === 'group') {
        document.getElementById('right-sidebar-title').innerText = "Group info";
        paneInfo.style.display = 'none';
        paneGroup.style.display = 'flex';
        
        const group = loadedChatData.group;
        document.getElementById('group-info-name').innerText = group?.group_name || 'Group';
        document.getElementById('group-info-meta').innerText = `Group • ${loadedChatData.participants?.length || 0} participants`;
        document.getElementById('group-member-count').innerText = loadedChatData.participants?.length || 0;
        document.getElementById('group-info-img').src = group?.group_image_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(group?.group_name || 'Group')}&background=FF5722&color=fff`;
        
        // Find if current user is admin from pivot
        const currentUserParticipant = loadedChatData.participants?.find(p => p.user_id === window.APP_USER.id);
        const isAdmin = currentUserParticipant ? currentUserParticipant.is_admin : false;
        
        document.getElementById('btn-group-photo-upload').style.display = isAdmin ? 'flex' : 'none';
        document.getElementById('btn-add-participant').style.display = isAdmin ? 'flex' : 'none';
        
        // Populate Members List
        const membersList = document.getElementById('group-members-list');
        membersList.innerHTML = '';
        if (loadedChatData.participants) {
            loadedChatData.participants.forEach(p => {
                const user = p.user;
                const isMe = user.id === window.APP_USER.id;
                const isMemberAdmin = p.is_admin;
                
                const item = document.createElement('div');
                item.className = 'participant-item';
                
                let actionsHTML = '';
                if (isAdmin && !isMe) {
                    actionsHTML = `
                        <div class="participant-actions" onclick="toggleParticipantMenu(event, '${user.id}')">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"></path></svg>
                        </div>
                        <div id="participant-menu-${user.id}" class="participant-menu" style="display: none;">
                            <div class="participant-menu-item" onclick="updateParticipantRole('${user.id}', ${!isMemberAdmin})">
                                ${isMemberAdmin ? 'Remove Admin' : 'Make Admin'}
                            </div>
                            <div class="participant-menu-item text-danger" onclick="kickParticipant('${user.id}')">
                                Remove User
                            </div>
                        </div>
                    `;
                }
                
                item.innerHTML = `
                    <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}" class="group-member-pic" style="width:40px; height:40px; border-radius:50%; margin-right:12px;">
                    <div class="group-member-info" style="flex:1;">
                        <div class="group-member-name" style="font-weight:500;">
                            ${isMe ? 'You' : escapeHTML(getUserDisplayName(user))}
                            ${isMemberAdmin ? '<span class="admin-badge">Admin</span>' : ''}
                        </div>
                        <div class="group-member-role" style="font-size:0.8rem; color:var(--text-muted);">${escapeHTML(user.about_status || 'Available')}</div>
                    </div>
                    ${actionsHTML}
                `;
                membersList.appendChild(item);
            });
        }
        return;
    }
    
    // Direct Chat
    document.getElementById('right-sidebar-title').innerText = "Contact info";
    paneGroup.style.display = 'none';
    paneInfo.style.display = 'flex';
    
    let targetUser = null;
    if (loadedChatData.chat_type === 'direct') {
        targetUser = loadedChatData.participants.find(p => p.user_id !== window.APP_USER.id)?.user;
    }
    
    if (targetUser) {
        document.getElementById('contact-info-name').innerText = getUserDisplayName(targetUser) || targetUser.phone_number;
        document.getElementById('contact-info-phone').innerText = targetUser.phone_number || '';
        document.getElementById('contact-info-about').innerText = targetUser.about_status || 'Available';
        const contactInfoImg = document.getElementById('contact-info-img');
        contactInfoImg.src = targetUser.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(targetUser))}&background=FF5722&color=fff`;
        // Reset to the default ring first — this element is reused across
        // contacts, so a previous "unviewed status" ring must not bleed in.
        contactInfoImg.style.border = '3px solid var(--border-line)';
        fetch(`/api/users/${targetUser.id}/online-status`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        })
            .then(r => r.json())
            .then(data => {
                if (data.has_unviewed_status && document.getElementById('contact-info-img') === contactInfoImg) {
                    contactInfoImg.style.border = '3px solid var(--primary-accent, #FF5722)';
                }
            })
            .catch(() => {});

        // Handle Contact Saving
        const contactSaveInput = document.getElementById('contact-save-name');
        const contactSaveBtn = document.getElementById('btn-save-contact');
        if (contactSaveInput && contactSaveBtn) {
            contactSaveInput.value = window.SAVED_CONTACTS[targetUser.id] || '';
            contactSaveBtn.onclick = async () => {
                const newName = contactSaveInput.value.trim();
                const originalText = contactSaveBtn.innerText;
                contactSaveBtn.innerText = '...';
                contactSaveBtn.disabled = true;
                
                try {
                    if (newName) {
                        await fetch('/api/contacts', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Authorization': `Bearer ${window.API_TOKEN}`,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                contact_user_id: targetUser.id,
                                custom_name: newName
                            })
                        });
                        window.SAVED_CONTACTS[targetUser.id] = newName;
                    } else if (window.SAVED_CONTACTS[targetUser.id]) {
                        // User wants to remove the saved contact by clearing the name.
                        // We need the contact ID, but we only have user_id in SAVED_CONTACTS map right now.
                        // Actually, if we fetch contacts and index by user_id, we'd need the primary key `id` to delete.
                        // Since I didn't store the primary key, let's just make clearing the input do nothing or we'd need an endpoint like DELETE /api/contacts/by-user/{id}.
                        // For now, if they clear it, just revert.
                        console.warn("Clearing name is not fully supported yet without primary key, reverting.");
                        contactSaveInput.value = window.SAVED_CONTACTS[targetUser.id];
                    }
                    
                    // Refresh views
                    document.getElementById('contact-info-name').innerText = getUserDisplayName(targetUser) || targetUser.phone_number;
                    renderMessages(loadedChatData.messages);
                    fetchInbox(); // Refresh sidebar names
                    
                    contactSaveBtn.innerText = 'Saved!';
                    setTimeout(() => {
                        contactSaveBtn.innerText = 'Save';
                        contactSaveBtn.disabled = false;
                    }, 2000);
                } catch (e) {
                    console.error("Failed to save contact", e);
                    contactSaveBtn.innerText = 'Error';
                    setTimeout(() => {
                        contactSaveBtn.innerText = 'Save';
                        contactSaveBtn.disabled = false;
                    }, 2000);
                }
            };
        }
        
        const blockContainer = document.getElementById('ci-block-btn-container');
        if (blockContainer) {
            blockContainer.style.display = 'flex';
            const textEl = document.getElementById('ci-block-text');
            if (isBlocked) {
                textEl.innerText = 'Unblock User';
                blockContainer.style.color = 'var(--text-muted)';
            } else {
                textEl.innerText = 'Block User';
                blockContainer.style.color = '#ef4444';
            }
            
            // Add click listener exactly once
            blockContainer.onclick = () => {
                const btnBlock = document.getElementById('btn-block-user');
                if (btnBlock) btnBlock.click();
                setTimeout(() => openContactInfo(), 500); // refresh the panel state
            };
        }
    }
}

function openChatSearch() {
    const paneInfo = document.getElementById('pane-contact-info');
    const paneSearch = document.getElementById('pane-chat-search');
    const sidebar = document.getElementById('right-sidebar');
    
    document.getElementById('right-sidebar-title').innerText = "Search messages";
    paneInfo.style.display = 'none';
    paneSearch.style.display = 'block';
    sidebar.style.display = 'flex';
    
    document.getElementById('in-chat-search-input').focus();
}

async function uploadMedia(fileOrBlob, messageType) {
    if (!currentChatId) return;
    
    const formData = new FormData();
    formData.append('message_type', messageType);
    
    // If it's a voice note Blob, give it a filename so the backend validates it as a file
    if (messageType === 'voice') {
        formData.append('attachment', fileOrBlob, 'voice_note.webm');
    } else {
        formData.append('attachment', fileOrBlob);
    }
    
    try {
        const response = await fetch(`/api/chats/${currentChatId}/messages`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            },
            body: formData // Note: no Content-Type header for FormData, browser sets it
        });
        
        const data = await response.json();
        appendMessage(data.message);
        setTimeout(() => {
            const tickEl = document.getElementById(`tick-${data.message.id}`);
            if(tickEl) { tickEl.className = 'tick delivered'; tickEl.innerHTML = '✓✓'; }
        }, 500);
    } catch (e) {
        console.error('Upload failed', e);
    }
}

async function fetchInbox() {
    try {
        const response = await fetch('/api/chats', {
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });
        const data = await response.json();
        
        if (data.blocked_user_ids) {
            blockedUsersSet = new Set(data.blocked_user_ids);
        }
        
        const listEl = document.getElementById('chat-list');
        const dashGrid = document.getElementById('dashboard-chat-grid');
        listEl.innerHTML = '';
        data.chats.sort((a, b) => {
            const timeA = a.last_message_at ? new Date(a.last_message_at).getTime() : 0;
            const timeB = b.last_message_at ? new Date(b.last_message_at).getTime() : 0;
            return timeB - timeA;
        });

        // Same decrypt-at-the-boundary rule as the chat detail view
        // (decryptMessageIfNeeded) — the sidebar's last-message preview is a
        // separate fetch that previously skipped it entirely, so an
        // encrypted chat's preview rendered as raw ciphertext even though
        // opening the chat itself decrypted fine.
        loadedInboxChats = await Promise.all(data.chats.map(async chat => {
            if (chat.messages && chat.messages.length) {
                chat.messages[0] = await decryptMessageIfNeeded(chat.messages[0]);
            }
            return chat;
        }));
        renderChatList();

        // Simply opening the app now heals every chat this device already
        // holds a key for — mirrors the mobile inbox fix. No-op per chat
        // if this device doesn't hold that chat's key.
        loadedInboxChats.forEach(chat => healMissingGrants(chat.id));
    } catch (err) {
        console.error('Failed to fetch inbox', err);
    }
}

function renderChatList() {
    const listEl = document.getElementById('chat-list');
    const dashGrid = document.getElementById('dashboard-chat-grid');
    if (!listEl) return;
    
    listEl.innerHTML = '';
    if (dashGrid) dashGrid.innerHTML = '';
    
    let filteredChats = loadedInboxChats;
    if (activeSidebarFilter === 'unread') {
        filteredChats = loadedInboxChats.filter(c => {
            const myParticipant = c.participants ? c.participants.find(p => p.user_id === window.APP_USER.id) : null;
            return myParticipant && myParticipant.unread_count > 0;
        });
    } else if (activeSidebarFilter === 'groups') {
        filteredChats = loadedInboxChats.filter(c => c.chat_type === 'group');
    }

    if (filteredChats.length === 0) {
        listEl.innerHTML = '<div class="loading-text">No chats found for this filter.</div>';
        return;
    }

    filteredChats.forEach(chat => {
        let lastMsg = 'No messages';
        if (chat.messages && chat.messages.length) {
            const msg = chat.messages[0];
            if (msg.message_type === 'call_log') {
                const meta = typeof msg.metadata === 'string' ? JSON.parse(msg.metadata) : msg.metadata;
                lastMsg = meta.call_type === 'video' ? '📹 Video call' : '📞 Voice call';
            } else {
                lastMsg = msg.content || msg.message_type;
            }
        }
        const isChatTyping = typingChats.has(chat.id);

        let name = 'Direct Message';
        let otherParticipant = null;
        if (chat.chat_type === 'group') {
            name = chat.group?.group_name;
        } else if (chat.participants) {
            otherParticipant = chat.participants.find(p => p.user_id !== window.APP_USER.id);
            if (otherParticipant) name = getUserDisplayName(otherParticipant.user);
        }

        const avatarUrl = chat.chat_type === 'group' 
            ? (chat.group?.group_image_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(name || 'Group')}&background=FF5722&color=fff`)
            : ((otherParticipant?.user?.photo_url) ? otherParticipant.user.photo_url : `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=FF5722&color=fff`);
        
        const time = chat.last_message_at ? new Date(chat.last_message_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '';
        
        const isOnline = chat.chat_type === 'direct' && otherParticipant && isUserOnline(otherParticipant.user) && !blockedUsersSet.has(otherParticipant.user_id) ? '<div class="online-dot"></div>' : '';
        
        const myParticipant = chat.participants ? chat.participants.find(p => p.user_id === window.APP_USER.id) : null;
        const unreadCount = myParticipant ? myParticipant.unread_count : 0;
        const unreadBadge = unreadCount > 0 ? `<div class="unread-badge">${unreadCount}</div>` : '';

        const item = document.createElement('div');
        item.className = 'chat-item';
        item.id = `chat-list-item-${chat.id}`;
        if (currentChatId === chat.id) item.classList.add('active');
        item.onclick = () => {
            const badge = item.querySelector('.unread-badge');
            if (badge) badge.remove();
            loadChat(chat.id, name, chat.chat_type, chat);
        };
        
        const lastMsgHtml = isChatTyping
            ? '<span style="font-style: italic; color: var(--primary-accent, #FF5722);">typing…</span>'
            : escapeHTML(lastMsg);

        item.innerHTML = `
            <div class="chat-item-pic-wrapper">
                <img src="${avatarUrl}" class="chat-item-pic">
                ${isOnline}
            </div>
            <div class="chat-info">
                <div class="chat-info-top">
                    <span class="chat-name">${escapeHTML(name)}</span>
                    <span class="chat-time">${time}</span>
                </div>
                <div class="chat-info-bottom">
                    <span class="chat-last-msg">${lastMsgHtml}</span>
                    ${unreadBadge}
                </div>
            </div>
        `;
        listEl.appendChild(item);

        if (dashGrid) {
            const card = document.createElement('div');
            card.className = 'dashboard-card';
            card.onclick = () => loadChat(chat.id, name, chat.chat_type, chat);
            card.innerHTML = `
                <div class="chat-item-pic-wrapper" style="margin-bottom: 16px;">
                    <img src="${avatarUrl}" class="chat-item-pic" style="width: 64px; height: 64px;">
                    ${isOnline}
                </div>
                <div class="dashboard-card-info" style="display: flex; flex-direction: column; flex: 1; justify-content: center; width: 100%;">
                    <h3 class="dashboard-card-name" style="margin: 0 0 6px 0; font-size: 1.05rem; font-weight: 600; color: var(--text-primary);">${escapeHTML(name)}</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">${lastMsgHtml}</p>
                </div>
                <div style="margin-top: auto; padding-top: 16px; font-size: 0.75rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">
                    ${time}
                </div>
            `;
            dashGrid.appendChild(card);
        }
    });
}

async function loadChat(chatId, chatName, chatType = 'direct', chatData = null) {
    currentChatId = chatId;
    currentChatType = chatType;
    loadedChatData = chatData;
    
    // UI Update
    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('chat-panel').style.display = 'flex';
    document.getElementById('active-chat-name').innerText = chatName;

    let avatarUrl = `https://ui-avatars.com/api/?name=${encodeURIComponent(chatName)}&background=FF5722&color=fff`;
    if (chatType === 'direct' && chatData && chatData.participants) {
        const otherParticipant = chatData.participants.find(p => p.user_id !== window.APP_USER.id);
        if (otherParticipant?.user?.photo_url) {
            avatarUrl = otherParticipant.user.photo_url;
        }
    }
    document.getElementById('active-chat-img').src = avatarUrl;
    
    // Mobile view handling
    document.body.classList.add('mobile-show-chat');
    
    // Back button handling
    document.getElementById('btn-back').onclick = () => {
        document.body.classList.remove('mobile-show-chat');
    };
    
    // Highlight list
    document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
    document.getElementById(`chat-list-item-${chatId}`)?.classList.add('active');

    // Load Messages
    const msgContainer = document.getElementById('chat-messages');
    msgContainer.innerHTML = '<div class="loading-text">Loading messages...</div>';

    try {
        const response = await fetch(`/api/chats/${chatId}`, {
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });
        const data = await response.json();
        
        // Prevent race conditions if the user clicked another chat before this fetch completed
        if (currentChatId !== chatId) return;
        
        if (data.chat) {
            loadedChatData = data.chat; // Contains eager loaded participants
            
            // Block logic UI
            if (data.chat.is_blocked) {
                document.querySelector('.chat-composer').style.display = 'none';
                document.getElementById('blocked-banner').style.display = 'flex';
                
                if (data.chat.blocked_by_me) {
                    document.getElementById('blocked-banner').innerHTML = `<span>You have blocked this contact. <a href="#" id="btn-unblock-banner" style="color: var(--primary-accent); text-decoration: none; font-weight: 500;">Tap to unblock.</a></span>`;
                    document.getElementById('btn-unblock-banner').onclick = async (e) => {
                        e.preventDefault();
                        unblockUser();
                    };
                } else {
                    document.getElementById('blocked-banner').innerHTML = `<span>You have been blocked by this contact.</span>`;
                }
            } else {
                document.querySelector('.chat-composer').style.display = 'flex';
                document.getElementById('blocked-banner').style.display = 'none';
            }
            
            // Update Block menu button text
            const blockMenuBtn = document.getElementById('btn-block-user');
            if (blockMenuBtn) {
                if (data.chat.chat_type === 'group') {
                    blockMenuBtn.style.display = 'none';
                } else {
                    blockMenuBtn.style.display = 'block';
                    blockMenuBtn.innerText = data.chat.blocked_by_me ? 'Unblock User' : 'Block User';
                }
            }
        }
        
        // Idempotent (see distributeNewChatKey) — bootstraps encryption for
        // this chat if it doesn't have a key yet, including chats that
        // predate E2EE and are only now being reopened. Awaited before
        // rendering so the decrypt pass below can find the key it just set up.
        if (data.chat.participants) {
            await distributeNewChatKey(chatId, data.chat.participants.map(p => p.user_id));
        }
        // Opportunistically reseals this chat's key to any participant
        // device missing a grant — simply opening the chat now heals a
        // reinstalled/new device, not just sending a new message.
        healMissingGrants(chatId);

        msgContainer.innerHTML = '';
        lastMessageData = null;
        lastMessageNode = null;
        const decryptedMessages = await Promise.all(
            data.messages.data.reverse().map(msg => decryptMessageIfNeeded(msg))
        );
        decryptedMessages.forEach(msg => appendMessage(msg));
        msgContainer.scrollTop = msgContainer.scrollHeight;

        updateActiveChatStatus();

        // Listen for real-time events
        listenToChat(chatId);
    } catch (e) {
        console.error("Failed to load messages", e);
    }
}

function updateActiveChatStatus() {
    if (!currentChatId || currentChatType !== 'direct' || !loadedChatData) {
        document.getElementById('active-chat-status').style.display = 'none';
        return;
    }
    const statusEl = document.getElementById('active-chat-status');
    const otherParticipant = loadedChatData.participants.find(p => p.user_id !== window.APP_USER.id);
    if (otherParticipant && isUserOnline(otherParticipant.user) && !blockedUsersSet.has(otherParticipant.user_id)) {
        statusEl.innerText = 'Online';
        statusEl.style.display = 'block';
    } else {
        statusEl.style.display = 'none';
    }
}

function listenToChat(chatId) {
    // Disconnect old listener if needed
    if (currentChatChannelName) {
        Echo.leave(currentChatChannelName);
    }

    startPaymentStatusPolling(chatId);
    
    currentChatChannelName = `chat.${chatId}`;
    const channel = Echo.private(currentChatChannelName);
    
    channel.listen('MessageSent', async (e) => {
        const message = await decryptMessageIfNeeded(e.message);
        appendMessage(message);

        // Notify server it was read
        if (e.message.sender_id !== window.APP_USER.id) {
            markAsRead(e.message.id);
        }
    });

    channel.listen('MessagesRead', (e) => {
        if (e.message_ids && Array.isArray(e.message_ids)) {
            e.message_ids.forEach(id => {
                const tickEl = document.getElementById(`tick-${id}`);
                if (tickEl) {
                    tickEl.className = 'tick read';
                    tickEl.innerHTML = '✓✓';
                }
            });
        }
    });

    channel.listen('MessageReactionUpdated', (e) => {
        renderReactionsForMessage(e.message_id, e.reactions);
    });

    // Server-broadcast event (POST /chats/{id}/typing -> UserTyping), the
    // same one the mobile app sends/listens for — replaces the old
    // Pusher-whisper-only approach so typing is visible across platforms.
    channel.listen('UserTyping', (e) => {
        if (e.user_id === window.APP_USER.id) return;
        const statusEl = document.getElementById('active-chat-status');
        clearTimeout(window.typingTimer);
        if (e.is_typing) {
            statusEl.innerText = 'typing...';
            statusEl.style.display = 'block';
            window.typingTimer = setTimeout(() => {
                updateActiveChatStatus();
            }, 2000);
        } else {
            updateActiveChatStatus();
        }
    });
}

let lastMessageData = null;
let lastMessageNode = null;

// The message currently being replied to (full object, needed for the
// composer's reply-preview sender/snippet) — cleared on send or on closing
// the preview. Populated from messagesById, kept alongside it below.
let replyingToMessage = null;
const messagesById = {};

function getUserDisplayNameForReply(msg) {
    if (msg.sender_id === window.APP_USER.id) return 'You';
    return getUserDisplayName(msg.sender);
}

function showReplyPreview(msg) {
    replyingToMessage = msg;
    document.getElementById('reply-preview-sender').textContent = getUserDisplayNameForReply(msg);
    document.getElementById('reply-preview-text').textContent = msg.content || previewTextForMessage(msg);
    document.getElementById('reply-preview-bar').style.display = 'flex';
    document.getElementById('message-input').focus();
}

function hideReplyPreview() {
    replyingToMessage = null;
    document.getElementById('reply-preview-bar').style.display = 'none';
}

function previewTextForMessage(msg) {
    switch (msg.message_type) {
        case 'image': return '📷 Photo';
        case 'video': return '🎥 Video';
        case 'voice': return '🎤 Voice note';
        case 'document': return '📎 File';
        case 'sticker': return `${msg.content || '🎉'} Sticker`;
        default: return msg.content || '';
    }
}

document.getElementById('btn-close-reply-preview').addEventListener('click', hideReplyPreview);

async function sendSticker(emoji) {
    if (!currentChatId) return;
    document.getElementById('sticker-picker').style.display = 'none';

    try {
        const response = await fetch(`/api/chats/${currentChatId}/messages`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                message_type: 'sticker',
                content: emoji,
                ...(replyingToMessage ? { quoted_message_id: replyingToMessage.id } : {})
            })
        });
        const data = await response.json();
        appendMessage(data.message);
        fetchInbox();
        hideReplyPreview();
    } catch (e) {
        console.error('Sticker send failed', e);
    }
}

// Global map to hold audio instances for voice notes
const activeAudios = new Map();
let currentlyPlayingVnId = null;

window.toggleVn = function(msgId) {
    const player = document.getElementById(`vn-${msgId}`);
    if (!player) return;
    
    let audio = activeAudios.get(msgId);
    if (!audio) {
        audio = new Audio(player.dataset.src);
        activeAudios.set(msgId, audio);
        
        audio.addEventListener('timeupdate', () => {
            const scrubber = player.querySelector('.vn-scrubber');
            const timeDisplay = player.querySelector('.vn-time');
            if (audio.duration) {
                scrubber.value = (audio.currentTime / audio.duration) * 100;
                timeDisplay.innerText = formatTime(audio.currentTime);
            }
        });
        
        audio.addEventListener('ended', () => {
            player.querySelector('.vn-icon-play').style.display = 'block';
            player.querySelector('.vn-icon-pause').style.display = 'none';
            player.querySelector('.vn-scrubber').value = 0;
            player.querySelector('.vn-time').innerText = '0:00';
            currentlyPlayingVnId = null;
        });
        
        audio.addEventListener('loadedmetadata', () => {
            const timeDisplay = player.querySelector('.vn-time');
            if(audio.duration && audio.currentTime === 0) {
                 timeDisplay.innerText = formatTime(audio.duration);
            }
        });
    }

    if (audio.paused) {
        // Pause currently playing if any
        if (currentlyPlayingVnId && currentlyPlayingVnId !== msgId) {
            const prevAudio = activeAudios.get(currentlyPlayingVnId);
            if (prevAudio) prevAudio.pause();
            const prevPlayer = document.getElementById(`vn-${currentlyPlayingVnId}`);
            if (prevPlayer) {
                prevPlayer.querySelector('.vn-icon-play').style.display = 'block';
                prevPlayer.querySelector('.vn-icon-pause').style.display = 'none';
            }
        }
        
        audio.play().catch(e => console.error(e));
        player.querySelector('.vn-icon-play').style.display = 'none';
        player.querySelector('.vn-icon-pause').style.display = 'block';
        currentlyPlayingVnId = msgId;
    } else {
        audio.pause();
        player.querySelector('.vn-icon-play').style.display = 'block';
        player.querySelector('.vn-icon-pause').style.display = 'none';
        currentlyPlayingVnId = null;
    }
};

window.seekVn = function(msgId, value) {
    const audio = activeAudios.get(msgId);
    if (audio && audio.duration) {
        audio.currentTime = (value / 100) * audio.duration;
    }
};

function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) return "0:00";
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}

function getPaymentStatusLabel(status) {
    const labels = {
        pending_approval: 'Pending Approval',
        submitted_to_sampay: 'Pending',
        pending: 'Pending',
        completed: 'Completed',
        rejected: 'Rejected',
        failed: 'Failed'
    };
    return labels[status] || 'Pending';
}

function buildPaymentRequestCard(msg, isSent) {
    const meta = typeof msg.metadata === 'string' ? JSON.parse(msg.metadata || '{}') : (msg.metadata || {});
    const status = meta.status || 'pending';
    const amount = Number(meta.amount || 0);
    const canAct = status === 'pending_approval' && meta.target_user_id === window.APP_USER.id;
    const requestId = meta.sampay_request_id ? `<div class="payment-line">Request ID: ${escapeHTML(String(meta.sampay_request_id))}</div>` : '';
    const errorLine = meta.last_error ? `<div class="payment-line" style="color:#ef4444;">Error: ${escapeHTML(String(meta.last_error))}</div>` : '';
    const previewRecipient = meta.recipient_preview || meta.recipient || null;
    const accountStatusLine = previewRecipient?.account_status ? `<div class="payment-line">Recipient status: ${escapeHTML(String(previewRecipient.account_status))}</div>` : '';

    let whoLine = '';
    if (isSent) {
        whoLine = '<div class="payment-line">Submitted to Sampay, waiting for wallet owner approval.</div>';
    } else if (status === 'pending' || status === 'submitted_to_sampay') {
        whoLine = '<div class="payment-line">Waiting for Sampay wallet owner approval.</div>';
    }

    const actionButtons = canAct
        ? `<div class="payment-actions">
            <button class="payment-action-btn approve btn-payment-approve" data-message-id="${msg.id}">Approve</button>
            <button class="payment-action-btn reject btn-payment-reject" data-message-id="${msg.id}">Reject</button>
        </div>`
        : '';

    return `
        <div class="payment-request-card" data-payment-message-id="${msg.id}">
            <div class="payment-header">
                <span class="payment-title">In-chat Payment</span>
                <span class="payment-status-badge ${status}">${getPaymentStatusLabel(status)}</span>
            </div>
            <div class="payment-line">Amount: ZMW ${Number.isFinite(amount) ? amount.toFixed(2) : '0.00'}</div>
            <div class="payment-line">Recipient type: ${escapeHTML(String(meta.recipient_type || 'personal'))}</div>
            <div class="payment-line">Recipient account: ${escapeHTML(String(meta.recipient_account || meta.recipient_username || 'N/A'))}</div>
            <div class="payment-line">Purpose: ${escapeHTML(String(meta.purpose || meta.description || 'N/A'))}</div>
            ${meta.remarks ? `<div class="payment-line">Remarks: ${escapeHTML(String(meta.remarks))}</div>` : ''}
            ${accountStatusLine}
            ${whoLine}
            ${requestId}
            ${errorLine}
            ${actionButtons}
        </div>
    `;
}

function buildQuotedHTML(msg) {
    if (!msg.quoted_message) return '';
    const q = msg.quoted_message;
    const senderLabel = q.sender_id === window.APP_USER.id ? 'You' : getUserDisplayName(q.sender);
    const snippet = escapeHTML((q.content || previewTextForMessage(q) || 'Message').slice(0, 80));
    return `<div class="quoted-message-block" data-quoted-id="${q.id}">
        <div class="quoted-message-sender">${escapeHTML(senderLabel)}</div>
        <div class="quoted-message-text">${snippet}</div>
    </div>`;
}

// Tapping a reply's quoted preview jumps to and briefly highlights the
// original message — WhatsApp-style, mirrors the Flutter app's
// ChatDetailScreen._scrollToMessage. Web has no scroll-up pagination (see
// the loadChat/fetchInbox note elsewhere), so this only ever works for a
// message that's already rendered — a graceful no-op otherwise rather than
// a broken-looking dead click.
document.getElementById('chat-messages').addEventListener('click', (e) => {
    const quotedBlock = e.target.closest('.quoted-message-block');
    if (!quotedBlock) return;
    const targetEl = document.getElementById(`msg-${quotedBlock.dataset.quotedId}`);
    if (!targetEl) return;
    targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    targetEl.classList.add('message-highlight');
    setTimeout(() => targetEl.classList.remove('message-highlight'), 1200);
});

function buildReactionsHTML(reactions) {
    if (!reactions || !reactions.length) return '';
    const counts = {};
    const mine = new Set();
    reactions.forEach(r => {
        counts[r.emoji] = (counts[r.emoji] || 0) + 1;
        if (r.user_id === window.APP_USER.id) mine.add(r.emoji);
    });
    return Object.entries(counts).map(([emoji, count]) => `
        <span class="reaction-pill${mine.has(emoji) ? ' mine' : ''}">${emoji}${count > 1 ? ` <span class="reaction-count">${count}</span>` : ''}</span>
    `).join('');
}

function renderReactionsForMessage(messageId, reactions) {
    const el = document.getElementById(`reactions-${messageId}`);
    if (el) el.innerHTML = buildReactionsHTML(reactions);
    if (messagesById[messageId]) messagesById[messageId].reactions = reactions;
}

function appendMessage(msg) {
    const isSent = msg.sender_id === window.APP_USER.id;
    const msgContainer = document.getElementById('chat-messages');
    const time = new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    messagesById[msg.id] = msg;

    const existingRow = document.getElementById(`msg-${msg.id}`);
    if (existingRow) {
        if (msg.message_type === 'payment_request') {
            const bubble = existingRow.querySelector('.message-bubble');
            if (bubble) {
                const cardHTML = buildPaymentRequestCard(msg, isSent);
                const existingCard = bubble.querySelector('.payment-request-card');
                if (existingCard) {
                    existingCard.outerHTML = cardHTML;
                } else {
                    bubble.insertAdjacentHTML('afterbegin', cardHTML);
                }

                const messageContent = bubble.querySelector('.message-content');
                if (messageContent) messageContent.remove();
            }
        }
        return;
    }

    // 1. Check if we should group this media message with the previous one
    let shouldGroup = false;
    let isMedia = false;
    if (msg.message_type === 'image' || msg.message_type === 'video') isMedia = true;

    if (lastMessageData && lastMessageNode) {
        const timeDiff = new Date(msg.created_at).getTime() - new Date(lastMessageData.created_at).getTime();
        const sameSender = msg.sender_id === lastMessageData.sender_id;
        const prevIsMedia = (lastMessageData.message_type === 'image' || lastMessageData.message_type === 'video');
        
        if (sameSender && isMedia && prevIsMedia && timeDiff < 60000) { // Within 60 seconds
            shouldGroup = true;
        }
    }

    if (msg.message_type === 'call_log') {
        const meta = typeof msg.metadata === 'string' ? JSON.parse(msg.metadata) : msg.metadata;
        const iconSVG = meta.call_type === 'video' 
            ? `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"></path></svg>`
            : `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56-.35-.12-.74-.03-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.03 21c.75 0 1-.65 1-1.19v-3.44c0-.54-.45-.99-.99-.99z"></path></svg>`;
        
        let action = meta.status;
        let isMissed = false;
        
        if (action === 'missed' && !isSent) {
            action = 'Missed call';
            isMissed = true;
        } else if (action === 'missed' && isSent) {
            action = 'Cancelled call';
            isMissed = true;
        } else if (action === 'rejected') {
            action = 'Declined call';
            isMissed = true;
        } else {
            action = `Call ended (${Math.floor(meta.duration / 60)}:${String(meta.duration % 60).padStart(2, '0')})`;
        }
        
        msg.call_html = `
            <div class="call-log-bubble" style="display: flex; align-items: center; gap: 12px; padding: 2px 4px 4px 0;">
                <div class="call-icon-circle" style="width: 40px; height: 40px; border-radius: 50%; background: ${isMissed ? 'var(--danger-error)' : 'var(--online-success)'}; display: flex; align-items: center; justify-content: center; color: white;">
                    ${iconSVG}
                </div>
                <div class="call-log-details" style="display: flex; flex-direction: column;">
                    <span style="font-weight: 500; font-size: 0.95rem;">${action}</span>
                    <span style="font-size: 0.8rem; opacity: 0.85;">${meta.call_type === 'video' ? 'Video' : 'Voice'} call</span>
                </div>
            </div>
        `;
        msg.content = '';
    }
    
    let tickClass = '';
    let tickText = '';
    if (isSent) {
        tickClass = 'tick pending';
        tickText = '✓';
        if (msg.receipts && msg.receipts.length > 0) {
            const isRead = msg.receipts.some(r => r.status === 'read');
            if (isRead) {
                tickClass = 'tick read';
                tickText = '✓✓';
            } else {
                tickClass = 'tick delivered';
                tickText = '✓✓';
            }
        }
    }
    const tickHTML = isSent ? `<span id="tick-${msg.id}" class="${tickClass}">${tickText}</span>` : '';

    // Parse Metadata for Media
    let mediaHTML = '';
    if (msg.message_type === 'payment_request') {
        mediaHTML = buildPaymentRequestCard(msg, isSent);
    } else if (msg.message_type === 'call_log' && msg.call_html) {
        mediaHTML = msg.call_html;
    } else if (msg.metadata) {
        const meta = typeof msg.metadata === 'string' ? JSON.parse(msg.metadata) : msg.metadata;
        if (meta.media_url) {
            if (msg.message_type === 'image') {
                mediaHTML = `<img src="${meta.media_url}" class="gallery-item">`;
            } else if (msg.message_type === 'video') {
                mediaHTML = `<video src="${meta.media_url}" class="gallery-item" controls></video>`;
            } else if (msg.message_type === 'document') {
                mediaHTML = `<a href="${meta.media_url}" target="_blank" style="display:block; padding: 10px; background: rgba(0,0,0,0.05); border-radius: 6px; margin-bottom: 5px; color: inherit; text-decoration: none;">📄 Document</a>`;
            } else if (msg.message_type === 'voice') {
                mediaHTML = `
                <div class="vn-player" data-src="${meta.media_url}" id="vn-${msg.id}">
                    <button class="vn-play-btn" onclick="toggleVn('${msg.id}')">
                        <svg class="vn-icon-play" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"></path></svg>
                        <svg class="vn-icon-pause" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" style="display:none;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"></path></svg>
                    </button>
                    <div class="vn-waveform">
                        <input type="range" class="vn-scrubber" value="0" min="0" max="100" step="0.1" oninput="seekVn('${msg.id}', this.value)" onchange="seekVn('${msg.id}', this.value)">
                        <div class="vn-time">0:00</div>
                    </div>
                </div>`;
            }
        }
    }

    if (shouldGroup) {
        const bubble = lastMessageNode.querySelector('.message-bubble');
        let galleryContainer = bubble.querySelector('.media-gallery-grid');
        
        if (!galleryContainer) {
            // Convert the existing single media item into a grid
            const existingMedia = bubble.querySelector('.gallery-item');
            if (existingMedia) {
                galleryContainer = document.createElement('div');
                galleryContainer.className = 'media-gallery-grid count-2';
                existingMedia.parentNode.insertBefore(galleryContainer, existingMedia);
                galleryContainer.appendChild(existingMedia);
            }
        }
        
        if (galleryContainer) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = mediaHTML;
            const newMediaElement = tempDiv.firstElementChild;
            newMediaElement.id = `msg-${msg.id}`; // Add ID to the media element directly to prevent duplicates
            galleryContainer.appendChild(newMediaElement);
            
            const count = galleryContainer.children.length;
            galleryContainer.className = `media-gallery-grid count-${count >= 4 ? 4 : count}`;
            
            lastMessageData = msg; // update timestamp for next
            msgContainer.scrollTop = msgContainer.scrollHeight;
            return; // We are done, don't create a new row!
        }
    }

    // 2. Standard creation of new message row
    const row = document.createElement('div');
    row.id = `msg-${msg.id}`;
    row.className = `message-row ${isSent ? 'sent' : 'received'}`;
    
    // Group Sender Name UI
    let senderHTML = '';
    if (!isSent && currentChatType === 'group' && msg.sender) {
        const colors = ['#e53935', '#d81b60', '#8e24aa', '#3949ab', '#039be5', '#00897b', '#43a047', '#ff8f00', '#f4511e'];
        const color = colors[msg.sender.id.charCodeAt(0) % colors.length];
        senderHTML = `<div class="sender-name" style="color: ${color};">${escapeHTML(getUserDisplayName(msg.sender))}</div>`;
    }
    
    let textContent = msg.content ? escapeHTML(msg.content) : '';
    if (msg.message_type === 'payment_request' || msg.message_type === 'sticker') {
        textContent = '';
    }

    const actionsBtnHTML = `<div class="msg-actions-btn" data-msg-id="${msg.id}" data-is-sent="${isSent}">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"></path></svg>
    </div>`;

    const quotedHTML = buildQuotedHTML(msg);
    const isSticker = msg.message_type === 'sticker';
    const stickerHTML = isSticker
        ? `<div class="sticker-content">${escapeHTML(msg.content || '🎉')}</div>`
        : '';

    row.innerHTML = `
        <input type="checkbox" class="msg-select-checkbox" data-msg-id="${msg.id}">
        <div class="message-bubble ${isSticker ? 'sticker-bubble' : ''}">
            ${actionsBtnHTML}
            ${senderHTML}
            ${quotedHTML}
            ${isSticker ? stickerHTML : mediaHTML}
            ${textContent ? `<div class="message-content">${textContent}</div>` : ''}
            <div class="reaction-pills" id="reactions-${msg.id}">${buildReactionsHTML(msg.reactions)}</div>
            <div class="message-meta">
                ${time} ${tickHTML}
            </div>
        </div>
    `;
    
    lastMessageNode = row;
    lastMessageData = msg;
    
    msgContainer.appendChild(row);
    msgContainer.scrollTop = msgContainer.scrollHeight;
}

function stopPaymentStatusPolling() {
    if (paymentStatusPollInterval) {
        clearInterval(paymentStatusPollInterval);
        paymentStatusPollInterval = null;
    }
}

async function syncPaymentStatusesForChat(chatId, silent = true) {
    if (!chatId) return;

    try {
        const response = await fetch(`/api/chats/${chatId}/sampay/sync-status`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });

        const data = await response.json();
        if (!response.ok) {
            if (!silent) {
                throw new Error(data.error || 'Failed to sync payment statuses.');
            }
            return;
        }

        const updatedMessages = Array.isArray(data.updated_messages) ? data.updated_messages : [];
        if (updatedMessages.length > 0) {
            updatedMessages.forEach(msg => appendMessage(msg));
            fetchInbox();
        }
    } catch (e) {
        if (!silent) {
            console.error('Payment status sync failed', e);
            alert(e.message || 'Failed to sync payment statuses.');
        }
    }
}

function startPaymentStatusPolling(chatId) {
    stopPaymentStatusPolling();
    if (!chatId) return;

    syncPaymentStatusesForChat(chatId, true);

    paymentStatusPollInterval = setInterval(() => {
        if (!currentChatId || currentChatId !== chatId) {
            stopPaymentStatusPolling();
            return;
        }
        syncPaymentStatusesForChat(chatId, true);
    }, 7000);
}

async function sendMessage() {
    if (!currentChatId) return;
    
    const input = document.getElementById('message-input');
    const content = input.value.trim();
    if (!content) return;
    
    input.value = ''; // clear instantly
    input.dispatchEvent(new Event('input')); // reset mic/send buttons
    document.getElementById('emoji-picker').style.display = 'none'; // hide picker
    
    try {
        let outgoingContent = content;
        const metadata = {};
        const encrypted = await tryEncryptForChat(currentChatId, content);
        if (encrypted) {
            outgoingContent = encrypted;
            metadata.encrypted = true;
        }

        const response = await fetch(`/api/chats/${currentChatId}/messages`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                message_type: 'text',
                content: outgoingContent,
                ...(Object.keys(metadata).length ? { metadata } : {}),
                ...(replyingToMessage ? { quoted_message_id: replyingToMessage.id } : {})
            })
        });

        const data = await response.json();
        // The server echoes back exactly what was stored (ciphertext, if we
        // just encrypted) — render the plaintext we already have locally
        // instead of round-tripping it through decrypt again.
        if (outgoingContent !== content) data.message.content = content;
        appendMessage(data.message);
        fetchInbox(); // Refresh sidebar to re-sort
        hideReplyPreview();
        
        // Update tick to delivered for testing
        setTimeout(() => {
            const tickEl = document.getElementById(`tick-${data.message.id}`);
            if(tickEl) { tickEl.className = 'tick delivered'; tickEl.innerHTML = '✓✓'; }
        }, 500);

    } catch (e) {
        console.error("Send failed", e);
    }
}

async function markAsRead(messageId) {
    fetch(`/api/messages/${messageId}/read`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${window.API_TOKEN}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }
    });
}

function escapeHTML(str) {
    return str.replace(/[&<>'"]/g, 
        tag => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[tag] || tag)
    );
}

/* -------------------------------------
   Profile Modal Logic
-------------------------------------- */
async function fetchContacts() {
    try {
        const res = await fetch('/api/contacts', {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}` }
        });
        const data = await res.json();
        if (data.contacts) {
            data.contacts.forEach(c => {
                window.SAVED_CONTACTS[c.contact_user_id] = c.custom_name;
            });
        }
    } catch (e) {
        console.error('Failed to load contacts', e);
    }
}

function getUserDisplayName(user) {
    if (!user) return 'Unknown';
    if (window.SAVED_CONTACTS && window.SAVED_CONTACTS[user.id]) {
        return window.SAVED_CONTACTS[user.id];
    }
    if (user.first_name && user.last_name) return `${user.first_name} ${user.last_name}`;
    if (user.first_name) return user.first_name;
    return user.username || 'User';
}

let selectedProfilePhoto = null;

function openProfileModal() {
    selectedProfilePhoto = null;
    document.getElementById('profile-photo-input').value = '';
    
    document.getElementById('profile-first-name').value = window.APP_USER.first_name || '';
    document.getElementById('profile-last-name').value = window.APP_USER.last_name || '';
    document.getElementById('profile-email').value = window.APP_USER.email || '';
    document.getElementById('profile-name').value = window.APP_USER.username || '';
    document.getElementById('profile-about').value = window.APP_USER.about_status || '';
    
    const avatarUrl = window.APP_USER.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(window.APP_USER))}&background=FF5722&color=fff`;
    document.getElementById('profile-avatar-preview').src = avatarUrl;
    
    document.getElementById('profile-modal').classList.add('active');
}

function closeProfileModal() {
    document.getElementById('profile-modal').classList.remove('active');
}

document.getElementById('btn-change-avatar').addEventListener('click', () => {
    document.getElementById('profile-photo-input').click();
});

document.getElementById('profile-photo-input').addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file) {
        selectedProfilePhoto = file;
        document.getElementById('profile-avatar-preview').src = URL.createObjectURL(file);
    }
});

async function saveProfile() {
    const btn = document.getElementById('btn-save-profile');
    btn.innerText = "Saving...";
    btn.disabled = true;

    try {
        const formData = new FormData();
        formData.append('first_name', document.getElementById('profile-first-name').value.trim());
        formData.append('last_name', document.getElementById('profile-last-name').value.trim());
        formData.append('email', document.getElementById('profile-email').value.trim());
        formData.append('username', document.getElementById('profile-name').value.trim());
        formData.append('about_status', document.getElementById('profile-about').value.trim());
        
        if (selectedProfilePhoto) {
            formData.append('photo', selectedProfilePhoto);
        }

        const response = await fetch('/api/user/profile', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            },
            body: formData
        });

        const data = await response.json();
        if (response.ok) {
            window.APP_USER = data.user;
            document.getElementById('my-profile-pic').src = window.APP_USER.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(window.APP_USER))}&background=6366f1&color=fff`;
            closeProfileModal();
        } else {
            alert("Failed to save profile. " + (data.message || ''));
        }
    } catch (e) {
        console.error("Save profile error", e);
    } finally {
        btn.innerText = "Save";
        btn.disabled = false;
    }
}

// Settings Modal
window.togglePrivacyListInput = function() {
    const val = document.getElementById('setting-status-privacy').value;
    const wrapper = document.getElementById('setting-status-privacy-list-wrapper');
    if (val === 'selected' || val === 'exclude') {
        wrapper.style.display = 'block';
    } else {
        wrapper.style.display = 'none';
    }
};

async function openSettingsModal() {
    const settings = JSON.parse(localStorage.getItem('samchats_settings') || '{"mute_sounds":false,"enter_send":true}');
    document.getElementById('setting-mute-sounds').checked = settings.mute_sounds;
    document.getElementById('setting-enter-send').checked = settings.enter_send;
    
    // Fetch privacy settings
    try {
        const res = await fetch('/api/users/privacy', {
            headers: { 
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });
        const data = await res.json();
        document.getElementById('setting-status-privacy').value = data.status_privacy || 'contacts';
        
        if (data.status_privacy_usernames && data.status_privacy_usernames.length > 0) {
            document.getElementById('setting-status-privacy-list').value = data.status_privacy_usernames.join(', ');
        } else {
            document.getElementById('setting-status-privacy-list').value = '';
        }
    } catch (e) {
        console.error("Failed to load privacy settings", e);
    }

    togglePrivacyListInput();
    document.getElementById('settings-modal').classList.add('active');
}

function closeSettingsModal() {
    document.getElementById('settings-modal').classList.remove('active');
}

async function saveSettings() {
    const muteSounds = document.getElementById('setting-mute-sounds').checked;
    const enterSend = document.getElementById('setting-enter-send').checked;
    
    localStorage.setItem('samchats_settings', JSON.stringify({
        mute_sounds: muteSounds,
        enter_send: enterSend
    }));
    
    const privacy = document.getElementById('setting-status-privacy').value;
    const listStr = document.getElementById('setting-status-privacy-list').value;
    const list = listStr.split(',').map(s => s.trim()).filter(s => s.length > 0);
    
    // Save privacy settings
    try {
        await fetch('/api/users/privacy', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                status_privacy: privacy,
                status_privacy_list: list
            })
        });
    } catch (e) {
        console.error("Failed to save privacy settings", e);
    }
    
    closeSettingsModal();
}

window.openProfileModal = openProfileModal;
window.openSettingsModal = openSettingsModal;
window.closeSettingsModal = closeSettingsModal;
window.saveSettings = saveSettings;

/* -------------------------------------
   Sampay Integration Logic
-------------------------------------- */
async function openPaymentsModal() {
    const modal = document.getElementById('payments-modal');
    modal.style.display = 'flex';
    modal.classList.add('active');
    document.getElementById('payments-loading').style.display = 'block';
    document.getElementById('payments-linked-view').style.display = 'none';
    document.getElementById('payments-unlinked-view').style.display = 'none';

    try {
        const response = await fetch('/api/sampay/status', {
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });
        const data = await response.json();
        
        document.getElementById('payments-loading').style.display = 'none';
        
        if (data.is_linked) {
            document.getElementById('payments-linked-view').style.display = 'block';
            document.getElementById('sampay-username-text').innerText = data.sampay_account.username || 'N/A';
            document.getElementById('sampay-mobile-text').innerText = data.sampay_account.mobile_number || 'N/A';
        } else {
            document.getElementById('payments-unlinked-view').style.display = 'block';
        }
    } catch (e) {
        console.error("Failed to check Sampay status", e);
        document.getElementById('payments-loading').innerHTML = '<p style="color: red;">Failed to load status.</p>';
    }
}

function closePaymentsModal() {
    const modal = document.getElementById('payments-modal');
    modal.classList.remove('active');
    modal.style.display = 'none';
}

async function linkSampay() {
    try {
        const response = await fetch('/api/sampay/link', {
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });

        const data = await response.json();
        if (!response.ok || !data.authorization_url) {
            throw new Error(data.error || 'Could not generate Sampay authorization URL.');
        }

        window.location.href = data.authorization_url;
    } catch (e) {
        console.error('Failed to start Sampay linking', e);
        alert(`Failed to start Sampay linking: ${e.message}`);
    }
}

async function unlinkSampay() {
    if (!confirm("Are you sure you want to unlink your Sampay account?")) return;
    
    try {
        await fetch('/api/sampay/unlink', {
            method: 'DELETE',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });
        // Refresh modal view
        openPaymentsModal();
    } catch (e) {
        console.error("Failed to unlink Sampay account", e);
    }
}

window.openPaymentsModal = openPaymentsModal;
window.closePaymentsModal = closePaymentsModal;
window.linkSampay = linkSampay;
window.unlinkSampay = unlinkSampay;

async function getSampayStatus() {
    const response = await fetch('/api/sampay/status', {
        headers: {
            'Authorization': `Bearer ${window.API_TOKEN}`,
            'Accept': 'application/json'
        }
    });

    if (!response.ok) {
        throw new Error('Failed to check Sampay status.');
    }

    return response.json();
}

function setButtonBusy(button, busy, busyText = 'Processing…') {
    if (!button) return;
    if (busy) {
        if (button.dataset.originalText === undefined) {
            button.dataset.originalText = button.textContent;
        }
        button.disabled = true;
        button.textContent = busyText;
    } else {
        button.disabled = false;
        if (button.dataset.originalText !== undefined) {
            button.textContent = button.dataset.originalText;
            delete button.dataset.originalText;
        }
    }
}

async function openChatPaymentModal(targetParticipant) {
    if (!currentChatId || !loadedChatData) {
        alert('Open a chat first to request a payment.');
        return;
    }

    if (!targetParticipant && loadedChatData.chat_type === 'group') {
        openChatPaymentRecipientPicker();
        return;
    }

    try {
        const status = await getSampayStatus();
        if (!status.is_linked) {
            const proceed = confirm('Your Sampay account is not linked yet. Link now?');
            if (proceed) openPaymentsModal();
            return;
        }
    } catch (e) {
        console.error('Sampay status check failed', e);
        alert('Could not validate your Sampay link status. Please try again.');
        return;
    }

    if (!targetParticipant) {
        targetParticipant = loadedChatData.participants?.find(p => p.user_id !== window.APP_USER.id);
    }
    if (!targetParticipant) {
        alert('Could not determine target user in this conversation.');
        return;
    }

    selectedPaymentTargetUserId = targetParticipant.user_id;
    const targetUserName = getUserDisplayName(targetParticipant.user || {});

    document.getElementById('chat-payment-target-text').innerText = `Target user: ${targetUserName}`;
    document.getElementById('chat-payment-amount').value = '';
    document.getElementById('chat-payment-recipient-type').value = 'personal';
    document.getElementById('chat-payment-recipient-account').type = 'tel';
    document.getElementById('chat-payment-recipient-account').placeholder = 'Account #';
    document.getElementById('chat-payment-recipient-account').value = '';
    document.getElementById('chat-payment-purpose').value = '';
    document.getElementById('chat-payment-purpose-other').value = '';
    document.getElementById('chat-payment-purpose-other-group').style.display = 'none';
    document.getElementById('chat-payment-remarks').value = '';

    const modal = document.getElementById('chat-payment-modal');
    modal.style.display = 'flex';
    modal.classList.add('active');
}

function closeChatPaymentModal() {
    const modal = document.getElementById('chat-payment-modal');
    modal.classList.remove('active');
    modal.style.display = 'none';
}

function openChatPaymentRecipientPicker() {
    const listEl = document.getElementById('chat-payment-recipient-list');
    listEl.innerHTML = '';

    const others = (loadedChatData.participants || []).filter(p => p.user_id !== window.APP_USER.id);
    if (others.length === 0) {
        listEl.innerHTML = '<div class="loading-text text-muted" style="text-align:center; padding: 20px;">No other members in this group.</div>';
    }

    others.forEach(p => {
        const item = document.createElement('div');
        item.className = 'chat-item';
        item.style.cursor = 'pointer';
        item.innerHTML = `
            <div class="chat-item-pic-wrapper">
                <img src="${p.user?.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(p.user || {}))}&background=FF5722&color=fff`}" class="chat-item-pic">
            </div>
            <div class="chat-info" style="border:none;">
                <div class="chat-info-top">
                    <span class="chat-name">${escapeHTML(getUserDisplayName(p.user || {}))}</span>
                </div>
            </div>
        `;
        item.onclick = () => {
            closeChatPaymentRecipientModal();
            openChatPaymentModal(p);
        };
        listEl.appendChild(item);
    });

    const modal = document.getElementById('chat-payment-recipient-modal');
    modal.style.display = 'flex';
    modal.classList.add('active');
}

function closeChatPaymentRecipientModal() {
    const modal = document.getElementById('chat-payment-recipient-modal');
    modal.classList.remove('active');
    modal.style.display = 'none';
}

async function submitChatPaymentRequest() {
    if (!currentChatId || !selectedPaymentTargetUserId) {
        alert('No valid chat target found.');
        return;
    }

    const amountInput = document.getElementById('chat-payment-amount');
    const recipientTypeInput = document.getElementById('chat-payment-recipient-type');
    const recipientAccountInput = document.getElementById('chat-payment-recipient-account');
    const purposeInput = document.getElementById('chat-payment-purpose');
    const purposeOtherInput = document.getElementById('chat-payment-purpose-other');
    const remarksInput = document.getElementById('chat-payment-remarks');

    const amount = parseFloat(amountInput.value || '0');
    const recipientType = recipientTypeInput.value;
    let recipientAccount = recipientAccountInput.value.trim();
    const purpose = purposeInput.value === 'other' ? purposeOtherInput.value.trim() : purposeInput.value.trim();
    const remarks = remarksInput.value.trim();

    if (!Number.isFinite(amount) || amount < 1) {
        alert('Enter a valid amount in ZMW (minimum 1).');
        amountInput.focus();
        return;
    }

    if (!recipientType || (recipientType !== 'personal' && recipientType !== 'business')) {
        alert('Select a recipient account type.');
        recipientTypeInput.focus();
        return;
    }

    if (recipientType === 'personal' && recipientAccountIntlInput) {
        const normalized = normalizePersonalRecipientAccount(recipientAccountInput.value);
        if (!normalized.isValid) {
            alert(normalized.reason || 'Enter a valid international phone number for personal recipient account.');
            recipientAccountInput.focus();
            return;
        }
        recipientAccount = normalized.number;
        recipientAccountInput.value = recipientAccount;
    } else if (recipientType === 'personal') {
        const normalized = normalizePersonalRecipientAccount(recipientAccountInput.value);
        if (!normalized.isValid) {
            alert(normalized.reason || 'Enter a valid international phone number for personal recipient account.');
            recipientAccountInput.focus();
            return;
        }
        recipientAccount = normalized.number;
        recipientAccountInput.value = recipientAccount;
    }

    if (!recipientAccount) {
        alert('Enter a recipient Sampay account.');
        recipientAccountInput.focus();
        return;
    }

    if (!purpose) {
        alert('Select or enter a payment purpose.');
        (purposeInput.value === 'other' ? purposeOtherInput : purposeInput).focus();
        return;
    }

    try {
        const validateResponse = await fetch(`/api/chats/${currentChatId}/sampay/validate-recipient`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                amount,
                recipient_type: recipientType,
                recipient_account: recipientAccount,
                purpose,
                remarks: remarks || null,
                recipient_user_id: selectedPaymentTargetUserId
            })
        });

        const validationData = await validateResponse.json();
        if (!validateResponse.ok) {
            throw new Error(validationData.details?.message || validationData.error || 'Recipient validation failed.');
        }

        const validationStatus = (() => {
            if (typeof validationData.status === 'boolean') return validationData.status;
            if (typeof validationData.status === 'string') return validationData.status.toLowerCase() === 'true';
            return false;
        })();

        if (!validationStatus) {
            const reason = validationData.message || validationData.error || 'Recipient validation was not approved.';
            throw new Error(reason);
        }

        const recipientData = validationData.data?.recipient || validationData.recipient || {};
        const recipientName = recipientData.name || recipientData.username || recipientData.account || recipientAccount;
        const recipientStatus = recipientData.account_status || 'unknown';
        const proceed = confirm(`Verified recipient: ${recipientName} (${recipientStatus}).\nProceed to send approval request in chat?`);
        if (!proceed) return;

        const response = await fetch(`/api/chats/${currentChatId}/sampay/request-chat`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                amount,
                recipient_type: recipientType,
                recipient_account: recipientAccount,
                purpose,
                remarks: remarks || null,
                recipient_user_id: selectedPaymentTargetUserId
            })
        });

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.details?.message || data.error || 'Failed to send payment.');
        }

        closeChatPaymentModal();
        appendMessage(data.chat_message);
        syncPaymentStatusesForChat(currentChatId, true);
        fetchInbox();
    } catch (e) {
        console.error('Failed to send in-chat payment', e);
        alert(e.message || 'Failed to send in-chat payment.');
    }
}

async function approveChatPaymentRequest(messageId) {
    if (!currentChatId || !messageId) return;

    try {
        const status = await getSampayStatus();
        if (!status.is_linked) {
            const proceed = confirm('Please link your Sampay account before approving. Link now?');
            if (proceed) openPaymentsModal();
            return;
        }
    } catch (e) {
        console.error('Sampay status check failed', e);
        alert('Could not validate Sampay link status.');
        return;
    }

    if (!confirm('Approve this payment?')) return;

    try {
        const response = await fetch(`/api/chats/${currentChatId}/messages/${messageId}/sampay/approve`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.details?.message || data.error || 'Failed to approve payment.');
        }

        appendMessage(data.chat_message);
        fetchInbox();
    } catch (e) {
        console.error('Approve payment failed', e);
        alert(e.message || 'Failed to approve payment.');
    }
}

async function rejectChatPaymentRequest(messageId) {
    if (!currentChatId || !messageId) return;
    if (!confirm('Reject this payment?')) return;

    try {
        const response = await fetch(`/api/chats/${currentChatId}/messages/${messageId}/sampay/reject`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.details?.message || data.error || 'Failed to reject payment.');
        }

        appendMessage(data.chat_message);
        fetchInbox();
    } catch (e) {
        console.error('Reject payment failed', e);
        alert(e.message || 'Failed to reject payment.');
    }
}

/* -------------------------------------
   Search and Create Chat Logic
-------------------------------------- */
async function handleSearch(query) {
    const chatList = document.getElementById('chat-list');
    const searchList = document.getElementById('search-results-list');

    if (!query.trim()) {
        searchList.style.display = 'none';
        chatList.style.display = 'block';
        return;
    }

    chatList.style.display = 'none';
    searchList.style.display = 'block';
    searchList.innerHTML = '<div class="loading-text">Searching...</div>';

    try {
        const response = await fetch(`/api/users/search?q=${encodeURIComponent(query)}`, {
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });
        const data = await response.json();

        searchList.innerHTML = '';
        if (data.users.length === 0) {
            searchList.innerHTML = '<div class="loading-text">No users found.</div>';
            return;
        }

        data.users.forEach(user => {
            if (user.id === window.APP_USER.id) return; // Skip self

            const item = document.createElement('div');
            item.className = 'chat-item';
            item.onclick = () => createChat(user.id, getUserDisplayName(user));
            
            item.innerHTML = `
                <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}" class="chat-item-pic">
                <div class="chat-info">
                    <div class="chat-info-top">
                        <span class="chat-name">${escapeHTML(getUserDisplayName(user))}</span>
                    </div>
                    <span class="chat-last-msg">${escapeHTML(user.about_status || 'Available')}</span>
                </div>
            `;
            searchList.appendChild(item);
        });
    } catch (e) {
        console.error("Search failed", e);
        searchList.innerHTML = '<div class="loading-text text-danger">Search error.</div>';
    }
}

async function createChat(userId, userName) {
    document.getElementById('search-input').value = '';
    document.getElementById('search-results-list').style.display = 'none';
    document.getElementById('chat-list').style.display = 'block';

    try {
        const response = await fetch(`/api/chats`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ user_id: userId })
        });
        
        const data = await response.json();
        if (response.ok) {
            // Reload inbox to show new chat, then open it
            await fetchInbox();
            loadChat(data.chat.id, userName, 'direct', data.chat);
        } else {
            alert(data.error || "Failed to create chat");
        }
    } catch (e) {
        console.error("Create chat failed", e);
        alert("Failed to start chat.");
    }
}

/* -------------------------------------
   Statuses Logic
-------------------------------------- */
let activeStatusTimers = [];
let currentViewingStatuses = [];
let currentViewingIndex = 0;
let statusCreatorColor = '#6366f1';

function initStatusUI() {
    // Viewer
    document.getElementById('btn-close-viewer').addEventListener('click', stopStatusViewer);
    document.getElementById('btn-status-play-pause').addEventListener('click', toggleStatusPlayPause);
    document.getElementById('status-nav-left').addEventListener('click', () => navigateStatus(-1));
    document.getElementById('status-nav-right').addEventListener('click', () => navigateStatus(1));
    document.getElementById('active-status-content').addEventListener('click', () => navigateStatus(1));
    
    // Create Modal
    document.getElementById('btn-close-create-status').addEventListener('click', closeStatusCreateModal);
    document.getElementById('btn-send-status').addEventListener('click', submitStatus);
    
    // Media attachment
    document.getElementById('btn-status-attach').addEventListener('click', () => {
        document.getElementById('status-media-input').click();
    });
    document.getElementById('status-media-input').addEventListener('change', handleStatusMediaSelect);
    
    // Colors
    const colors = ['#e53935', '#d81b60', '#8e24aa', '#3949ab', '#039be5', '#00897b', '#43a047', '#ff8f00', '#6366f1'];
    const picker = document.getElementById('status-color-picker');
    picker.innerHTML = '';
    colors.forEach(c => {
        const div = document.createElement('div');
        div.className = 'color-option';
        div.style.background = c;
        if(c === statusCreatorColor) div.classList.add('active');
        div.onclick = () => {
            document.querySelectorAll('.color-option').forEach(el => el.classList.remove('active'));
            div.classList.add('active');
            statusCreatorColor = c;
            document.getElementById('status-create-preview').style.background = c;
        };
        picker.appendChild(div);
    });
}

let selectedMediaFile = null;

function handleStatusMediaSelect(e) {
    const file = e.target.files[0];
    if (!file) return;

    selectedMediaFile = file;
    const isVideo = file.type.startsWith('video/');
    
    const textInput = document.getElementById('status-create-text');
    const imgPreview = document.getElementById('status-media-img-preview');
    const vidPreview = document.getElementById('status-media-vid-preview');
    const colorPicker = document.getElementById('status-color-picker');

    textInput.style.display = 'none';
    colorPicker.style.display = 'none';
    document.getElementById('status-create-preview').style.background = '#000';

    const url = URL.createObjectURL(file);
    if (isVideo) {
        imgPreview.style.display = 'none';
        vidPreview.style.display = 'block';
        vidPreview.src = url;
    } else {
        vidPreview.style.display = 'none';
        imgPreview.style.display = 'block';
        imgPreview.src = url;
    }
}

function openStatusCreateModal() {
    selectedMediaFile = null;
    document.getElementById('status-media-input').value = '';
    
    document.getElementById('status-create-text').style.display = 'block';
    document.getElementById('status-color-picker').style.display = 'flex';
    document.getElementById('status-media-img-preview').style.display = 'none';
    document.getElementById('status-media-vid-preview').style.display = 'none';
    document.getElementById('status-media-vid-preview').src = '';
    
    document.getElementById('status-create-text').value = '';
    document.getElementById('status-create-preview').style.background = statusCreatorColor;
    document.getElementById('status-create-modal').style.display = 'flex';
}

function closeStatusCreateModal() {
    document.getElementById('status-create-modal').style.display = 'none';
    document.getElementById('status-media-vid-preview').src = ''; // stop video
}

async function submitStatus() {
    const content = document.getElementById('status-create-text').value.trim();
    if (!content && !selectedMediaFile) return;

    try {
        let fetchOptions = {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        };

        if (selectedMediaFile) {
            const formData = new FormData();
            formData.append('type', selectedMediaFile.type.startsWith('video/') ? 'video' : 'image');
            formData.append('media', selectedMediaFile);
            if (content) formData.append('content', content);
            
            fetchOptions.body = formData;
        } else {
            fetchOptions.headers['Content-Type'] = 'application/json';
            fetchOptions.body = JSON.stringify({ type: 'text', content: content, background_color: statusCreatorColor });
        }

        const btn = document.getElementById('btn-send-status');
        btn.style.opacity = '0.5';
        btn.disabled = true;

        await fetch('/api/statuses', fetchOptions);
        
        btn.style.opacity = '1';
        btn.disabled = false;
        
        closeStatusCreateModal();
        fetchStatuses(); // Refresh hub
    } catch (e) {
        console.error("Failed to upload status", e);
        alert("Failed to upload status. Please try again.");
        document.getElementById('btn-send-status').style.opacity = '1';
        document.getElementById('btn-send-status').disabled = false;
    }
}

async function fetchStatuses() {
    const listEl = document.getElementById('status-list-container');
    const emptyState = document.getElementById('status-empty-state');
    const btnMyStatus = document.getElementById('btn-my-status');

    try {
        const response = await fetch('/api/statuses', {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        const statuses = data.statuses;

        listEl.innerHTML = '';
        
        let myStatuses = [];
        
        for (const [userId, userStatuses] of Object.entries(statuses)) {
            const user = userStatuses[0].user;
            if(user.id === window.APP_USER.id) {
                myStatuses = userStatuses;
                continue; // Don't show self in friends list
            }

            const item = document.createElement('div');
            item.className = 'status-thumbnail';
            item.onclick = () => playStatuses(userStatuses);

            const latestStatus = userStatuses[userStatuses.length - 1];
            item.innerHTML = `
                <div class="status-thumbnail-ring active">
                    <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}" class="status-thumbnail-pic">
                </div>
                <div style="display:flex; flex-direction:column; justify-content:center; flex:1; min-width:0;">
                    <div class="status-thumbnail-name" style="margin-bottom: 2px;">${escapeHTML(getUserDisplayName(user))}</div>
                    <div style="font-size:0.8rem; color:var(--text-muted);">${timeAgo(latestStatus.created_at)}</div>
                </div>
            `;
            listEl.appendChild(item);
        }
        
        if (listEl.children.length === 0) {
            emptyState.style.display = 'flex';
        } else {
            emptyState.style.display = 'none';
        }

        // Update My Status UI and Behavior
        const myRing = btnMyStatus.querySelector('.status-thumbnail-ring');
        if (myStatuses.length > 0) {
            myRing.classList.remove('add-ring');
            myRing.classList.add('active');
        } else {
            myRing.classList.remove('active');
            myRing.classList.add('add-ring');
        }

        btnMyStatus.onclick = (e) => {
            // If they clicked the plus icon, always create
            if (e.target.closest('.status-add-icon')) {
                e.stopPropagation();
                openStatusCreateModal();
                return;
            }
            
            // Otherwise, play if exists, else create
            if (myStatuses.length > 0) {
                playStatuses(myStatuses);
            } else {
                openStatusCreateModal();
            }
        };

    } catch(e) {
        console.error("Failed to fetch statuses", e);
    }
}

function playStatuses(userStatuses) {
    if(!userStatuses || userStatuses.length === 0) return;
    
    currentViewingStatuses = userStatuses;
    currentViewingIndex = 0;
    
    const viewer = document.getElementById('active-status-viewer');
    viewer.style.display = 'flex';
    
    const barsContainer = document.getElementById('status-progress-bars');
    barsContainer.innerHTML = '';
    
    userStatuses.forEach((_, i) => {
        barsContainer.innerHTML += `
            <div class="status-bar-track">
                <div id="status-bar-${i}" class="status-bar-fill"></div>
            </div>
        `;
    });
    
    const user = userStatuses[0].user;
    document.getElementById('status-viewer-user-info').innerHTML = `
        <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}">
        <div style="display:flex; flex-direction:column;">
            <span>${escapeHTML(getUserDisplayName(user))}</span>
            <span id="status-viewer-time" style="font-size:0.8rem; opacity:0.8; font-weight:400;"></span>
        </div>
    `;
    
    renderCurrentStatus();
}

function timeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffInSeconds = Math.floor((now - date) / 1000);

    if (diffInSeconds < 60) return "Just now";
    
    const diffInMinutes = Math.floor(diffInSeconds / 60);
    if (diffInMinutes < 60) return `${diffInMinutes}m ago`;
    
    const diffInHours = Math.floor(diffInMinutes / 60);
    if (diffInHours < 24) return `${diffInHours}h ago`;
    
    const diffInDays = Math.floor(diffInHours / 24);
    if (diffInDays < 7) return `${diffInDays}d ago`;
    
    return date.toLocaleDateString();
}

let isStatusPaused = false;

function toggleStatusPlayPause() {
    isStatusPaused = !isStatusPaused;
    const btn = document.getElementById('btn-status-play-pause');
    const currentBar = document.getElementById(`status-bar-${currentViewingIndex}`);
    const activeVideo = document.getElementById('active-video-player');
    
    if (isStatusPaused) {
        btn.innerHTML = '<path d="M8 5v14l11-7z"></path>'; // Play icon
        if (currentBar) currentBar.style.animationPlayState = 'paused';
        if (activeVideo) activeVideo.pause();
    } else {
        btn.innerHTML = '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"></path>'; // Pause icon
        if (currentBar) currentBar.style.animationPlayState = 'running';
        if (activeVideo) activeVideo.play();
    }
}

function renderCurrentStatus() {
    isStatusPaused = false;
    document.getElementById('btn-status-play-pause').innerHTML = '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"></path>'; // Default to pause icon (playing state)

    // Fill previous bars, empty future bars
    currentViewingStatuses.forEach((_, i) => {
        const bar = document.getElementById(`status-bar-${i}`);
        bar.style.animation = 'none';
        bar.style.width = i < currentViewingIndex ? '100%' : '0%';
        bar.onanimationend = null;
    });

    const status = currentViewingStatuses[currentViewingIndex];
    const contentEl = document.getElementById('active-status-content');
    const backdropEl = document.getElementById('status-viewer-backdrop');
    
    document.getElementById('status-viewer-time').textContent = timeAgo(status.created_at);

    backdropEl.style.background = status.background_color || '#333';
    contentEl.style.background = status.background_color || '#333';

    if (status.type === 'text') {
        contentEl.innerHTML = escapeHTML(status.content);
    } else if (status.type === 'image') {
        contentEl.innerHTML = `<img src="${status.content}" style="max-width:100%; max-height:100%; object-fit:contain; border-radius: 20px;">`;
    } else if (status.type === 'video') {
        contentEl.innerHTML = `<video id="active-video-player" src="${status.content}" autoplay playsinline style="max-width:100%; max-height:100%; object-fit:contain; border-radius: 20px;"></video>`;
        const vid = document.getElementById('active-video-player');
        if (isStatusPaused) vid.pause();
    }

    const currentBar = document.getElementById(`status-bar-${currentViewingIndex}`);
    
    // small timeout to allow reset
    setTimeout(() => {
        if (!currentBar) return;
        
        // duration is in milliseconds
        const durationSeconds = (status.duration || 5000) / 1000;
        currentBar.style.animation = `progressFill ${durationSeconds}s linear forwards`;
        if (isStatusPaused) currentBar.style.animationPlayState = 'paused';
        
        currentBar.onanimationend = () => {
            navigateStatus(1);
        };
    }, 50);
}

function navigateStatus(dir) {
    currentViewingIndex += dir;
    if (currentViewingIndex >= currentViewingStatuses.length) {
        stopStatusViewer();
    } else if (currentViewingIndex < 0) {
        currentViewingIndex = 0;
        renderCurrentStatus();
    } else {
        renderCurrentStatus();
    }
}

function stopStatusViewer() {
    const currentBar = document.getElementById(`status-bar-${currentViewingIndex}`);
    if (currentBar) {
        currentBar.style.animation = 'none';
        currentBar.onanimationend = null;
    }
    currentViewingStatuses = [];
    isStatusPaused = false;
    document.getElementById('active-status-viewer').style.display = 'none';
}

/* -------------------------------------
   Calls Logic (WebRTC) Mesh Network
-------------------------------------- */
let activeCallId = null;
let activeCallPeerId = null;
let callTimerInterval = null;
let localStream = null;
let callChannel = null;
let isCallInitiator = false;
let activeCallMode = 'audio';

// Relay a call signal through the Laravel API (broadcast to the call channel)
// instead of a raw Pusher client-event whisper. Client-event whispers are only
// delivered peer-to-peer over the websocket and are not reliably received by
// the mobile app's native Pusher client, so mobile <-> web calls need the
// signal to go through the server the same way the mobile app already does.
async function sendCallSignal(endpoint, payload) {
    if (!activeCallId) return;
    try {
        await fetch(`/api/calls/${activeCallId}/${endpoint}`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });
    } catch (e) {
        console.error(`Failed to send ${endpoint} signal`, e);
    }
}

// Mesh Networking State
let peerConnections = {};
let remoteStreams = {};
let audioContexts = {};
let animationFrames = {};
// Remote candidates that arrived before setRemoteDescription resolved for that
// peer (offer/answer and candidate signals are independent async socket events
// and can race), keyed by peer id — flushed once the remote description lands.
// Without this, addIceCandidate throws InvalidStateError and the candidate is
// silently lost, which can leave ICE with no viable pair.
let pendingCandidates = {};
let remoteDescriptionSet = new Set();
// Peers we've already sent an offer to for the current call — guards against
// a duplicate `client-user-joined` (Pusher can deliver the same frame twice)
// triggering a second createOffer() on an already-negotiated connection,
// which fails with "the order of m-lines in subsequent offer doesn't match
// order from previous offer/answer" (Chrome won't reorder transceivers to
// match a stale negotiation). Reset alongside the other mesh state below.
let offeredPeers = new Set();

async function flushPendingCandidates(peerId, pc) {
    const pending = pendingCandidates[peerId];
    if (!pending) return;
    delete pendingCandidates[peerId];
    for (const candidate of pending) {
        await pc.addIceCandidate(candidate);
    }
}

// STUN discovers public addresses; a TURN relay is required when a direct
// peer path is blocked (Wi-Fi AP/client isolation, symmetric NAT, mobile data)
// or ICE fails. The relay itself is a short-lived Cloudflare Realtime TURN
// credential fetched from the backend per call (see
// CallController::turnCredentials — same endpoint the Flutter app uses),
// rather than a static VITE_TURN_URL baked into the build: that avoids
// shipping a long-lived TURN password to every browser tab, and Cloudflare's
// network reaches callers "everywhere," not just whatever LAN a self-hosted
// TURN box happens to sit on. Fetched once per call and cached in
// `cachedTurnServer` (reset in endCall) since a call may open several peer
// connections (group calls). Falls back to STUN-only if the fetch fails.
let cachedTurnServer = null;

async function fetchTurnServer() {
    try {
        const res = await fetch('/api/calls/turn-credentials', {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}` }
        });
        if (!res.ok) return null;
        const data = await res.json();
        return data.iceServers || null;
    } catch (e) {
        console.warn('[WebRTC] Failed to fetch TURN credentials, falling back to STUN-only', e);
        return null;
    }
}

function buildIceServers() {
    const servers = [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' }
    ];
    if (cachedTurnServer) servers.push(cachedTurnServer);
    return { iceServers: servers };
}

function setupAudioVisualizer(stream, targetElementId, identifierName) {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        
        // Prevent duplicate contexts
        if (audioContexts[identifierName]) {
            audioContexts[identifierName].close();
        }
        if (animationFrames[identifierName]) {
            cancelAnimationFrame(animationFrames[identifierName]);
        }

        const audioCtx = new AudioContext();
        audioContexts[identifierName] = audioCtx;
        
        const analyser = audioCtx.createAnalyser();
        analyser.fftSize = 256;
        analyser.smoothingTimeConstant = 0.8;
        
        const source = audioCtx.createMediaStreamSource(stream);
        source.connect(analyser);
        
        const dataArray = new Uint8Array(analyser.frequencyBinCount);
        
        const draw = () => {
            animationFrames[identifierName] = requestAnimationFrame(draw);
            analyser.getByteFrequencyData(dataArray);
            
            // Calculate average volume
            let sum = 0;
            for (let i = 0; i < dataArray.length; i++) {
                sum += dataArray[i];
            }
            const average = sum / dataArray.length;
            
            // Log sound when volume is significant
            if (average > 10) {
                console.log(`[Audio Log] ${identifierName} sound received. Volume: ${average.toFixed(2)}`);
            }
            
            // Visual echo effect
            const el = document.getElementById(targetElementId);
            if (el) {
                if (average > 5) {
                    const scale = 1 + (average / 255) * 0.15;
                    const glow = average / 2;
                    el.style.transform = `scale(${scale})`;
                    el.style.boxShadow = `0 0 ${glow}px ${glow/2}px rgba(99, 102, 241, 0.6)`;
                    el.style.transition = 'transform 0.05s ease, box-shadow 0.05s ease';
                } else {
                    el.style.transform = 'scale(1)';
                    el.style.boxShadow = 'none';
                    el.style.transition = 'transform 0.3s ease, box-shadow 0.3s ease';
                }
            }
        };
        
        draw();
    } catch (e) {
        console.warn('Audio visualization not supported or failed', e);
    }
}

async function setupMedia(type) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert("Your browser is blocking camera/microphone access. This usually happens when accessing the site via HTTP on a local network instead of HTTPS or localhost.\n\nTo test calls on your phone, you may need to enable 'Insecure origins treated as secure' in your browser flags (e.g. chrome://flags).");
        throw new Error("Media devices not supported or blocked by insecure context");
    }

    try {
        localStream = await navigator.mediaDevices.getUserMedia({
            video: type === 'video',
            audio: true
        });
        const localVideo = document.getElementById('local-video');
        localVideo.srcObject = localStream;
        if (type === 'video') localVideo.style.display = 'block';
        
        // Setup local audio visualizer
        setTimeout(() => {
            const visualizerTargetId = type === 'audio' ? 'call-avatar' : 'local-video';
            setupAudioVisualizer(localStream, visualizerTargetId, 'Local User');
        }, 500);
        
    } catch (e) {
        console.error("Error accessing media devices.", e);
        if (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError') {
            alert("Microphone/Camera permission was denied. Please allow permissions in your browser settings to make calls.");
        } else if (e.name === 'NotFoundError') {
            alert("No microphone or camera was found on your device.");
        } else {
            alert("Could not access camera or microphone. Please check permissions.");
        }
        throw e;
    }
}

function createPeerConnection(targetUserId) {
    if (peerConnections[targetUserId]) return peerConnections[targetUserId];

    const iceServers = buildIceServers();
    console.warn('[WebRTC] creating PeerConnection with ICE_SERVERS:', JSON.stringify(iceServers));
    const pc = new RTCPeerConnection(iceServers);
    peerConnections[targetUserId] = pc;

    if (localStream) {
        localStream.getTracks().forEach(track => {
            pc.addTrack(track, localStream);
        });
    }

    pc.ontrack = (event) => {
        const stream = (event.streams && event.streams[0]) ? event.streams[0] : null;

        if (!remoteStreams[targetUserId]) {
            remoteStreams[targetUserId] = stream || new MediaStream();
            addRemoteVideoElement(targetUserId);
        }

        if (!stream) {
            remoteStreams[targetUserId].addTrack(event.track);
        }

        // The <video> element is muted (see addRemoteVideoElement) and only
        // renders the video track — it's sometimes display:none for audio-only
        // calls, and hidden <video> elements silently fail to play audio in
        // some browsers (notably Safari/iOS). Remote sound is played through a
        // dedicated <audio> element instead, which has no such visibility quirk.
        const vid = document.getElementById(`remote-video-${targetUserId}`);
        if (vid) {
            vid.srcObject = remoteStreams[targetUserId];
            vid.play().catch(e => console.warn('[WebRTC] remote video play() blocked', targetUserId, e));
        }

        const audioEl = document.getElementById(`remote-audio-${targetUserId}`);
        if (audioEl) {
            audioEl.srcObject = remoteStreams[targetUserId];
            audioEl.play().catch(e => console.warn('[WebRTC] remote audio play() blocked', targetUserId, e));
        }

        // Setup remote audio visualizer
        setTimeout(() => {
            const visualizerTargetId = activeCallMode === 'audio' ? `remote-avatar-${targetUserId}` : `remote-wrapper-${targetUserId}`;
            setupAudioVisualizer(remoteStreams[targetUserId], visualizerTargetId, `Remote ${targetUserId}`);
        }, 500);
    };

    pc.onicecandidate = (event) => {
        if (event.candidate) {
            console.warn(`[WebRTC] local candidate for ${targetUserId}: type=${event.candidate.type} protocol=${event.candidate.protocol} address=${event.candidate.address || event.candidate.ip}`);
            sendCallSignal('candidate', {
                candidate: event.candidate,
                target_id: targetUserId
            });
        } else {
            console.warn(`[WebRTC] ICE gathering complete for ${targetUserId}`);
        }
    };

    pc.onicegatheringstatechange = () => {
        console.warn(`[WebRTC] ICE gathering state for ${targetUserId}: ${pc.iceGatheringState}`);
    };

    pc.oniceconnectionstatechange = () => {
        console.warn(`[WebRTC] ICE Connection State for ${targetUserId}: ${pc.iceConnectionState}`);
        if (pc.iceConnectionState === 'disconnected' || pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'closed') {
            removeRemoteVideoElement(targetUserId);
            if (peerConnections[targetUserId]) {
                peerConnections[targetUserId].close();
                delete peerConnections[targetUserId];
            }
            remoteDescriptionSet.delete(targetUserId);
            delete pendingCandidates[targetUserId];
        }
    };

    return pc;
}

function addRemoteVideoElement(userId, userName, userPhoto) {
    const container = document.getElementById('call-info-container');
    // Change to a grid layout
    container.style.display = 'flex';
    container.style.flexWrap = 'wrap';
    container.style.justifyContent = 'center';
    container.style.gap = '15px';
    container.style.padding = '20px';
    
    // Hide default single remote-video if it exists
    const defaultRemote = document.getElementById('remote-video');
    if (defaultRemote) defaultRemote.style.display = 'none';

    let wrapper = document.getElementById(`remote-wrapper-${userId}`);
    if (!wrapper) {
        // Find participant info if not provided via signaling
        let participant = null;
        let cachedData = window.callParticipantsData ? window.callParticipantsData[userId] : null;

        if (!userName && !cachedData && loadedChatData && loadedChatData.participants) {
            const p = loadedChatData.participants.find(p => p.user && p.user.id === userId);
            if (p) participant = p.user;
        }
        
        const finalName = userName || (cachedData ? cachedData.name : null) || (participant ? getUserDisplayName(participant) : `User ${userId.substring(0,4)}...`);
        const finalPhoto = userPhoto || (cachedData ? cachedData.photo : null) || participant?.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(finalName)}&background=FF5722&color=fff`;

        wrapper = document.createElement('div');
        wrapper.id = `remote-wrapper-${userId}`;
        wrapper.style.position = 'relative';
        wrapper.style.width = '200px';
        wrapper.style.height = '200px';
        wrapper.style.borderRadius = '16px';
        wrapper.style.overflow = 'hidden';
        wrapper.style.boxShadow = '0 8px 24px rgba(0,0,0,0.4)';
        wrapper.style.backgroundColor = 'var(--surface-color)';
        wrapper.style.display = 'flex';
        wrapper.style.alignItems = 'center';
        wrapper.style.justifyContent = 'center';

        const videoEl = document.createElement('video');
        videoEl.id = `remote-video-${userId}`;
        videoEl.autoplay = true;
        videoEl.playsInline = true;
        // Sound is played via the dedicated <audio> element below, not this
        // element — keeps audio playback working even when this video is
        // display:none (audio-only calls) or fails to render for any reason.
        videoEl.muted = true;
        videoEl.style.width = '100%';
        videoEl.style.height = '100%';
        videoEl.style.objectFit = 'cover';

        const audioEl = document.createElement('audio');
        audioEl.id = `remote-audio-${userId}`;
        audioEl.autoplay = true;

        if (activeCallMode === 'audio') {
            videoEl.style.display = 'none';
            // Show avatar for audio calls
            const img = document.createElement('img');
            img.src = finalPhoto;
            img.id = `remote-avatar-${userId}`;
            img.style.width = '80px';
            img.style.height = '80px';
            img.style.borderRadius = '50%';
            img.style.objectFit = 'cover';
            img.style.boxShadow = '0 4px 12px rgba(0,0,0,0.3)';
            wrapper.appendChild(img);
        }

        // Name overlay
        const nameLabel = document.createElement('div');
        nameLabel.innerText = finalName;
        nameLabel.style.position = 'absolute';
        nameLabel.style.bottom = '10px';
        nameLabel.style.left = '10px';
        nameLabel.style.right = '10px';
        nameLabel.style.textAlign = 'center';
        nameLabel.style.backgroundColor = 'rgba(0,0,0,0.6)';
        nameLabel.style.color = '#fff';
        nameLabel.style.padding = '4px 8px';
        nameLabel.style.borderRadius = '8px';
        nameLabel.style.fontSize = '0.85rem';
        nameLabel.style.fontWeight = '500';
        nameLabel.style.backdropFilter = 'blur(4px)';
        nameLabel.style.whiteSpace = 'nowrap';
        nameLabel.style.overflow = 'hidden';
        nameLabel.style.textOverflow = 'ellipsis';

        wrapper.appendChild(videoEl);
        wrapper.appendChild(audioEl);
        wrapper.appendChild(nameLabel);
        container.appendChild(wrapper);
    }

    // Attempt to play once stream is attached in ontrack
    const vid = document.getElementById(`remote-video-${userId}`);
    if (vid) vid.srcObject = remoteStreams[userId];
    const audio = document.getElementById(`remote-audio-${userId}`);
    if (audio) audio.srcObject = remoteStreams[userId];
}

function removeRemoteVideoElement(userId) {
    const wrapper = document.getElementById(`remote-wrapper-${userId}`);
    if (wrapper) {
        wrapper.remove();
    }
    delete remoteStreams[userId];
}

function setupCallSignaling() {
    // Guard against stale state from a call that didn't reach endCall() cleanly
    // (e.g. testing back-to-back attempts without a page reload). Reusing an
    // already-negotiated RTCPeerConnection for a brand new call makes
    // createOffer() produce a mismatched m-line order, which breaks the SDP
    // for the whole call — always start a new call with a clean slate.
    Object.values(peerConnections).forEach(pc => { try { pc.close(); } catch (e) {} });
    peerConnections = {};
    remoteStreams = {};
    remoteDescriptionSet = new Set();
    pendingCandidates = {};
    offeredPeers = new Set();

    callChannel = Echo.private('call.' + activeCallId);

    window.callParticipantsData = window.callParticipantsData || {};
    
    const handleUserJoined = async (data) => {
        if (data.userId === window.APP_USER.id) return;

        if (data.userName) {
            window.callParticipantsData[data.userId] = { name: data.userName, photo: data.userPhoto };
        }

        // See offeredPeers' declaration — a duplicate join frame for a peer
        // we've already offered to must not trigger a second createOffer().
        if (offeredPeers.has(data.userId)) return;
        offeredPeers.add(data.userId);

        // A new user joined, create PC and send Offer
        const pc = createPeerConnection(data.userId);
        try {
            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            sendCallSignal('offer', {
                offer: offer,
                target_id: data.userId,
                sender_name: getUserDisplayName(window.APP_USER),
                sender_photo: window.APP_USER.photo_url || ''
            });
        } catch (e) {
            console.error("Error creating offer", e);
        }
    };

    callChannel.listenForWhisper('user-joined', handleUserJoined);
    callChannel.listen('.client-user-joined', handleUserJoined);

    // WebRTC SDP requires every line, including the last, to be terminated
    // with CRLF ("\r\n"). Chrome's own createOffer()/createAnswer() output
    // doesn't always append a trailing CRLF after the final attribute line —
    // harmless for the local peer's own setLocalDescription(), but the
    // *receiving* side's setRemoteDescription() rejects it outright with
    // "Failed to parse SessionDescription... Invalid SDP line", pointing at
    // that last line even though its own content is fine. Confirmed via a
    // raw dump: every line had a trailing \r except the last. Normalize
    // internal line endings and guarantee a trailing CRLF before parsing.
    const normalizeSdp = (desc) => {
        if (!desc || typeof desc.sdp !== 'string') return desc;
        let fixed = desc.sdp.replace(/\r\n/g, '\n').replace(/\n/g, '\r\n');
        if (!fixed.endsWith('\r\n')) {
            fixed += '\r\n';
        }
        if (fixed !== desc.sdp) {
            console.warn('[WebRTC] SDP was missing proper line termination and has been repaired');
        }
        return { ...desc, sdp: fixed };
    };

    const handleWebRTCSignal = async (data) => {
        const senderId = data.senderId || data.userId;
        const targetId = data.targetId;

        // If targetId is explicitly provided and not for us, ignore it.
        if (targetId && targetId !== window.APP_USER.id) return;
        
        // Ignore our own signals echoed back
        if (!senderId || senderId === window.APP_USER.id) return;

        if (data.senderName) {
            window.callParticipantsData[senderId] = { name: data.senderName, photo: data.senderPhoto };
        }

        try {
            if (data.offer) {
                const pc = createPeerConnection(senderId);
                await pc.setRemoteDescription(new RTCSessionDescription(normalizeSdp(data.offer)));
                remoteDescriptionSet.add(senderId);
                await flushPendingCandidates(senderId, pc);
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                sendCallSignal('answer', {
                    answer: answer,
                    target_id: senderId,
                    sender_name: getUserDisplayName(window.APP_USER),
                    sender_photo: window.APP_USER.photo_url || ''
                });
            } else if (data.answer) {
                const pc = peerConnections[senderId];
                if (pc) {
                    await pc.setRemoteDescription(new RTCSessionDescription(normalizeSdp(data.answer)));
                    remoteDescriptionSet.add(senderId);
                    await flushPendingCandidates(senderId, pc);
                }
            } else if (data.candidate) {
                const pc = peerConnections[senderId];
                const candidate = new RTCIceCandidate(data.candidate);
                if (pc && remoteDescriptionSet.has(senderId)) {
                    await pc.addIceCandidate(candidate);
                } else {
                    (pendingCandidates[senderId] ??= []).push(candidate);
                }
            }
        } catch (e) {
            console.error("WebRTC signaling error", e);
            // Dump the raw SDP with lines numbered and JSON-escaped so any
            // invisible/control character or truncation is visible verbatim,
            // instead of guessing from the browser's one-line error summary.
            const badSdp = data.offer?.sdp || data.answer?.sdp;
            if (badSdp) {
                console.error('[WebRTC] raw SDP that failed to parse (' + badSdp.length + ' chars):\n' +
                    badSdp.split('\n').map((line, i) => `${i}: ${JSON.stringify(line)}`).join('\n'));
            }
        }
    };

    callChannel.listenForWhisper('webrtc-signal', handleWebRTCSignal);
    callChannel.listen('.client-webrtc-signal', handleWebRTCSignal);
}

async function initiateCall(type) {
    if (!currentChatId) return;

    activeCallMode = type;
    isCallInitiator = true;
    
    const isGroup = currentChatType === 'group';
    let receiver = null;
    
    if (!isGroup) {
        receiver = loadedChatData?.participants?.find(p => p.user.id !== window.APP_USER.id)?.user;
        if (!receiver) return;
    }

    activeCallPeerId = receiver ? receiver.id : null;

    try {
        cachedTurnServer = await fetchTurnServer();
        await setupMedia(type);
        showCallOverlay('Calling...', isGroup ? {name: loadedChatData.group?.group_name || 'Group', photo_url: loadedChatData.group?.group_image_url || ''} : receiver, false);
        
        const payload = isGroup ? { chat_id: currentChatId, call_type: type } : { receiver_id: receiver.id, call_type: type };

        const response = await fetch('/api/calls', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        activeCallId = data.call.id;
        startRingtone();
        setupCallSignaling();
        
        // Since we initiated, we are in the room. Wait for others.
    } catch (e) {
        console.error("Failed to initiate call", e);
        endCall();
    }
}

function handleIncomingCall(call) {
    console.log('[call] IncomingCall received, call.id =', call.id, 'previous activeCallId =', activeCallId, call);
    activeCallId = call.id;
    activeCallPeerId = call.caller_id || call.caller?.id || null;
    activeCallMode = call.call_type;
    isCallInitiator = false;
    
    let callerInfo = call.caller;
    if (call.chat_id && call.chat && call.chat.chat_type === 'group') {
        // Group call — show group name + who started the call
        callerInfo = { 
            name: call.chat.group?.group_name || 'Group Call',
            photo_url: call.chat.group?.group_image_url || ''
        };
    }
    
    const statusText = (call.chat_id && call.chat && call.chat.chat_type === 'group')
        ? `Group Call from ${getUserDisplayName(call.caller)}`
        : 'Incoming Call';
    
    showCallOverlay(statusText, callerInfo, true);
    startRingtone();
    
    // Do not setup signaling until they accept
}

function showCallOverlay(statusText, user, isIncoming) {
    const overlay = document.getElementById('call-overlay');
    overlay.style.display = 'flex';
    
    const container = document.getElementById('call-info-container');
    container.style.display = 'flex';
    container.style.flexDirection = 'column';
    container.style.alignItems = 'center';
    container.innerHTML = `
        <div id="call-status-text" class="call-status-text">${escapeHTML(statusText)}</div>
        <img id="call-avatar" src="" class="call-avatar" alt="Caller" style="width: 100px; height: 100px; border-radius: 50%; margin: 20px 0; object-fit: cover; box-shadow: 0 4px 15px rgba(0,0,0,0.5);">
        <div id="call-name" class="call-name"></div>
        <div id="call-timer" class="call-timer" style="display: none;">00:00</div>
    `;

    document.getElementById('call-name').innerText = getUserDisplayName(user);
    document.getElementById('call-avatar').src = user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`;

    const incomingActions = document.getElementById('call-incoming-actions');
    const activeActions = document.getElementById('call-active-actions');

    if (isIncoming) {
        incomingActions.classList.add('visible');
        activeActions.classList.remove('visible');
    } else {
        incomingActions.classList.remove('visible');
        activeActions.classList.add('visible');
    }
}

// Guards acceptCall() against firing more than once per call. Without this,
// a double-click/double-tap on the accept button (common on touchscreens,
// where "touchend" and a synthetic "click" can both land) re-runs
// setupMedia() (grabbing a second, orphaned localStream) and
// setupCallSignaling() (which resets peerConnections/remoteStreams and
// re-registers Echo listeners on top of the still-active ones from the
// first run) mid-negotiation — server logs show this happening for real,
// with the same call_id accepted several times a few seconds apart.
let acceptCallInFlight = null;

async function acceptCall() {
    console.log('[call] acceptCall() clicked, activeCallId =', activeCallId);
    if (!activeCallId) return;
    if (acceptCallInFlight === activeCallId) return;
    acceptCallInFlight = activeCallId;

    try {
        cachedTurnServer = await fetchTurnServer();
        await setupMedia(activeCallMode);

        await fetch(`/api/calls/${activeCallId}/accept`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });

        setupCallSignaling();

        // Notify others we joined so they can send us an offer. Relayed through
        // the server (not a client whisper) so it reliably reaches mobile peers.
        sendCallSignal('join', {
            target_id: activeCallPeerId,
            user_name: getUserDisplayName(window.APP_USER),
            user_photo: window.APP_USER.photo_url || ''
        });

        startActiveCallUI();
    } catch (e) {
        console.error("Accept failed", e);
        acceptCallInFlight = null;
        endCall();
    }
}

async function declineCall() {
    if (!activeCallId) return;
    try {
        await fetch(`/api/calls/${activeCallId}/decline`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        endCall();
    } catch (e) {
        endCall();
    }
}

async function handleCallAnswered(call) {
    if (activeCallId === call.id) {
        startActiveCallUI();
    }
}

function handleCallDeclined(call) {
    if (activeCallId === call.id) {
        if (call.status === 'rejected') {
            document.getElementById('call-status-text').innerText = "Call Declined";
        } else {
            document.getElementById('call-status-text').innerText = "Call Ended";
        }
        setTimeout(endCall, 2000);
    }
}

function startActiveCallUI() {
    stopRingtone();
    const statusEl = document.getElementById('call-status-text');
    if (statusEl) statusEl.innerText = "Active Call";
    
    document.getElementById('call-incoming-actions').classList.remove('visible');
    document.getElementById('call-active-actions').classList.add('visible');

    const timerEl = document.getElementById('call-timer');
    timerEl.style.display = 'block';
    timerEl.innerText = "00:00";

    let seconds = 0;
    clearInterval(callTimerInterval);
    callTimerInterval = setInterval(() => {
        seconds++;
        const m = String(Math.floor(seconds / 60)).padStart(2, '0');
        const s = String(seconds % 60).padStart(2, '0');
        timerEl.innerText = `${m}:${s}`;
    }, 1000);
    
    // Hide avatar if video call
    if (activeCallMode === 'video') {
        const avatar = document.getElementById('call-avatar');
        if (avatar) avatar.style.display = 'none';
        const name = document.getElementById('call-name');
        if (name) name.style.display = 'none';
    }
}

let isMuted = false;

function toggleMute() {
    if (!localStream) return;
    
    isMuted = !isMuted;
    
    localStream.getAudioTracks().forEach(track => {
        track.enabled = !isMuted;
    });
    
    const micOn = document.getElementById('icon-mic-on');
    const micOff = document.getElementById('icon-mic-off');
    const muteBtn = document.getElementById('btn-call-mute');
    
    if (isMuted) {
        if (micOn) micOn.style.display = 'none';
        if (micOff) micOff.style.display = 'block';
        if (muteBtn) muteBtn.style.backgroundColor = 'rgba(239, 68, 68, 0.8)'; // Red when muted
    } else {
        if (micOn) micOn.style.display = 'block';
        if (micOff) micOff.style.display = 'none';
        if (muteBtn) muteBtn.style.backgroundColor = 'rgba(255, 255, 255, 0.2)'; // Normal
    }
}

function endCall() {
    stopRingtone();
    clearInterval(callTimerInterval);

    if (activeCallId) {
        fetch(`/api/calls/${activeCallId}/end`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}` }
        }).catch(e => console.error("Error ending call", e));
    }

    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }
    
    // Close all peer connections
    Object.values(peerConnections).forEach(pc => pc.close());
    peerConnections = {};
    remoteStreams = {};
    remoteDescriptionSet = new Set();
    pendingCandidates = {};
    cachedTurnServer = null;
    offeredPeers = new Set();
    
    // Clean up audio visualizers
    Object.keys(animationFrames).forEach(id => cancelAnimationFrame(animationFrames[id]));
    animationFrames = {};
    Object.values(audioContexts).forEach(ctx => {
        try { ctx.close(); } catch (e) {}
    });
    audioContexts = {};

    if (callChannel) {
        Echo.leave('call.' + activeCallId);
        callChannel = null;
    }

    activeCallId = null;
    activeCallPeerId = null;
    isCallInitiator = false;
    acceptCallInFlight = null;

    // Reset mute state
    if (isMuted) {
        toggleMute(); // Will toggle back to unmuted
    }

    document.getElementById('local-video').style.display = 'none';
    document.getElementById('local-video').srcObject = null;
    document.getElementById('call-overlay').style.display = 'none';
    
    // Clear dynamic videos
    const container = document.getElementById('call-info-container');
    container.innerHTML = '';
}

async function openCallsPanel() {
    const panel = document.getElementById('calls-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);
    const list = document.getElementById('call-logs-list');
    list.innerHTML = '<div class="loading-text">Loading call logs...</div>';

    try {
        const response = await fetch('/api/calls', {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}` }
        });
        const data = await response.json();

        list.innerHTML = '';
        if (data.calls.length === 0) {
            list.innerHTML = '<div class="loading-text">No recent calls</div>';
            return;
        }

        data.calls.forEach(call => {
            const isOutgoing = call.caller_id === window.APP_USER.id;
            const icon = call.call_type === 'video' ? '📹' : '📞';
            const date = new Date(call.created_at).toLocaleString([], {month:'short', day:'numeric', hour: '2-digit', minute:'2-digit'});

            let displayName = 'Unknown';
            let photoUrl = '';

            if (call.chat_id && call.chat && call.chat.group) {
                // Group Call
                displayName = call.chat.group.group_name || 'Group';
                photoUrl = call.chat.group.group_image_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(displayName)}&background=FF5722&color=fff`;
                if (!isOutgoing && call.caller) {
                    displayName += ` (from ${getUserDisplayName(call.caller)})`;
                }
            } else {
                // Direct Call
                const otherUser = isOutgoing ? call.receiver : call.caller;
                if (otherUser) {
                    displayName = getUserDisplayName(otherUser);
                    photoUrl = otherUser.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(displayName)}&background=FF5722&color=fff`;
                }
            }

            let statusText = '';
            let color = 'var(--text-secondary)';
            if (call.status === 'missed') {
                statusText = isOutgoing ? 'Cancelled' : 'Missed';
                if (!isOutgoing) color = 'var(--danger-error,  #ef4444)';
            } else if (call.status === 'rejected') {
                statusText = 'Declined';
            } else {
                statusText = `Answered (${Math.floor(call.duration_seconds / 60)}:${String(call.duration_seconds % 60).padStart(2, '0')})`;
            }

            const div = document.createElement('div');
            div.className = 'chat-item';
            div.innerHTML = `
                <div class="chat-item-pic-wrapper">
                    <img src="${photoUrl}" class="chat-item-pic">
                </div>
                <div class="chat-info">
                    <div class="chat-info-top">
                        <span class="chat-name">${escapeHTML(displayName)}</span>
                        <span class="chat-time">${date}</span>
                    </div>
                    <div class="chat-info-bottom">
                        <span class="chat-last-msg" style="color: ${color}; font-weight: 500;">
                            ${isOutgoing ? '↗' : '↙'} ${icon} ${statusText}
                        </span>
                    </div>
                </div>
            `;
            list.appendChild(div);
        });
    } catch (e) {
        console.error(e);
        list.innerHTML = '<div class="loading-text">Failed to load calls</div>';
    }
}

/* ========================================================
   MEETINGS PANEL LOGIC
======================================================== */

async function openMeetingsPanel() {
    const panel = document.getElementById('meetings-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);
    await renderMeetingsList();
}

async function renderMeetingsList() {
    const list = document.getElementById('meetings-list');
    list.innerHTML = '<div class="loading-text">Loading meetings...</div>';

    try {
        const response = await fetch('/api/meetings', {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        list.innerHTML = '';

        if (!data.meetings || data.meetings.length === 0) {
            list.innerHTML = '<div class="loading-text">No meetings scheduled</div>';
            return;
        }

        data.meetings
            .slice()
            .sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at))
            .forEach(meeting => {
                const isHost = meeting.host_id === window.APP_USER.id;
                const myInvite = (meeting.invitees || []).find(i => i.user_id === window.APP_USER.id);
                const needsResponse = !isHost && myInvite && myInvite.status === 'invited';
                const scheduledAt = new Date(meeting.scheduled_at);
                const endsAt = new Date(scheduledAt.getTime() + meeting.duration_minutes * 60000);
                const isPast = new Date() > endsAt;
                const isJoinable = !isPast && new Date() > new Date(scheduledAt.getTime() - 5 * 60000);
                const hostName = meeting.host ? getUserDisplayName(meeting.host) : 'Unknown';
                const photoUrl = meeting.host?.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(hostName)}&background=FF5722&color=fff`;

                const div = document.createElement('div');
                div.className = 'chat-item';
                div.innerHTML = `
                    <div class="chat-item-pic-wrapper">
                        <img src="${photoUrl}" class="chat-item-pic">
                    </div>
                    <div class="chat-info">
                        <div class="chat-info-top">
                            <span class="chat-name">${escapeHTML(meeting.title)}</span>
                        </div>
                        <div class="chat-info-bottom">
                            <span class="chat-last-msg">${scheduledAt.toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'})} · ${meeting.duration_minutes} min${isPast ? ' · Ended' : ''}</span>
                        </div>
                    </div>
                    <div class="meeting-actions"></div>
                `;

                const actions = div.querySelector('.meeting-actions');
                if (needsResponse) {
                    const acceptBtn = document.createElement('button');
                    acceptBtn.className = 'btn-outline meeting-action-btn';
                    acceptBtn.textContent = 'Accept';
                    acceptBtn.onclick = (e) => { e.stopPropagation(); respondToMeeting(meeting.id, 'accepted'); };
                    const declineBtn = document.createElement('button');
                    declineBtn.className = 'btn-outline danger meeting-action-btn';
                    declineBtn.textContent = 'Decline';
                    declineBtn.onclick = (e) => { e.stopPropagation(); respondToMeeting(meeting.id, 'declined'); };
                    actions.appendChild(acceptBtn);
                    actions.appendChild(declineBtn);
                } else if (isJoinable) {
                    const joinBtn = document.createElement('button');
                    joinBtn.className = 'btn-outline meeting-action-btn';
                    joinBtn.textContent = 'Join';
                    joinBtn.onclick = (e) => { e.stopPropagation(); joinMeeting(meeting); };
                    actions.appendChild(joinBtn);
                }
                const icsBtn = document.createElement('button');
                icsBtn.className = 'btn-outline meeting-action-btn meeting-action-btn-icon';
                icsBtn.title = 'Add to calendar';
                icsBtn.textContent = '📅';
                icsBtn.onclick = (e) => { e.stopPropagation(); downloadMeetingIcs(meeting.id); };
                actions.appendChild(icsBtn);

                list.appendChild(div);
            });
    } catch (e) {
        console.error('Failed to load meetings', e);
        list.innerHTML = '<div class="loading-text">Failed to load meetings</div>';
    }
}

async function respondToMeeting(meetingId, status) {
    try {
        await fetch(`/api/meetings/${meetingId}/respond`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ status })
        });
        renderMeetingsList();
    } catch (e) {
        console.error('Failed to respond to meeting', e);
    }
}

async function joinMeeting(meeting) {
    if (!meeting.chat_id) return;
    try {
        // Bookkeeping only (marks started_at) — the actual call reuses
        // initiateCall(), the same function any group call goes through, so
        // there's only one place that creates a Call and rings people.
        await fetch(`/api/meetings/${meeting.id}/start`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
    } catch (e) {
        console.error('Failed to mark meeting started', e);
    }

    document.getElementById('meetings-panel').style.transform = 'translateX(-100%)';
    setTimeout(() => document.getElementById('meetings-panel').style.display = 'none', 300);

    await loadChat(meeting.chat_id, meeting.title, 'group');
    initiateCall(meeting.call_type === 'audio' ? 'audio' : 'video');
}

async function downloadMeetingIcs(meetingId) {
    try {
        const response = await fetch(`/api/meetings/${meetingId}/ics`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'meeting.ics';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (e) {
        console.error('Failed to download calendar file', e);
    }
}

async function openScheduleMeetingPanel() {
    selectedMeetingInvitees = [];
    document.getElementById('meeting-title-input').value = '';
    document.getElementById('meeting-description-input').value = '';
    document.getElementById('meeting-duration-input').value = '30';
    document.getElementById('meeting-call-type-input').value = 'video';

    const defaultStart = new Date(Date.now() + 60 * 60000);
    document.getElementById('meeting-date-input').value = defaultStart.toISOString().slice(0, 10);
    document.getElementById('meeting-time-input').value = defaultStart.toTimeString().slice(0, 5);

    const panel = document.getElementById('schedule-meeting-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);

    const listEl = document.getElementById('meeting-invite-contact-list');
    listEl.innerHTML = '<div class="loading-text">Loading contacts...</div>';

    try {
        const response = await fetch(`/api/users/search?q=`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        listEl.innerHTML = '';

        data.users.forEach(user => {
            if (user.id === window.APP_USER.id) return;

            const item = document.createElement('div');
            item.className = 'chat-item contact-item';
            item.onclick = () => {
                const idx = selectedMeetingInvitees.indexOf(user.id);
                if (idx > -1) {
                    selectedMeetingInvitees.splice(idx, 1);
                    item.classList.remove('selected');
                    item.querySelector('.contact-item-checkbox').innerHTML = '';
                } else {
                    selectedMeetingInvitees.push(user.id);
                    item.classList.add('selected');
                    item.querySelector('.contact-item-checkbox').innerHTML = '✓';
                }
            };

            item.innerHTML = `
                <div class="contact-item-checkbox"></div>
                <div class="chat-item-pic-wrapper">
                    <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}" class="chat-item-pic">
                </div>
                <div class="chat-info">
                    <div class="chat-info-top">
                        <span class="chat-name">${escapeHTML(getUserDisplayName(user))}</span>
                    </div>
                </div>
            `;
            listEl.appendChild(item);
        });
    } catch (e) {
        listEl.innerHTML = '<div class="loading-text text-danger">Failed to load contacts.</div>';
    }
}

async function submitScheduleMeeting() {
    const title = document.getElementById('meeting-title-input').value.trim();
    const description = document.getElementById('meeting-description-input').value.trim();
    const date = document.getElementById('meeting-date-input').value;
    const time = document.getElementById('meeting-time-input').value;
    const durationMinutes = parseInt(document.getElementById('meeting-duration-input').value, 10);
    const callType = document.getElementById('meeting-call-type-input').value;

    if (!title || !date || !time || selectedMeetingInvitees.length === 0) {
        alert('Enter a title, date/time, and invite at least one participant.');
        return;
    }

    const scheduledAt = new Date(`${date}T${time}`);

    try {
        const response = await fetch('/api/meetings', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                title,
                description: description || null,
                scheduled_at: scheduledAt.toISOString(),
                duration_minutes: durationMinutes,
                call_type: callType,
                invitee_ids: selectedMeetingInvitees
            })
        });
        if (!response.ok) {
            const err = await response.json();
            alert(err.error || 'Failed to schedule meeting');
            return;
        }

        document.getElementById('schedule-meeting-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('schedule-meeting-panel').style.display = 'none', 300);
        renderMeetingsList();
    } catch (e) {
        console.error('Failed to schedule meeting', e);
        alert('Failed to schedule meeting');
    }
}

/* ========================================================
   EMAIL (IMAP/SMTP account linking) LOGIC
======================================================== */

async function openEmailAccountsPanel() {
    const panel = document.getElementById('email-accounts-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);
    await renderEmailAccountsList();
}

// Summed across every connected account and shown on the header mail icon,
// capped at "99+" — mirrors the Flutter app's Email tab badge. Called
// wherever the account list is (re)fetched or a message's read state
// changes, so it stays roughly in sync without needing its own poll loop.
function updateEmailUnreadBadge(accounts) {
    const total = (accounts || []).reduce((sum, a) => sum + (a.unread_count || 0), 0);
    const badge = document.getElementById('email-unread-badge');
    if (total > 0) {
        badge.textContent = total > 99 ? '99+' : String(total);
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

async function refreshEmailUnreadBadge() {
    try {
        const response = await fetch('/api/email-accounts', {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        updateEmailUnreadBadge(data.email_accounts);
    } catch (e) {
        console.error('Failed to refresh email unread badge', e);
    }
}

async function renderEmailAccountsList() {
    const list = document.getElementById('email-accounts-list');
    list.innerHTML = '<div class="loading-text">Loading email accounts...</div>';

    try {
        const response = await fetch('/api/email-accounts', {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        list.innerHTML = '';
        updateEmailUnreadBadge(data.email_accounts);

        if (!data.email_accounts || data.email_accounts.length === 0) {
            list.innerHTML = '<div class="loading-text">No email accounts linked yet</div>';
            return;
        }

        data.email_accounts.forEach(account => {
            const unreadCount = account.unread_count || 0;
            const div = document.createElement('div');
            div.className = 'chat-item';
            div.innerHTML = `
                <div class="chat-item-pic-wrapper">
                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(account.email_address)}&background=FF5722&color=fff" class="chat-item-pic">
                </div>
                <div class="chat-info">
                    <div class="chat-info-top">
                        <span class="chat-name" style="font-weight:${unreadCount > 0 ? '700' : 'normal'};">${escapeHTML(account.email_address)}</span>
                    </div>
                    <div class="chat-info-bottom">
                        <span class="chat-last-msg">${account.provider === 'yahoo' ? 'Yahoo Mail' : account.provider === 'custom' ? 'Custom (IMAP/SMTP)' : 'Gmail'}</span>
                    </div>
                </div>
                <div class="meeting-actions"></div>
            `;
            const actions = div.querySelector('.meeting-actions');
            if (unreadCount > 0) {
                const badge = document.createElement('div');
                badge.className = 'unread-badge';
                badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                actions.appendChild(badge);
            }
            const unlinkBtn = document.createElement('button');
            unlinkBtn.className = 'btn-outline danger meeting-action-btn';
            unlinkBtn.textContent = 'Unlink';
            unlinkBtn.onclick = (e) => { e.stopPropagation(); unlinkEmailAccount(account); };
            actions.appendChild(unlinkBtn);

            div.addEventListener('click', () => openEmailInboxPanel(account));
            list.appendChild(div);
        });
    } catch (e) {
        console.error('Failed to load email accounts', e);
        list.innerHTML = '<div class="loading-text">Failed to load email accounts</div>';
    }
}

async function unlinkEmailAccount(account) {
    if (!confirm(`Stop syncing ${account.email_address}? Downloaded emails will be removed from SamChat.`)) return;
    try {
        await fetch(`/api/email-accounts/${account.id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        renderEmailAccountsList();
    } catch (e) {
        console.error('Failed to unlink email account', e);
    }
}

// Well-known public IMAP/SMTP settings for common providers, keyed by email
// domain, so a "Custom" account mostly needs just an address + password.
// Anything not listed falls back to a `mail.<domain>` guess (the convention
// most small/self-hosted mail setups follow) — the fields stay editable
// specifically so a wrong guess can be corrected before connecting.
const MAIL_PRESETS = {
    'gmail.com': ['imap.gmail.com', 993, 'ssl', 'smtp.gmail.com', 465, 'ssl'],
    'googlemail.com': ['imap.gmail.com', 993, 'ssl', 'smtp.gmail.com', 465, 'ssl'],
    'yahoo.com': ['imap.mail.yahoo.com', 993, 'ssl', 'smtp.mail.yahoo.com', 465, 'ssl'],
    'ymail.com': ['imap.mail.yahoo.com', 993, 'ssl', 'smtp.mail.yahoo.com', 465, 'ssl'],
    'outlook.com': ['outlook.office365.com', 993, 'ssl', 'smtp-mail.outlook.com', 587, 'tls'],
    'hotmail.com': ['outlook.office365.com', 993, 'ssl', 'smtp-mail.outlook.com', 587, 'tls'],
    'live.com': ['outlook.office365.com', 993, 'ssl', 'smtp-mail.outlook.com', 587, 'tls'],
    'msn.com': ['outlook.office365.com', 993, 'ssl', 'smtp-mail.outlook.com', 587, 'tls'],
    'icloud.com': ['imap.mail.me.com', 993, 'ssl', 'smtp.mail.me.com', 587, 'tls'],
    'me.com': ['imap.mail.me.com', 993, 'ssl', 'smtp.mail.me.com', 587, 'tls'],
    'mac.com': ['imap.mail.me.com', 993, 'ssl', 'smtp.mail.me.com', 587, 'tls'],
    'aol.com': ['imap.aol.com', 993, 'ssl', 'smtp.aol.com', 465, 'ssl'],
    'zoho.com': ['imap.zoho.com', 993, 'ssl', 'smtp.zoho.com', 465, 'ssl'],
    'gmx.com': ['imap.gmx.com', 993, 'ssl', 'smtp.gmx.com', 465, 'ssl'],
};

function guessMailPresetForDomain(domain) {
    if (MAIL_PRESETS[domain]) return MAIL_PRESETS[domain];
    return [`mail.${domain}`, 993, 'ssl', `mail.${domain}`, 465, 'ssl'];
}

// Keeps the (possibly hidden) IMAP/SMTP fields in sync with whatever domain
// the user has typed so far — connecting works without ever opening "Edit
// IMAP/SMTP settings" for a recognized/guessable domain. Callers only invoke
// this while the detail panel is closed (or about to open), so it never
// clobbers a manual edit made after opening it.
function applyEmailPresetFromAddress() {
    const email = document.getElementById('email-address-input').value.trim();
    const atIndex = email.indexOf('@');
    if (atIndex === -1 || atIndex === email.length - 1) return;
    const domain = email.substring(atIndex + 1).toLowerCase();
    const [imapHost, imapPort, imapEnc, smtpHost, smtpPort, smtpEnc] = guessMailPresetForDomain(domain);
    document.getElementById('email-imap-host-input').value = imapHost;
    document.getElementById('email-imap-port-input').value = imapPort;
    document.getElementById('email-imap-encryption-input').value = imapEnc;
    document.getElementById('email-smtp-host-input').value = smtpHost;
    document.getElementById('email-smtp-port-input').value = smtpPort;
    document.getElementById('email-smtp-encryption-input').value = smtpEnc;
}

function toggleEmailCustomFields() {
    const detail = document.getElementById('email-custom-fields');
    const opening = detail.style.display === 'none';
    if (opening) applyEmailPresetFromAddress();
    detail.style.display = opening ? 'block' : 'none';
    document.getElementById('btn-toggle-email-custom-fields').textContent =
        opening ? 'Hide IMAP/SMTP settings' : 'Edit IMAP/SMTP settings';
}

function updateEmailAppPasswordHelp() {
    const provider = document.getElementById('email-provider-input').value;
    const isCustom = provider === 'custom';

    document.getElementById('email-preset-help').style.display = isCustom ? 'none' : 'block';
    document.getElementById('email-custom-intro').style.display = isCustom ? 'block' : 'none';
    document.getElementById('email-app-password-input').placeholder = isCustom ? 'Password' : 'App password';
    if (isCustom) {
        applyEmailPresetFromAddress();
        return;
    }

    const link = document.getElementById('email-app-password-help-link');
    if (provider === 'yahoo') {
        link.href = 'https://login.yahoo.com/myaccount/security';
        link.textContent = 'Generate an app password for Yahoo Mail';
    } else {
        link.href = 'https://myaccount.google.com/apppasswords';
        link.textContent = 'Generate an app password for Gmail';
    }
}

function openConnectEmailPanel() {
    document.getElementById('email-provider-input').value = 'gmail';
    document.getElementById('email-address-input').value = '';
    document.getElementById('email-app-password-input').value = '';
    document.getElementById('email-imap-host-input').value = '';
    document.getElementById('email-imap-port-input').value = '';
    document.getElementById('email-imap-encryption-input').value = 'ssl';
    document.getElementById('email-smtp-host-input').value = '';
    document.getElementById('email-smtp-port-input').value = '';
    document.getElementById('email-smtp-encryption-input').value = 'ssl';
    document.getElementById('connect-email-error').style.display = 'none';
    document.getElementById('email-custom-fields').style.display = 'none';
    document.getElementById('btn-toggle-email-custom-fields').textContent = 'Edit IMAP/SMTP settings';
    updateEmailAppPasswordHelp();

    const panel = document.getElementById('connect-email-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);
}

async function submitConnectEmail() {
    const provider = document.getElementById('email-provider-input').value;
    const emailAddress = document.getElementById('email-address-input').value.trim();
    const appPassword = document.getElementById('email-app-password-input').value.trim();
    const errorEl = document.getElementById('connect-email-error');
    errorEl.style.display = 'none';

    if (!emailAddress || !appPassword) return;

    const payload = { provider, email_address: emailAddress, app_password: appPassword };
    if (provider === 'custom') {
        const imapHost = document.getElementById('email-imap-host-input').value.trim();
        const imapPort = document.getElementById('email-imap-port-input').value.trim();
        const smtpHost = document.getElementById('email-smtp-host-input').value.trim();
        const smtpPort = document.getElementById('email-smtp-port-input').value.trim();
        if (!imapHost || !imapPort || !smtpHost || !smtpPort) {
            errorEl.textContent = 'Fill in the IMAP and SMTP server details.';
            errorEl.style.display = 'block';
            return;
        }
        payload.imap_host = imapHost;
        payload.imap_port = parseInt(imapPort, 10);
        payload.imap_encryption = document.getElementById('email-imap-encryption-input').value;
        payload.smtp_host = smtpHost;
        payload.smtp_port = parseInt(smtpPort, 10);
        payload.smtp_encryption = document.getElementById('email-smtp-encryption-input').value;
    }

    const submitBtn = document.getElementById('btn-submit-connect-email');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Connecting...';

    try {
        const response = await fetch('/api/email-accounts', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        const data = await response.json();

        if (!response.ok) {
            errorEl.textContent = data.error || 'Could not connect to that account.';
            errorEl.style.display = 'block';
            return;
        }

        document.getElementById('connect-email-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('connect-email-panel').style.display = 'none', 300);
        renderEmailAccountsList();
    } catch (e) {
        console.error('Failed to connect email account', e);
        errorEl.textContent = 'Could not connect to that account.';
        errorEl.style.display = 'block';
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Connect';
    }
}

async function openEmailInboxPanel(account) {
    currentEmailAccount = account;
    currentEmailPage = 1;
    currentEmailHasMore = false;

    document.getElementById('email-inbox-title').textContent = account.email_address;
    document.getElementById('email-inbox-list').innerHTML = '<div class="loading-text">Loading emails...</div>';

    const panel = document.getElementById('email-inbox-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);

    await loadEmailInboxPage(true);
}

// Fetches new mail over real IMAP — a separate request from connecting the
// account (see submitConnectEmail), since real mailbox I/O can be slow and
// used to run inline during connect, which caused connect requests to time
// out. Best-effort: a sync failure shouldn't block showing whatever's
// already saved locally, so callers ignore rejections from this.
async function syncEmailAccount(accountId) {
    const response = await fetch(`/api/email-accounts/${accountId}/sync`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
    });
    if (!response.ok) throw new Error('sync failed');
}

async function loadEmailInboxPage(reset = false) {
    if (!currentEmailAccount) return;
    if (!reset && (currentEmailLoadingMore || !currentEmailHasMore)) return;

    const list = document.getElementById('email-inbox-list');
    if (reset) {
        currentEmailPage = 1;
        try {
            await syncEmailAccount(currentEmailAccount.id);
        } catch (e) {
            console.warn('Email sync failed, showing cached inbox', e);
        }
    }
    currentEmailLoadingMore = true;

    try {
        const response = await fetch(`/api/email-accounts/${currentEmailAccount.id}/emails?page=${currentEmailPage}`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        const paginator = data.emails || {};
        const emails = paginator.data || [];

        if (reset) list.innerHTML = '';
        if (reset && emails.length === 0) {
            list.innerHTML = '<div class="loading-text">No emails yet</div>';
        }

        emails.forEach(email => {
            const div = document.createElement('div');
            div.className = 'chat-item';
            const senderName = (email.from_name && email.from_name.length) ? email.from_name : (email.from_address || 'Unknown');
            const weight = email.is_read ? 'normal' : '700';
            div.innerHTML = `
                <div class="chat-item-pic-wrapper">
                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(senderName)}&background=FF5722&color=fff" class="chat-item-pic">
                </div>
                <div class="chat-info">
                    <div class="chat-info-top">
                        <span class="chat-name" style="font-weight:${weight};">${escapeHTML(senderName)}</span>
                    </div>
                    <div class="chat-info-bottom">
                        <span class="chat-last-msg" style="font-weight:${weight};">${escapeHTML(email.subject || '(no subject)')}</span>
                    </div>
                </div>
            `;
            div.onclick = () => openEmailDetailPanel(email.id);
            list.appendChild(div);
        });

        currentEmailPage = (paginator.current_page || currentEmailPage) + 1;
        currentEmailHasMore = (paginator.current_page || 1) < (paginator.last_page || 1);
    } catch (e) {
        console.error('Failed to load emails', e);
        if (reset) list.innerHTML = '<div class="loading-text">Failed to load emails</div>';
    } finally {
        currentEmailLoadingMore = false;
    }
}

// Extracts bare addresses from a "Display Name" <a@b.com>, ... string,
// mirroring the backend's reply-all parsing — real display names can
// legitimately contain commas, so a naive split-on-comma isn't safe.
function extractEmailAddresses(raw) {
    if (!raw) return new Set();
    const matches = raw.match(/[^\s<>",]+@[^\s<>",]+\.[^\s<>",]+/g) || [];
    return new Set(matches.map(a => a.toLowerCase()));
}

function attachmentIconGlyph(extension) {
    const map = {
        pdf: '📕', doc: '📘', docx: '📘', xls: '📗', xlsx: '📗', csv: '📗',
        ppt: '📙', pptx: '📙', zip: '🗜️', rar: '🗜️', '7z': '🗜️',
        mp3: '🎵', wav: '🎵', m4a: '🎵', mp4: '🎬', mov: '🎬', avi: '🎬', mkv: '🎬',
    };
    return map[extension] || '📄';
}

function isImageExtensionForAttachment(extension) {
    return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'].includes(extension);
}

function formatFileSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

async function openEmailDetailPanel(emailId) {
    const panel = document.getElementById('email-detail-panel');
    const content = document.getElementById('email-detail-content');
    const replyBtn = document.getElementById('btn-reply-email');
    const replyAllBtn = document.getElementById('btn-reply-all-email');
    content.innerHTML = '<div class="loading-text">Loading...</div>';
    replyBtn.style.display = 'none';
    replyAllBtn.style.display = 'none';

    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);

    try {
        // Also marks the email read server-side.
        const response = await fetch(`/api/emails/${emailId}`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        const email = data.email;

        const attachments = email.attachments || [];
        const attachmentsHtml = attachments.length === 0 ? '' : `
            <div style="display:flex; flex-wrap:wrap; gap:10px; margin: 16px 0;">
                ${attachments.map(a => {
                    const ext = (a.file_name.split('.').pop() || '').toLowerCase();
                    const thumb = isImageExtensionForAttachment(ext)
                        ? `<img src="${a.url}" class="email-attachment-thumb">`
                        : `<div class="email-attachment-icon">${attachmentIconGlyph(ext)}</div>`;
                    return `
                        <a href="${a.url}" target="_blank" rel="noopener" class="email-attachment-chip" style="text-decoration:none;">
                            ${thumb}
                            <div class="email-attachment-meta">
                                <span class="email-attachment-name">${escapeHTML(a.file_name)}</span>
                                <span class="email-attachment-size">${formatFileSize(a.size_bytes)}</span>
                            </div>
                        </a>
                    `;
                }).join('')}
            </div>
        `;

        content.innerHTML = `
            <h3 style="margin: 0 0 8px 0;">${escapeHTML(email.subject || '(no subject)')}</h3>
            <div style="font-size: 13px; color: var(--text-muted);">${escapeHTML(email.from_name || '')} ${email.from_address ? `&lt;${escapeHTML(email.from_address)}&gt;` : ''}</div>
            ${email.to_address ? `<div style="font-size: 13px; color: var(--text-muted);">To: ${escapeHTML(email.to_address)}</div>` : ''}
            ${email.cc_address ? `<div style="font-size: 13px; color: var(--text-muted);">Cc: ${escapeHTML(email.cc_address)}</div>` : ''}
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">${new Date(email.received_at).toLocaleString()}</div>
            ${attachmentsHtml}
            <div style="white-space: pre-wrap; color: var(--text-primary);">${escapeHTML(email.body_text || '(no content)')}</div>
        `;

        if (!email.is_outgoing) {
            const others = extractEmailAddresses(`${email.to_address || ''} ${email.cc_address || ''}`);
            others.delete((currentEmailAccount && currentEmailAccount.email_address || '').toLowerCase());
            others.delete((email.from_address || '').toLowerCase());
            const hasOtherRecipients = others.size > 0;
            const replySubject = (email.subject || '').toLowerCase().startsWith('re:') ? email.subject : `Re: ${email.subject || ''}`;

            replyBtn.style.display = 'flex';
            replyBtn.onclick = () => openComposeEmailPanel({
                replyToEmailId: email.id, to: email.from_address, subject: replySubject, hasOtherRecipients, replyAll: false,
            });

            if (hasOtherRecipients) {
                replyAllBtn.style.display = 'flex';
                replyAllBtn.onclick = () => openComposeEmailPanel({
                    replyToEmailId: email.id, to: email.from_address, subject: replySubject, hasOtherRecipients, replyAll: true,
                });
            }
        }

        loadEmailInboxPage(true);
        refreshEmailUnreadBadge();
    } catch (e) {
        console.error('Failed to load email', e);
        content.innerHTML = '<div class="loading-text">Failed to load email</div>';
    }
}

function renderComposeAttachments() {
    const list = document.getElementById('compose-email-attachments-list');
    list.innerHTML = '';
    composeAttachments.forEach((file, index) => {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const thumb = isImageExtensionForAttachment(ext)
            ? `<img src="${URL.createObjectURL(file)}" class="email-attachment-thumb">`
            : `<div class="email-attachment-icon">${attachmentIconGlyph(ext)}</div>`;
        const chip = document.createElement('div');
        chip.className = 'email-attachment-chip';
        chip.innerHTML = `
            ${thumb}
            <div class="email-attachment-meta">
                <span class="email-attachment-name">${escapeHTML(file.name)}</span>
                <span class="email-attachment-size">${formatFileSize(file.size)}</span>
            </div>
            <span class="email-attachment-remove">&times;</span>
        `;
        chip.querySelector('.email-attachment-remove').onclick = () => {
            composeAttachments.splice(index, 1);
            renderComposeAttachments();
        };
        list.appendChild(chip);
    });
}

function updateReplyModeButtons() {
    const singleBtn = document.getElementById('btn-reply-mode-single');
    const allBtn = document.getElementById('btn-reply-mode-all');
    singleBtn.style.background = currentReplyAllMode ? '' : 'var(--primary-accent)';
    singleBtn.style.color = currentReplyAllMode ? '' : '#fff';
    allBtn.style.background = currentReplyAllMode ? 'var(--primary-accent)' : '';
    allBtn.style.color = currentReplyAllMode ? '#fff' : '';
}

function openComposeEmailPanel({ replyToEmailId = null, to = '', subject = '', hasOtherRecipients = false, replyAll = false } = {}) {
    currentReplyToEmailId = replyToEmailId;
    currentReplyAllMode = replyAll;
    composeAttachments = [];
    renderComposeAttachments();

    document.getElementById('compose-email-title').textContent = replyToEmailId ? (replyAll ? 'Reply all' : 'Reply') : 'New email';
    document.getElementById('compose-email-from').textContent = currentEmailAccount ? `From: ${currentEmailAccount.email_address}` : '';
    const toInput = document.getElementById('compose-email-to');
    const subjectInput = document.getElementById('compose-email-subject');
    const ccInput = document.getElementById('compose-email-cc');
    toInput.value = to || '';
    subjectInput.value = subject || '';
    ccInput.value = '';
    ccInput.style.display = 'none';
    toInput.disabled = !!replyToEmailId;
    subjectInput.disabled = !!replyToEmailId;
    document.getElementById('compose-email-body').value = '';

    const replyModeRow = document.getElementById('compose-reply-mode-row');
    if (replyToEmailId && hasOtherRecipients) {
        replyModeRow.style.display = 'flex';
        updateReplyModeButtons();
    } else {
        replyModeRow.style.display = 'none';
    }

    const panel = document.getElementById('compose-email-panel');
    panel.style.display = 'flex';
    setTimeout(() => panel.style.transform = 'translateX(0)', 10);
}

async function submitComposeEmail() {
    const body = document.getElementById('compose-email-body').value.trim();
    const to = document.getElementById('compose-email-to').value.trim();
    const cc = document.getElementById('compose-email-cc').value.trim();
    const subject = document.getElementById('compose-email-subject').value.trim();

    if (!body) return;
    if (!currentReplyToEmailId && (!to || !subject)) return;
    if (!currentReplyToEmailId && !currentEmailAccount) return;

    const url = currentReplyToEmailId
        ? `/api/emails/${currentReplyToEmailId}/reply`
        : `/api/email-accounts/${currentEmailAccount.id}/send`;

    const formData = new FormData();
    formData.append('body', body);
    if (currentReplyToEmailId) {
        formData.append('reply_all', currentReplyAllMode ? '1' : '0');
    } else {
        formData.append('to', to);
        formData.append('subject', subject);
    }
    if (cc) formData.append('cc', cc);
    composeAttachments.forEach(file => formData.append('attachments[]', file));

    const submitBtn = document.getElementById('btn-submit-compose-email');
    const progressWrap = document.getElementById('compose-email-progress');
    const progressBar = document.getElementById('compose-email-progress-bar');
    submitBtn.style.pointerEvents = 'none';
    progressBar.style.width = '0%';
    progressWrap.style.display = composeAttachments.length > 0 ? 'block' : 'none';

    try {
        // XMLHttpRequest (not fetch) specifically for upload-progress events,
        // since attachments can be large enough that a stalled 0%-to-100%
        // jump would look broken.
        await new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.setRequestHeader('Authorization', `Bearer ${window.API_TOKEN}`);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    progressBar.style.width = `${Math.round((e.loaded / e.total) * 100)}%`;
                }
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve();
                    return;
                }
                let message = 'Failed to send email';
                try { message = JSON.parse(xhr.responseText).error || message; } catch (parseError) { /* ignore */ }
                reject(new Error(message));
            };
            xhr.onerror = () => reject(new Error('Failed to send email'));
            xhr.send(formData);
        });

        document.getElementById('compose-email-panel').style.transform = 'translateX(-100%)';
        setTimeout(() => document.getElementById('compose-email-panel').style.display = 'none', 300);
    } catch (e) {
        console.error('Failed to send email', e);
        alert(e.message || 'Failed to send email');
    } finally {
        submitBtn.style.pointerEvents = '';
        progressWrap.style.display = 'none';
    }
}

/* ========================================================
   MESSAGE CONTEXT MENU LOGIC
======================================================== */
let currentContextMsgId = null;

document.addEventListener('click', (e) => {
    const btn = e.target.closest('.msg-actions-btn');
    const menu = document.getElementById('message-context-menu');
    
    if (btn) {
        currentContextMsgId = btn.dataset.msgId;
        const isSent = btn.dataset.isSent === 'true';
        const rect = btn.getBoundingClientRect();
        
        // Hide "Delete for everyone" if we didn't send this message
        const deleteEveryoneBtn = document.getElementById('btn-delete-msg-everyone');
        if (deleteEveryoneBtn) {
            deleteEveryoneBtn.style.display = isSent ? 'flex' : 'none';
        }
        
        // Clamp to the viewport — this menu grew taller (3 new "Share via"
        // items) and its rect.left-100 offset can push it partly off-screen
        // on narrow mobile widths otherwise.
        menu.style.display = 'block';
        const menuWidth = menu.offsetWidth;
        const menuHeight = menu.offsetHeight;
        let top = rect.bottom + window.scrollY;
        let left = rect.left + window.scrollX - 100;
        if (left + menuWidth > window.scrollX + window.innerWidth) {
            left = window.scrollX + window.innerWidth - menuWidth - 8;
        }
        if (left < window.scrollX) left = window.scrollX + 8;
        if (top + menuHeight > window.scrollY + window.innerHeight) {
            top = rect.top + window.scrollY - menuHeight;
        }
        menu.style.top = `${top}px`;
        menu.style.left = `${left}px`;
    } else if (menu && !menu.contains(e.target)) {
        menu.style.display = 'none';
        currentContextMsgId = null;
    }
});

document.getElementById('btn-delete-msg-me').addEventListener('click', () => deleteMessageApi('me'));
document.getElementById('btn-delete-msg-everyone').addEventListener('click', () => deleteMessageApi('everyone'));

document.getElementById('btn-reply-msg').addEventListener('click', () => {
    document.getElementById('message-context-menu').style.display = 'none';
    const msg = messagesById[currentContextMsgId];
    if (msg) showReplyPreview(msg);
});

async function reactToMessage(messageId, emoji) {
    try {
        const response = await fetch(`/api/messages/${messageId}/react`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ emoji })
        });
        const data = await response.json();
        if (data.reactions) renderReactionsForMessage(messageId, data.reactions);
    } catch (e) {
        console.error('React failed', e);
    }
}

document.querySelectorAll('.quick-react-emoji').forEach(el => {
    el.addEventListener('click', () => {
        document.getElementById('message-context-menu').style.display = 'none';
        if (currentContextMsgId) reactToMessage(currentContextMsgId, el.dataset.emoji);
    });
});

// "More" reactions — a dedicated <emoji-picker> so it never inserts into
// the message composer's text input (that's #emoji-picker's job).
const reactionEmojiPicker = document.getElementById('reaction-emoji-picker');
document.getElementById('btn-react-more').addEventListener('click', (e) => {
    document.getElementById('message-context-menu').style.display = 'none';
    const rect = e.target.getBoundingClientRect();
    reactionEmojiPicker.style.top = `${rect.bottom + window.scrollY}px`;
    reactionEmojiPicker.style.left = `${rect.left + window.scrollX - 100}px`;
    reactionEmojiPicker.style.display = 'block';
});
reactionEmojiPicker.addEventListener('emoji-click', event => {
    reactionEmojiPicker.style.display = 'none';
    if (currentContextMsgId) reactToMessage(currentContextMsgId, event.detail.unicode);
});
document.addEventListener('click', (e) => {
    if (reactionEmojiPicker.style.display !== 'none' && !reactionEmojiPicker.contains(e.target) && !e.target.closest('#btn-react-more')) {
        reactionEmojiPicker.style.display = 'none';
    }
});

// One-way "share via" hand-off to WhatsApp/email — not an inbox integration
// (that needs real WhatsApp Business API / Gmail OAuth credentials this app
// doesn't have), just opens the target pre-filled with the message text,
// the same way the mobile app's share sheet works.
function currentContextMsgText() {
    if (!currentContextMsgId) return '';
    const row = document.getElementById(`msg-${currentContextMsgId}`);
    return row?.querySelector('.message-content')?.textContent?.trim() || '';
}

document.getElementById('btn-share-msg-whatsapp').addEventListener('click', () => {
    const text = currentContextMsgText();
    document.getElementById('message-context-menu').style.display = 'none';
    if (!text) return;
    window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
});

document.getElementById('btn-share-msg-email').addEventListener('click', () => {
    const text = currentContextMsgText();
    document.getElementById('message-context-menu').style.display = 'none';
    if (!text) return;
    window.location.href = `mailto:?body=${encodeURIComponent(text)}`;
});

document.getElementById('btn-share-msg-more').addEventListener('click', async () => {
    const text = currentContextMsgText();
    document.getElementById('message-context-menu').style.display = 'none';
    if (!text) return;
    if (navigator.share) {
        try {
            await navigator.share({ text });
        } catch (e) {
            // AbortError when the user cancels the native share sheet — not an error.
        }
    } else if (navigator.clipboard) {
        await navigator.clipboard.writeText(text);
        alert('Copied to clipboard — paste it into the app you want to share to.');
    }
});

async function deleteMessageApi(type) {
    if (!currentContextMsgId) return;
    const msgId = currentContextMsgId;
    document.getElementById('message-context-menu').style.display = 'none';
    
    // Remove instantly for snappy UI
    const row = document.getElementById(`msg-${msgId}`);
    if (row) row.remove();
    
    // Check if it was in a gallery grid
    const mediaEl = document.getElementById(`msg-${msgId}`);
    if (mediaEl && mediaEl.classList.contains('gallery-item')) {
        const grid = mediaEl.closest('.media-gallery-grid');
        mediaEl.remove();
        if (grid && grid.children.length === 0) {
            grid.closest('.message-row')?.remove(); // if bubble is empty, remove it entirely
        }
    }
    
    try {
        await fetch(`/api/messages/${msgId}?type=${type}`, {
            method: 'DELETE',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });
    } catch (e) {
        console.error("Failed to delete message", e);
    }
}

/* ========================================================
   FULL SCREEN GALLERY SLIDER LOGIC
======================================================== */
let galleryImages = [];
let currentGalleryIndex = 0;

document.addEventListener('click', (e) => {
    const clickedItem = e.target.closest('.gallery-item');
    if (clickedItem && clickedItem.tagName === 'IMG') {
        openGallery(clickedItem.src);
    }
});

function openGallery(initialSrc) {
    // Collect all image sources currently loaded in the chat
    galleryImages = [];
    document.querySelectorAll('.chat-messages img.gallery-item').forEach(img => {
        galleryImages.push(img.src);
    });
    
    if (galleryImages.length === 0) return;
    
    currentGalleryIndex = galleryImages.indexOf(initialSrc);
    if (currentGalleryIndex === -1) currentGalleryIndex = 0;
    
    updateGalleryView();
    document.getElementById('image-gallery-slider').style.display = 'flex';
}

function updateGalleryView() {
    const imgEl = document.getElementById('gallery-main-img');
    const counterEl = document.getElementById('gallery-counter');
    
    imgEl.src = galleryImages[currentGalleryIndex];
    counterEl.innerText = `${currentGalleryIndex + 1} of ${galleryImages.length}`;
}

document.getElementById('btn-close-gallery').addEventListener('click', () => {
    document.getElementById('image-gallery-slider').style.display = 'none';
    galleryImages = [];
});

document.getElementById('btn-gallery-prev').addEventListener('click', () => {
    if (currentGalleryIndex > 0) {
        currentGalleryIndex--;
        updateGalleryView();
    }
});

document.getElementById('btn-gallery-next').addEventListener('click', () => {
    if (currentGalleryIndex < galleryImages.length - 1) {
        currentGalleryIndex++;
        updateGalleryView();
    }
});

document.addEventListener('keydown', (e) => {
    const slider = document.getElementById('image-gallery-slider');
    if (slider && slider.style.display === 'flex') {
        if (e.key === 'ArrowLeft' && currentGalleryIndex > 0) {
            currentGalleryIndex--;
            updateGalleryView();
        } else if (e.key === 'ArrowRight' && currentGalleryIndex < galleryImages.length - 1) {
            currentGalleryIndex++;
            updateGalleryView();
        } else if (e.key === 'Escape') {
            document.getElementById('image-gallery-slider').style.display = 'none';
            galleryImages = [];
        }
    }
});

// Participant Management
window.toggleParticipantMenu = function(event, userId) {
    event.stopPropagation();
    document.querySelectorAll('.participant-menu').forEach(m => m.style.display = 'none');
    const menu = document.getElementById(`participant-menu-${userId}`);
    if (menu) {
        menu.style.display = 'flex';
    }
};

document.addEventListener('click', () => {
    document.querySelectorAll('.participant-menu').forEach(m => m.style.display = 'none');
});

window.updateParticipantRole = async function(userId, makeAdmin) {
    if (!currentChatId || currentChatType !== 'group') return;
    try {
        const response = await fetch(`/api/chats/${currentChatId}/participants/${userId}/role`, {
            method: 'PUT',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ is_admin: makeAdmin })
        });
        if (response.ok) {
            const data = await response.json();
            loadedChatData = data.chat;
            openContactInfo(); // refresh the view
        } else {
            const err = await response.json();
            alert(err.message || 'Failed to update role');
        }
    } catch (e) {
        console.error("Failed to update participant role", e);
    }
};

window.kickParticipant = async function(userId) {
    if (!currentChatId || currentChatType !== 'group') return;
    if (!confirm('Are you sure you want to remove this user from the group?')) return;
    try {
        const response = await fetch(`/api/chats/${currentChatId}/participants/${userId}`, {
            method: 'DELETE',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Accept': 'application/json'
            }
        });
        if (response.ok) {
            const data = await response.json();
            loadedChatData = data.chat;
            openContactInfo(); // refresh the view
        } else {
            const err = await response.json();
            alert(err.message || 'Failed to remove user');
        }
    } catch (e) {
        console.error("Failed to kick user", e);
    }
};

// Add Participant Modal Logic
let addParticipantSelectedUsers = [];

const btnAddParticipant = document.getElementById('btn-add-participant');
if (btnAddParticipant) {
    btnAddParticipant.addEventListener('click', () => {
        addParticipantSelectedUsers = [];
        document.getElementById('add-participant-search').value = '';
        document.getElementById('add-participant-list').innerHTML = '';
        document.getElementById('add-participant-modal').style.display = 'flex';
        fetchAddParticipantUsers('');
    });
}

const btnCloseAddParticipant = document.getElementById('btn-close-add-participant');
if (btnCloseAddParticipant) {
    btnCloseAddParticipant.addEventListener('click', () => {
        document.getElementById('add-participant-modal').style.display = 'none';
    });
}

document.getElementById('add-participant-search')?.addEventListener('input', (e) => {
    fetchAddParticipantUsers(e.target.value);
});

async function fetchAddParticipantUsers(query = '') {
    const listEl = document.getElementById('add-participant-list');
    listEl.innerHTML = '<div class="loading-text">Loading...</div>';

    try {
        const q = encodeURIComponent(query);
        const response = await fetch(`/api/users/search?q=${q}`, {
            headers: { 'Authorization': `Bearer ${window.API_TOKEN}`, 'Accept': 'application/json' }
        });
        const data = await response.json();
        listEl.innerHTML = '';
        
        // Filter out users already in the group
        const existingIds = loadedChatData?.participants?.map(p => p.user_id) || [];
        const availableUsers = data.users.filter(u => u.id !== window.APP_USER.id && !existingIds.includes(u.id));

        if (availableUsers.length === 0) {
            listEl.innerHTML = '<div class="loading-text text-muted" style="text-align:center; padding: 20px;">No new users found</div>';
            return;
        }

        availableUsers.forEach(user => {
            const item = document.createElement('div');
            item.className = 'chat-item';
            item.style.cursor = 'pointer';
            
            item.onclick = () => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                checkbox.checked = !checkbox.checked;
                if (checkbox.checked) {
                    addParticipantSelectedUsers.push(user.id);
                } else {
                    addParticipantSelectedUsers = addParticipantSelectedUsers.filter(id => id !== user.id);
                }
            };

            const isChecked = addParticipantSelectedUsers.includes(user.id) ? 'checked' : '';
            
            item.innerHTML = `
                <div class="chat-item-pic-wrapper">
                    <img src="${user.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(getUserDisplayName(user))}&background=FF5722&color=fff`}" class="chat-item-pic">
                </div>
                <div class="chat-info" style="border:none;">
                    <div class="chat-info-top">
                        <span class="chat-name">${escapeHTML(getUserDisplayName(user))}</span>
                    </div>
                </div>
                <div>
                    <input type="checkbox" class="custom-checkbox" ${isChecked} style="pointer-events: none;">
                </div>
            `;
            listEl.appendChild(item);
        });
    } catch(e) {
        listEl.innerHTML = '<div class="loading-text text-danger">Failed to load contacts.</div>';
    }
}

document.getElementById('btn-submit-add-participants')?.addEventListener('click', async () => {
    if (addParticipantSelectedUsers.length === 0) {
        alert('Please select at least one user to add.');
        return;
    }
    
    if (!currentChatId || currentChatType !== 'group') return;
    
    const btn = document.getElementById('btn-submit-add-participants');
    const originalText = btn.innerText;
    btn.innerText = 'Adding...';
    btn.disabled = true;
    
    try {
        const response = await fetch(`/api/chats/${currentChatId}/participants`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.API_TOKEN}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ user_ids: addParticipantSelectedUsers })
        });
        
        if (response.ok) {
            const data = await response.json();
            loadedChatData = data.chat;
            // Reseal this chat's already-shared key to each newly-added
            // member's devices — a no-op if the chat isn't encrypted yet.
            for (const userId of addParticipantSelectedUsers) {
                await distributeKeyToNewMember(currentChatId, userId);
            }
            document.getElementById('add-participant-modal').style.display = 'none';
            openContactInfo(); // refresh the view
        } else {
            const err = await response.json();
            alert(err.message || 'Failed to add participants');
        }
    } catch (e) {
        console.error("Failed to add participants", e);
        alert('An error occurred while adding participants.');
    } finally {
        btn.innerText = originalText;
        btn.disabled = false;
    }
});
