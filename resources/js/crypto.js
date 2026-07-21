// E2EE primitives — must match lib/core/crypto/e2ee_primitives.dart
// byte-for-byte, since the two implementations have to interoperate
// (Flutter <-> web messages in the same encrypted chat). Built from
// standardized primitives (X25519 / RFC 7748, BLAKE2b, ChaCha20-Poly1305
// IETF / RFC 7539) rather than libsodium's own crypto_box_seal helper, for
// exactly that reason — an official RFC is implemented identically by both
// libsodium.js and the Dart `cryptography` package, whereas replicating an
// undocumented internal convention across two different libraries would be
// far riskier to get byte-exact.
//
// IMPORTANT CAVEAT: this file could not be executed or tested in this dev
// environment (no Node.js/npm available to run or bundle it) — every line
// was written from the documented libsodium.js API, but it has NOT been
// exercised end-to-end here the way the Dart side was (verified with a real
// round-trip script, see the project plan/commit history). Before relying on
// this in production, manually verify a message encrypted in the Flutter
// app decrypts correctly on web, and vice versa.
//
// Imported as a real ES module via jsdelivr's `+esm` endpoint (not a classic
// <script src="..."> tag setting a `window.sodium` global) — a first attempt
// using a classic script tag left `window.sodium` undefined in practice
// (likely the UMD bundle's environment-detection picking the wrong branch
// inside Vite's module context, or a load-order issue). A real `import`
// sidesteps that class of problem entirely: Vite/the browser's module loader
// resolves it like any other dependency, regardless of script tag order.
import sodium from 'https://cdn.jsdelivr.net/npm/libsodium-wrappers@0.7.15/+esm';

async function sodiumReady() {
    await sodium.ready;
    return sodium;
}

function toBase64(bytes) {
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
}

function fromBase64(b64) {
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
}

function concatBytes(...arrays) {
    const total = arrays.reduce((sum, a) => sum + a.length, 0);
    const out = new Uint8Array(total);
    let offset = 0;
    for (const a of arrays) {
        out.set(a, offset);
        offset += a.length;
    }
    return out;
}

/** { privateKey: Uint8Array(32), publicKey: Uint8Array(32) } — raw X25519, not crypto_kx (which bakes in extra key derivation and would NOT interop with plain X25519 on the Dart side). */
async function generateKeyPair() {
    const sodium = await sodiumReady();
    const privateKey = sodium.randombytes_buf(32);
    const publicKey = sodium.crypto_scalarmult_base(privateKey);
    return { privateKey, publicKey };
}

async function publicKeyFromPrivateKeyBase64(privateKeyBase64) {
    const sodium = await sodiumReady();
    return sodium.crypto_scalarmult_base(fromBase64(privateKeyBase64));
}

/** Fresh random 32-byte chat key. */
async function generateChatKey() {
    const sodium = await sodiumReady();
    return sodium.randombytes_buf(32);
}

async function deriveSymmetricKey(sharedSecret, ephemeralPublicKey, recipientPublicKey) {
    const sodium = await sodiumReady();
    const input = concatBytes(sharedSecret, ephemeralPublicKey, recipientPublicKey);
    return sodium.crypto_generichash(32, input);
}

/**
 * Seals chatKeyBytes so only the holder of recipientPublicKeyBase64's
 * matching private key can recover it. Must match
 * E2eePrimitives.sealToPublicKey exactly:
 *   1. ephemeral X25519 keypair
 *   2. sharedSecret = X25519(ephemeralPrivate, recipientPublic)
 *   3. key = BLAKE2b-256(sharedSecret || ephemeralPublic || recipientPublic)
 *   4. ciphertext = ChaCha20-Poly1305-IETF(chatKeyBytes, key, nonce = 12 zero bytes)
 *   5. output = base64(ephemeralPublic (32B) || ciphertext+mac)
 */
async function sealToPublicKey(chatKeyBytes, recipientPublicKeyBase64) {
    const sodium = await sodiumReady();
    const recipientPublicKey = fromBase64(recipientPublicKeyBase64);

    const ephemeralPrivateKey = sodium.randombytes_buf(32);
    const ephemeralPublicKey = sodium.crypto_scalarmult_base(ephemeralPrivateKey);

    const sharedSecret = sodium.crypto_scalarmult(ephemeralPrivateKey, recipientPublicKey);
    const symmetricKey = await deriveSymmetricKey(sharedSecret, ephemeralPublicKey, recipientPublicKey);

    const zeroNonce = new Uint8Array(12);
    // crypto_aead_chacha20poly1305_ietf_encrypt(message, additional_data, secret_nonce, public_nonce, key)
    // — parameter order mirrors libsodium's C API (see comment at top of file).
    const cipherTextAndMac = sodium.crypto_aead_chacha20poly1305_ietf_encrypt(
        chatKeyBytes, null, null, zeroNonce, symmetricKey
    );

    return toBase64(concatBytes(ephemeralPublicKey, cipherTextAndMac));
}

/** Reverses sealToPublicKey using this device's own X25519 keypair. */
async function unseal(sealedBase64, myPrivateKeyBase64) {
    const sodium = await sodiumReady();
    const all = fromBase64(sealedBase64);
    const ephemeralPublicKey = all.slice(0, 32);
    const cipherTextAndMac = all.slice(32);

    const myPrivateKey = fromBase64(myPrivateKeyBase64);
    const myPublicKey = sodium.crypto_scalarmult_base(myPrivateKey);

    const sharedSecret = sodium.crypto_scalarmult(myPrivateKey, ephemeralPublicKey);
    const symmetricKey = await deriveSymmetricKey(sharedSecret, ephemeralPublicKey, myPublicKey);

    const zeroNonce = new Uint8Array(12);
    // crypto_aead_chacha20poly1305_ietf_decrypt(secret_nonce, ciphertext, additional_data, public_nonce, key)
    // — note secret_nonce comes FIRST here, unlike encrypt (mirrors the C API).
    return sodium.crypto_aead_chacha20poly1305_ietf_decrypt(
        null, cipherTextAndMac, null, zeroNonce, symmetricKey
    );
}

/**
 * Encrypts message content with a chat's already-shared symmetric key.
 * Output: base64(nonce (12B) || ciphertext+mac). Fresh random nonce every
 * call — required since the same chatKey is reused across every message.
 */
async function encryptMessage(plainText, chatKeyBytes) {
    const sodium = await sodiumReady();
    const nonce = sodium.randombytes_buf(12);
    const message = sodium.from_string(plainText);
    const cipherTextAndMac = sodium.crypto_aead_chacha20poly1305_ietf_encrypt(
        message, null, null, nonce, chatKeyBytes
    );
    return toBase64(concatBytes(nonce, cipherTextAndMac));
}

async function decryptMessage(cipherTextBase64, chatKeyBytes) {
    const sodium = await sodiumReady();
    const all = fromBase64(cipherTextBase64);
    const nonce = all.slice(0, 12);
    const cipherTextAndMac = all.slice(12);
    const clear = sodium.crypto_aead_chacha20poly1305_ietf_decrypt(
        null, cipherTextAndMac, null, nonce, chatKeyBytes
    );
    return sodium.to_string(clear);
}

export {
    generateKeyPair,
    publicKeyFromPrivateKeyBase64,
    generateChatKey,
    sealToPublicKey,
    unseal,
    encryptMessage,
    decryptMessage,
    toBase64,
    fromBase64,
};
