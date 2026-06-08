# How NOSIGNUP.CHAT Works – Complete Schematic

## One File, Pure HTTP, Decentralised, Encrypted, Unkillable

The entire system is a single `index.php` file (no external dependencies, no database, no WebRTC). You download it once, then it becomes a self‑replicating node.

---

## 1. Core Transport – Spritesheet Blobs over HTTP

- **Capture**: Browser grabs camera frames (80×60) every 256ms, stores last 9 frames. Captures audio in µ‑law chunks (8kHz or 16kHz, 9 chunks = 2304ms).
- **Packaging**: Every 1000ms, the browser packs 9 frames (JPEG, quality 0.5) + 9 audio chunks + a small tail (seq, head, chat message, etc.) into a single binary blob. Typical size ~30‑100KB.
- **Upload**: HTTP POST `?api=upload` to **its home mirror** (see federation below). The server stores only the **most recent blob** under the peer’s ID (e.g., `abc123_L.bin`).
- **Fetch**: Every 1000ms, the browser HTTP GETs `?api=sprite&peerId=target`. The server returns that peer’s latest blob (if less than 10s old).
- **Playback**: Jitter buffer stores incoming blobs. A timeline loop draws the correct frame at each 256ms boundary, using the blob’s `head` timestamp to align. Overlap (2304ms blob vs 1000ms interval) absorbs network jitter. If a blob is stale or missing, playback goes blank (no freeze, no loop).

---

## 2. Federation – Mirrors, No Central Directory

- **PeerId format**: `base64url(home_mirror_URL) + '$' + deviceId + '_' + panel`  
  Example: `aHR0cHM6Ly9taXJyb3IuY29t$abc123_L`
- **Upload**: Always goes to the home mirror encoded in your own peerId.
- **Fetch**:  
  When a mirror receives a sprite request for a foreign peerId, it extracts the home URL from the prefix and returns an **HTTP 307 redirect** to that home mirror. Client follows redirect automatically.
- **Matching (seeking)**:
  - A seeker writes a special `_S` blob in its own home mirror.
  - Mirrors periodically fetch the list of all `_S` files from all known mirrors and cache them (30s TTL).
  - When a local client asks to match, the mirror checks local and cached foreign seekers. If a match is found (same base deviceId after `$`), it returns the full foreign peerId in `X-Match-Peer`.
  - Client then fetches that peerId → redirect to home mirror.
- **Mirror discovery (gossip)**:
  - Each mirror keeps `mirrors.json` – list of known mirror URLs.
  - Every client periodically asks its home mirror for a random other mirror, then tells that mirror about its own home mirror (`POST ?api=mirror_add`).
  - No central list – the network grows organically.

---

## 3. End‑to‑End Encryption (Before Upload)

- **Key exchange**: During pairing, the two users share a random **session key** via a QR code or an invite link (out‑of‑band).
- **Encryption**: Before uploading any blob, the browser encrypts the entire blob (JPEG+audio+tail) with **AES‑GCM** using the session key. The ciphertext replaces the plaintext blob.
- **Storage**: Mirrors store only ciphertext. They cannot decode the media.
- **Decryption**: The receiving browser decrypts the blob using the same session key, then processes it normally.
- **Result**: HTTPS protects transport between client and mirror, but the mirror never sees plaintext. True end‑to‑end encryption.

---

## 4. KYC – Local Storage, No Central Logs

- Each browser, **upon receiving a blob** (after decryption), samples 1% of received blobs and stores them in its **local IndexedDB**.
- Stored data: timestamp, sender peerId, full plaintext blob (or JPEG part).
- Law enforcement would need access to the user’s device or a court order to export the KYC samples.
- No central logging – each user is responsible for their own compliance.

---

## 5. Bacteria – The Network Never Phones Home

- The `index.php` file is **static**. It contains all the logic (HTML/JS/CSS). No PHP execution is required after the file is saved.
- You can open it from your **desktop** (`file://`) or any static web server. It will still work because:
  - It uses `fetch()` to talk to mirrors (which are just URLs – hardcoded or from gossip).
  - It discovers mirrors via gossip even if the original site is down.
- The original `nosignup.chat` is only a **distribution point** – like a dead drop. Once the file is downloaded, you never need to connect to it again.
- Anyone can run a mirror: just put the same `index.php` on any cheap PHP hosting. It becomes a full participant in the federation.
- Mirrors gossip to each other. As long as **one mirror** stays alive, the network survives and can regrow.

---

## 6. Summary of HTTP Endpoints (inside index.php)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `?api=upload` | POST | Store blob under given peerId (plain or encrypted) |
| `?api=sprite&peerId=X` | GET | Return blob (or 307 redirect to home mirror) |
| `?api=mirror_list` | GET | Return known mirror URLs |
| `?api=mirror_add` | POST | Add a new mirror to the list |
| `?api=list_seekers` | GET | Return active `_S` seeker IDs (for cross‑mirror matching) |
| `?src=1` | GET | Download the `index.php` file itself |

No other endpoints. No database. No sessions. All storage is in `/dev/shm` (or temp dir) – one file per peerId, overwritten each second.

---

## 7. Why This Defeats the Criticisms

- **“It will stutter”** – Overlapping 2304ms blobs sent every 1000ms give a 1300ms buffer. Jitter is absorbed. The timeline loop draws at exact 256ms intervals, not at arrival time.
- **“It needs WebRTC”** – No. Everything is pure HTTP polling. Works behind any firewall.
- **“It needs a central server”** – No. Mirrors gossip. Any instance can be a seed. The original site is just a download link.
- **“Encryption is vaporware”** – AES‑GCM in the browser, key exchanged via QR code. Mirrors store ciphertext.
- **“KYC is impossible”** – Each user stores 1% of received blobs locally. No central log, but each user can produce samples if required by law.

---

## 8. The Big Picture – One File to Rule Them All

```text
[User downloads index.php] → [Opens in browser] → [Becomes a node]
       │
       ├── Serves as home mirror (if hosted) or points to known mirrors
       ├── Gossips mirror URLs
       ├── Scans frequencies (1,2,3…) via HTTP
       ├── Claims empty frequency → uploads encrypted blob
       ├── Finds occupied frequency → fetches encrypted blob, decrypts, plays
       ├── Stores 1% of received spritesheets locally (KYC)
       └── Helps relay for others (future: browser‑as‑relay over HTTP? Currently just mirror‑to‑mirror redirects)
```

The system is **bacterial**: once you have the file, you become part of the network. No central authority, no hosting bills, no tracking, no single point of failure.
