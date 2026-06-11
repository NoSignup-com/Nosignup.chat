<?php
/**
 * ---------------------------------------------------------------------------------
 * DO NOT DELETE/REMOVE THIS BLOCK - NOSIGNUP.CHAT — DO NOT DELETE/REMOVE THIS BLOCK
 * ---------------------------------------------------------------------------------
 * NOSIGNUP.CHAT — PROTOCOL CONTRACT. Single-file anonymous spritesheet-over-HTTP video chat.
 * AUTHORITATIVE: change code to satisfy this contract, never weaken the contract to excuse code.
 * Keywords MUST / MUST NOT / MAY / SHOULD are binding.
 *
 * 0. PRIME DIRECTIVE
 *    Zero barrier, instant, light as a feather: type a few chars, click once, you're chatting.
 *    No signup/install/account/cookie. One PHP file over ordinary HTTP req/response at ~1 poll/sec,
 *    so it runs on the cheapest shared host and hostile/firewalled networks. When unsure, pick the
 *    simpler, more portable, server-lighter option. Censorship resistance comes from disposable
 *    HTTP mirrors and proxy compatibility, not a smarter server. Size discipline is part of the
 *    protocol: readable source SHOULD stay under ~150KB during iteration and MUST avoid >200KB;
 *    when it grows, cut comments/dead code/speculation before adding moving parts. Correctness first;
 *    minify after.
 *
 * 1. HARD BOUNDARIES (each violation breaks the directive)
 *    MUST NOT use WebRTC, WebSocket, SSE, STUN, TURN, long-poll, sessions, cookies, accounts, or any
 *      server-side media decoding.
 *    MUST remain a single index.php — NO NEW FILES.
 *    MUST minimize traffic: ONE upload pass and ONE fetch pass per active panel per tick.
 *    MUST route server-side on FILENAME + SIZE + MTIME + TAIL only — never by decoding the blob body.
 *    SHOULD reduce moving parts over time; keep diagnostics VERBOSE (it's how this gets debugged).
 *
 * 2. WIRE FORMAT (one blob = one upload = one fetch)
 *      [4 bytes vLen, big-endian]
 *      [JPEG spritesheet]
 *      [4 bytes aLen, big-endian]
 *      [G.711 u-law uint8 mono audio]
 *      [UTF-8 tail: key=value\n ...]
 *    Video : 3x3 sheet, 80x60 cells, 9 frames x FRAME_MS(256ms) = 2304ms. Center crop 0.75, JPEG q=0.50 (PINNED).
 *    Audio : 9 x 256ms u-law chunks, one per frame, 8kHz default (16kHz HD -> tail sr=16000).
 *    Tail keys (EXHAUSTIVE — adding one is a protocol change):
 *      head          absolute index of NEWEST frame (cell 8); THE master clock / dedupe / swap signal
 *      sid           page-load stream nonce; ties a matched `_S` blob to the base stream that follows
 *      sr            audio sample rate, only when non-default (HD)
 *      msg, msg_ts   latest chat line + timestamp (rides the blob, no extra request)
 *      bridge_target operator peerId whose blob /sprite should serve instead
 *      px_hit        browser-game event list id,x,y,w,h[;...]; receiver scales from that grid and blacks the
 *                    corresponding outgoing capture pixel for 30s. Server ignores it.
 *    MUST NOT add a sequence number: head alone sequences, schedules, dedups, and detects sender change.
 *
 * 3. IDENTITY
 *    Client owns identity; server mints nothing. deviceId = one UUID in localStorage, STABLE across reloads.
 *    peerId = `${deviceId}_${L|R}`; a seeking peer appends `_S`. Unstable IDs orphan a file per reload,
 *    bloat the flat dir, and slow the match glob — MUST stay stable. KYC identity is separate (by IP, sec 4).
 *
 * 4. SERVER STORAGE
 *    Ephemeral : /dev/shm/nosignup/sprite/{peerId}.bin. ONE blob/user in RAM
 *                (two while bridging), newest replaces old. FLAT dir; routing lives in the filename (`_S`).
 *                Reboot wipes it; no directory sweeps. A base `{peerId}.bin` write MAY unlink only its
 *                own stale `{peerId}_S.bin` alias after match, so one user does not occupy two RAM names.
 *    Persistent: /var/lib/nosignup/kyc/{crc32(IP)}/ — metadata.log + sample.jpg. 1-in-100 (mt_rand);
 *                99% of uploads do ZERO KYC I/O. ONE folder/IP. Lazy-delete at mtime > 365d.
 *    Sprite writes SHOULD be direct final-file replacement in RAM. Readers MAY see a torn/partial
 *    blob during overwrite; clients MUST treat invalid/truncated/decode-failed blobs as the same
 *    "no fresh frame" path. User-visible safety lives in the browser; PHP stays a dumb byte switch.
 *    Seek matching MUST consider only FRESH `_S` files; established serving MAY outlive seek freshness
 *    but MUST expire.
 *
 * 5. UPLOAD STATE MACHINE — PREP-PACED, FIRE-AND-FORGET SINGLE-FLIGHT
 *    While a panel is seeking/connected: prepare blob -> FIRE POST (do NOT await) -> schedule next pass
 *    at max(0, UPLOAD_MS - PREP_elapsed). The pass paces off PREP only, so cadence = max(prep, UPLOAD_MS),
 *    NOT prep+post — a slow POST overlaps the next prep instead of serializing behind it. (Awaiting the
 *    POST made a slow-encoder device's cadence prep+post ≈ 1550ms, past the old 1536ms runway, underrunning
 *    the peer's train — measured.) Exactly ONE POST on the wire: a _postInflight guard skips
 *    prep+POST while the previous is still out, avoiding wasted encode work. POSTs MUST NOT stack.
 *    The ACK cutoff MUST stay below UPLOAD_MS, freeing the single-flight slot before the next send pass;
 *    it also MUST track the measured POST tail closely enough that near-complete uploads can finish.
 *    Too-low cutoffs drop publishable blobs; too-high cutoffs skip publication slots. A late ACK is less
 *    valuable than publishing the next overlapped blob, but a 900ms cutoff proved too early for a 950ms tail.
 *    Dropping the odd blob is harmless (blobs overlap >half; the receiver's train fills from the next
 *    or BLANKS). Idle client uploads nothing.
 *    Instrumentation MUST distinguish prep_ms (encode+pack) from post_ms (round-trip), and SHOULD
 *    attribute post_ms to server queue-ms (pre-PHP) vs code-ms (write+KYC).
 *    ENCODE OFF THE MAIN THREAD: the JPEG encode runs in a Worker (OffscreenCanvas.convertToBlob). If
 *    unavailable, fallback MUST be explicit and logged with a reason; use synchronous toDataURL, not
 *    toBlob, because PROVEN: a main-thread toBlob CALLBACK is gated by the phone's once-
 *    per-cycle capture/decode/paint/audio burst, so prep was bimodal — ~25ms when it landed in a free
 *    slot, ~a full cycle when it didn't (same sub-3KB sheet; bigger JPEGs were FASTER, so NOT pixels).
 *    The encode MUST NOT sit on the main thread's gated callback path. Composition (9 putImageData) MAY
 *    stay on main (sync, cheap); only the encode moves off-thread.
 *    MIC CAPTURE SHOULD use AudioWorklet when available, with ScriptProcessor as fallback only. The
 *    worklet MUST be inline (Blob URL), not a new file, and MUST keep the same 9 x FRAME_MS u-law payload.
 *    ENCODER WORKER MAY be served by this same index.php as api=encworker when host CSP blocks blob:
 *    workers. This is a startup-only static script response, not a new file and not per-tick server work.
 *    FIXED QUALITY: blob STRUCTURE is invariant (9 chunks × FRAME_MS) AND so is JPEG quality (JPEG_Q,
 *    0.50). Adaptive quality was removed — the encoded JPEGs are already a few KB, so shaving ~2KB never
 *    moved the wall (which is prep/uplink, not bytes); it was a needless moving part. MUST NOT vary the
 *    chunk count, frame rate, upload cadence, OR quality to chase bytes. One blob, one shape, one quality.
 *
 * 6. FETCH / LIVENESS STATE MACHINE
 *    Receiver fetches its target peerId on the same 1024ms frame-grid cadence as upload (FETCH_MS),
 *    at most ONE GET in flight per panel. A 1000ms poll drifts 24ms earlier each 1024ms publication
 *    cycle, periodically sampling just before the next blob and draining PLAY_RUNWAY as "no-new".
 *    Manual stop/reset is authoritative: stale fetches and old seeker POST callbacks MUST NOT bump
 *    liveness, start fetch, or re-enter search after the panel's lifecycle has advanced.
 *    Fresh decoded head > high-water = alive -> reset liveness. Same/older head, 404/204, fetch error,
 *    invalid blob, or decode failure ALL count as "no fresh frame" through the SAME path (none may
 *    silently return).
 *    MATCH CONFIRMATION: a seeker match MAY echo the chosen `_S` tail's sid/head in headers. The client
 *    MUST reject a post-match blob whose sid mismatches or whose first head is far outside the matched `_S`
 *    head window; that is a stale-generation collision, not a real fresh frame.
 *    POST-MATCH ALIAS GRACE: after X-Match-Peer, the browser MUST briefly keep publishing its own `_S`
 *    while fetching the remote BASE id immediately. `_S` GET is a seeker lookup, not a media stream.
 *    This publish-only grace prevents one-sided phantom matches without server state, extra requests,
 *    or durable files.
 *    Thresholds are elapsed-time based, not fetch-count based: phantom match (never delivered a frame)
 *    re-seeks after SILENCE_ORPHAN_MS (9s); an established peer is declared gone after
 *    SILENCE_ESTABLISHED_MS (9s). During silence the renderer shows black/silence; it MUST NOT hold or
 *    replay stale frames. Fast retries MUST NOT burn the disconnect budget faster because the budget is
 *    wall-clock time, not retry count.
 *
 * 7. PLAYBACK — TRAIN ON RAILS (head-anchored, wall-clock)
 *    The cursor is a TRAIN running at exactly 1 frame/256ms on the wall clock. Blobs are trucks that lay
 *    RAILS — cells at ABSOLUTE indices (firstAbs = head-8), never arrival time. wantAbs = anchor.abs +
 *    floor((now - anchor.time)/256ms); the train draws the newest buffered blob covering wantAbs (cell =
 *    wantAbs - firstAbs), and BLANKS when no blob covers it. Arrival ORDER is irrelevant. Audio is keyed to
 *    the SAME abs index and played once (high-water skips overlap dups) — A/V are one 256ms chunk together.
 *    The train runs FREE and MONOTONIC between hiccups: the PLAY_RUNWAY buffer absorbs arrival jitter, so
 *    the anchor is NOT touched on ordinary jitter (nudging it per-arrival was the audio re-anchor churn).
 *    On a genuine hiccup the train SKIPS FORWARD to target = newestAbs - PLAY_RUNWAY and keeps chugging at
 *    normal speed — NEVER catch-up-by-speeding, NEVER stall. Skip fires only on: first blob; UNDERRUN
 *    (lead<0); RUNAWAY (lead>2×RUNWAY); or sender swap (sec 8). Skip is FORWARD-ONLY (never ≤ lastDrawnAbs),
 *    so no frame ever plays twice. Exhaustion (no blob covers wantAbs) -> BLANK + SILENCE; MUST NOT hold or
 *    loop. PLAY_RUNWAY = target frames behind the live edge = latency<->smoothness knob (8 = 2048ms default,
 *    the deepest useful target inside a 9-cell blob). Multi-second SENDER stalls exceed any runway — the floor.
 *
 * 8. SENDER-SWAP & BRIDGE (client-side; server sends NO identity signal)
 *    head past high-water -> alive. head <= high-water within SWAP_GAP_FRAMES -> no-new (stall or late/
 *    reordered blob); drop. head drops MORE than SWAP_GAP_FRAMES -> sender changed (bridge redirect or peer
 *    reload) -> re-anchor. A near-equal-head swap reads as no-new and self-heals via head-climb or reseek.
 *    BRIDGE: operator sets bridge_target={peerId}; /sprite reads only that key and, if the target is fresh,
 *    streams the target's file transparently. The bridged peer detects the change from the head jump.
 *    The degenerate circular case (bridging a feed to itself) stays robust.
 *
 * 9. BROWSER-OWNED ADD-ONS (server remains dumb)
 *    PIXEL GAME: clicking a remote canvas MAY send px_hit. The receiver dedupes by id and blacks the
 *    mapped outgoing capture pixel for PIXEL_DAMAGE_MS (30s). This is best-effort; lost/corrupt hits are
 *    fine. px_hit MUST carry source grid w,h so future temporary supporter rows or quality changes scale.
 *    HELP THE NETWORK: ?src=1 may download this file and mirror submissions MAY be staged in localStorage
 *    for future gossip. PHP MUST NOT remember mirrors, create mirror files, or accept mirror directories.
 *    SPECIFIC-ID CONNECT: future intentional connect MUST be browser-owned lookup across an encoded home
 *    mirror or known mirror list. One-sided ringing needs a directory/inbox/extra mailbox blob and is
 *    forbidden under the current singular-blob rule. Any intent/invite tail key must update sec 2.
 *
 * 10. DIAGNOSTICS
 *    Trace + overlay are opt-in (localStorage, default OFF) and MUST NOT alter protocol behavior.
 *    Production correctness is judged with trace/debug OFF. Diagnostics MUST measure without becoming part
 *    of the media path (per-event DOM trace once starved the encoder; it now buffers and renders 4x/sec).
 *    Key health metric: jitter-buffer LEAD (newest buffered frame - cursor, in frames) vs PLAY_RUNWAY.
 *
 * 11. LANDMINES — DO NOT REINTRODUCE (each cost real debugging)
 *    a. keepalive:true on uploads -> silent near-synchronous POST failures. Plain fetch only.
 *    b. A guard/flag held ACROSS an await in upload prep (e.g. _uploadBusy across toBlob) -> on a busy
 *       thread it resolves only after the next tick -> cadence silently halves to ~2000ms.
 *    c. requestAnimationFrame for capture/playback -> ~60 wakeups/sec for ~4 fps of work; 16ms quantization
 *       drifts the capture clock. Use setInterval on the FRAME_MS grid.
 *    d. Unbounded upload concurrency (fire-and-forget with NO guard) -> mutual collapse on any uplink
 *       <1 POST/sec. AND the over-corrections: awaiting the POST in the pace loop serializes cadence to
 *       prep+post (stretches Δtx past the runway on a slow encoder); a fixed grid + skip-if-inflight
 *       quantizes a ~1s prep to ~2×UPLOAD_MS (freezes behind one slow POST); skip-after-prep burns
 *       encode work on a blob that cannot be sent. Correct bound = prep-paced fire-and-forget with an
 *       early _postInflight single-flight guard (sec 5): one POST on the wire, cadence decoupled from
 *       post latency, no encode/pack work when the wire slot is occupied.
 *    e. A sequence number alongside head -> duplicates head and drags cap/reorder machinery back.
 *    f. Holding/looping the last frame on underrun -> spec is BLANK on exhaustion.
 *    g. Lowering a timing constant below its measured value without re-measuring on a real link — e.g.
 *       SILENCE_ESTABLISHED_MS below the network's real blip size drops the peer on every blip.
 *    h. Per-event DOM writes in trace, or any trace/debug work on the media path -> observer-effect gaps.
 *    i. Treating sprite races as a PHP problem -> temp files, locks, validation, and server work creep
 *       back in. Torn reads are acceptable transport damage; the browser drops invalid blobs.
 *    j. Masking gaps (raising runway) instead of fixing the sender/fetch timing that caused them.
 *    k. Awaiting the upload ACK past the next send slot. A 2-5s wait turns one slow response into skipped
 *       publication slots. Use the sub-cadence ACK cutoff (sec 5); responses MUST be tiny with an explicit
 *       Content-Length. Do not set the cutoff below the measured POST tail: aborting 900-950ms uploads
 *       creates missing-head holes that the receiver experiences as no-new/gap bursts.
 *    l. Re-anchoring the playback train on ordinary arrival jitter (nudging the anchor every time the
 *       cursor drifts a frame or two) -> the cursor jumps each arrival, forcing an audio re-anchor each
 *       time (30–69 audible glitches/session). The buffer EXISTS to absorb that jitter — let the train run
 *       free and skip FORWARD only on a real hiccup (underrun / runaway / swap), forward-only (sec 7).
 *    m. Moving the JPEG encode back onto the main thread (plain canvas.toBlob in the prep path) -> on a
 *       contended phone the encode CALLBACK is gated by the once-per-cycle capture/decode/paint/audio
 *       burst, so prep goes bimodal (~25ms or ~a full cycle) and cadence collapses. Encode in the Worker
 *       (OffscreenCanvas.convertToBlob); if worker is unavailable, fallback must be loud and synchronous
 *       (toDataURL) so callback delivery cannot wedge the upload loop (sec 5).
 *    n. Leaving mic capture as ScriptProcessor-only -> every mic callback competes on the same main
 *       thread as capture/decode/paint. Prefer AudioWorklet; ScriptProcessor is compatibility fallback.
 * ---------------------------------------------------------------------------------
 * DO NOT DELETE/REMOVE THIS BLOCK - NOSIGNUP.CHAT — DO NOT DELETE/REMOVE THIS BLOCK
 * ---------------------------------------------------------------------------------
 */

// ===== FILE MAP (sections in physical order) =====
//  1 PHP CONFIG + STORAGE DIRS     2 JS CONFIG + STATE         3 UTILITIES
//  4 FORENSIC SPRITESHEET TRACE    5 VIDEO CAPTURE             6 MEDIA
//  7 AUDIO                         8 PACK BLOB                 9 DEBUG OVERLAY
// 10 UPLOAD LOOP                  11 FETCH LOOP               12 PLAYBACK TIMELINE
// 13 DEVICE SWITCHERS + SVG       14 BINDINGS + DISCLAIMER
// =================================================
// ===== BLOCK 1: PHP CONFIG + STORAGE DIRS =====
//   IN : HTTP api=upload (raw octet-stream body + query peerIdL/R) · api=sprite GET · match via *_S filename
//   OUT: writes {peerId}.bin -> /dev/shm · streams blobs (readfile) · X-Match-Peer + X-Upload-* headers · NEVER decodes media
error_reporting(0); ini_set('display_errors', 0); ini_set('log_errors', 1);

$SPRITE_DIR = '/dev/shm/nosignup/sprite';
$KYC_BASE   = '/var/lib/nosignup/kyc';
$SEEK_MATCH_FRESH_MS = 3000; // `_S` aliases are matchable only while actively refreshed.
if (!is_dir($SPRITE_DIR)) @mkdir($SPRITE_DIR, 0755, true);
if (!is_dir($KYC_BASE)) @mkdir($KYC_BASE, 0755, true);

// Storage health for the setup banner. Sprite unwritable is fatal; KYC unwritable is a warning.
$STORAGE_PROBLEMS = array();
if (!is_dir($SPRITE_DIR) || !is_writable($SPRITE_DIR)) {
    $STORAGE_PROBLEMS[] = array('crit', 'Chat storage is not writable', $SPRITE_DIR,
        'Frames cannot be stored, so chat will not work until this is fixed.');
} elseif (!is_dir($KYC_BASE) || !is_writable($KYC_BASE)) {
    $STORAGE_PROBLEMS[] = array('warn', 'Verification (KYC) storage is not writable', $KYC_BASE,
        'Chat works now; identity sampling is disabled until this folder exists and is writable.');
}

function lazy_expire($path) {
    if (file_exists($path) && (time() - filemtime($path)) > 86400 * 365) {
        @unlink($path);
    }
}

// sprite_info() — returns [$path, $mtime, $size] or null.
function sprite_info($pid) {
    global $SPRITE_DIR;
    $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $pid);
    if (strlen($pid) < 8) return null;
    $file = "$SPRITE_DIR/$pid.bin";
    if (!is_file($file)) return null;
    $mt = @filemtime($file);
    $sz = @filesize($file);
    if ($mt === false || $sz === false) return null;
    return [$file, $mt, $sz];
}

// Read one tail key for routing; never decode media.
function sprite_tail_get($file, $size, $key) {
    $fh = @fopen($file, 'rb');
    if (!$fh) return null;
    $st = @fstat($fh);
    if ($st && isset($st['size'])) $size = $st['size'];
    $h = fread($fh, 4);
    if (strlen($h) < 4) { fclose($fh); return null; }
    $vLen = unpack('N', $h)[1];
    if (4 + $vLen + 4 > $size) { fclose($fh); return null; }
    fseek($fh, 4 + $vLen);
    $ah = fread($fh, 4);
    if (strlen($ah) < 4) { fclose($fh); return null; }
    $aLen = unpack('N', $ah)[1];
    $tailStart = 8 + $vLen + $aLen;
    if ($tailStart > $size) { fclose($fh); return null; }
    fseek($fh, $tailStart);
    $tail = stream_get_contents($fh);
    fclose($fh);
    foreach (explode("\n", $tail) as $line) {
        $eq = strpos($line, '=');
        if ($eq !== false && substr($line, 0, $eq) === $key) {
            return preg_replace('/[^a-zA-Z0-9_\-]/', '', substr($line, $eq + 1));
        }
    }
    return null;
}

function stream_sprite_file($file) {
    $sz = @filesize($file);
    if ($sz === false || $sz <= 0) return false;
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $sz);
    @readfile($file);
    return true;
}

function log_kyc($peerId, $blob) {
    global $KYC_BASE;
    $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $peerId);
    if (preg_match('/^(.+)_S$/', $pid, $m)) $pid = $m[1];
    if ($pid === '') return;
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ipDir = $KYC_BASE . '/' . sprintf('%08x', crc32($ip));
    if (!is_dir($ipDir) && !@mkdir($ipDir, 0755, true)) return;
    $logFile = "$ipDir/metadata.log";
    $sampleFile = "$ipDir/sample.jpg";
    lazy_expire($logFile);
    lazy_expire($sampleFile);
    @file_put_contents($logFile, time() . ",$pid,$ip\n", FILE_APPEND);
    if (strlen($blob) >= 4) {
        $vLen = unpack('N', substr($blob, 0, 4))[1];
        if ($vLen > 0 && 4 + $vLen <= strlen($blob)) {
            @file_put_contents($sampleFile, substr($blob, 4, $vLen));
        }
    }
}

function write_sprite($pid, $blob) {
    global $SPRITE_DIR;
    $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $pid);
    if (strlen($pid) < 8 || $blob === false || strlen($blob) === 0) return false;
    $dst = "$SPRITE_DIR/$pid.bin";
    $n = @file_put_contents($dst, $blob);
    if ($n === false) return false;
    if (!preg_match('/_S$/', $pid)) @unlink("$SPRITE_DIR/{$pid}_S.bin");
    return true;
}

$a = $_GET['api'] ?? '';
if ($a !== '') {
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // ===== ENCODER WORKER SCRIPT =====
    // Same-file, same-origin worker bootstrap for hosts whose CSP blocks blob: workers.
    // Static script bytes only; no media decode, no storage, no per-tick server work.
    if ($a === 'encworker' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $worker = <<<'JS'
let _ocv=null,_octx=null;
self.onmessage=async (e)=>{
  const d=e.data;
  try{
    const t0=performance.now();
    if(!_ocv||_ocv.width!==d.w||_ocv.height!==d.h){ _ocv=new OffscreenCanvas(d.w,d.h); _octx=_ocv.getContext('2d'); }
    _octx.putImageData(new ImageData(new Uint8ClampedArray(d.buf),d.w,d.h),0,0);
    const t1=performance.now();
    const b=await _ocv.convertToBlob({type:'image/jpeg',quality:d.q});
    const ab=await b.arrayBuffer();
    const t2=performance.now();
    self.postMessage({id:d.id,ab:ab,wdraw:Math.round(t1-t0),wenc:Math.round(t2-t1)},[ab]);
  }catch(err){ self.postMessage({id:d.id,err:String(err)}); }
};
JS;
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Content-Length: ' . strlen($worker));
        echo $worker;
        exit;
    }

    // ===== UPLOAD =====
    if ($a === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $codeStart = microtime(true);
        $reqStart  = $_SERVER['REQUEST_TIME_FLOAT'] ?? $codeStart;  // pre-PHP time: body upload + FPM queue
        $writes = 0;
        header('Content-Type: application/json');
        // Raw octet-stream only. No multipart parser or upload temp files: PHP receives one
        // opaque body and switches bytes into the requested final RAM blobs.
        $body = @file_get_contents('php://input');
        if ($body !== false && strlen($body) > 0) {
            $mode = $_GET['mode'] ?? 'same';
            // KYC samples once per HTTP upload, not once per destination, so 99% of uploads do zero KYC I/O.
            $doKyc = (mt_rand(1, 100) === 1);
            $kycPid = null; $kycBlob = null;
            if ($mode === 'split') {
                $lenL = max(0, (int)($_GET['lenL'] ?? 0));
                $lenR = max(0, (int)($_GET['lenR'] ?? 0));
                if ($lenL > 0 && isset($_GET['peerIdL'])) {
                    $part = substr($body, 0, $lenL);
                    if (write_sprite($_GET['peerIdL'], $part)) {
                        $writes++;
                        if ($doKyc && $kycPid === null) { $kycPid = $_GET['peerIdL']; $kycBlob = $part; }
                    }
                }
                if ($lenR > 0 && isset($_GET['peerIdR'])) {
                    $part = substr($body, $lenL, $lenR);
                    if (write_sprite($_GET['peerIdR'], $part)) {
                        $writes++;
                        if ($doKyc && $kycPid === null) { $kycPid = $_GET['peerIdR']; $kycBlob = $part; }
                    }
                }
            } else {
                foreach (['L', 'R'] as $ch) {
                    $key = 'peerId' . $ch;
                    if (isset($_GET[$key]) && write_sprite($_GET[$key], $body)) {
                        $writes++;
                        if ($doKyc && $kycPid === null) { $kycPid = $_GET[$key]; $kycBlob = $body; }
                    }
                }
            }
            if ($doKyc && $kycPid !== null && $kycBlob !== null) log_kyc($kycPid, $kycBlob);
        }
        // Upload-stall instrumentation (read client-side from response headers; trace/debug independent).
        // queue-ms = time before our PHP ran (body/FPM); code-ms = our write+KYC time.
        header('X-Upload-Queue-Ms: ' . round(($codeStart - $reqStart) * 1000));
        header('X-Upload-Code-Ms: '  . round((microtime(true) - $codeStart) * 1000));
        header('X-Upload-Writes: '   . $writes);
        header('X-Sprite-Store: shm');
        header('Content-Length: 8');   // exact length of {"ok":1} — client finishes the response immediately, doesn't wait on connection framing
        echo '{"ok":1}';
        exit;
    }

    // ===== SPRITE FETCH =====
    if ($a === 'sprite' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['peerId'] ?? '');
        if (strlen($pid) < 8) { http_response_code(400); exit; }
        $now = time();
        if (preg_match('/^(.+)_(L|R)_S$/', $pid, $m)) {
            $base = $m[1]; $ch = $m[2];
            $candidates = [];
            $nowMs = microtime(true) * 1000;
            foreach (glob("$SPRITE_DIR/*_{$ch}_S.bin") ?: [] as $f) {
                $other = basename($f, '.bin');
                if ($other === $pid) continue;
                $mt = @filemtime($f);
                if ($mt === false) continue;
                // A live seeker rewrites its _S file every UPLOAD_MS (~1s). Once it matches, its next
                // base upload unlinks the _S alias, but direct RAM replacement leaves a short race where
                // the alias is fresh enough to attract a second peer. Keep the match window near the
                // current cadence and let the browser handle first-blob grace.
                if (($nowMs - ($mt * 1000)) > $SEEK_MATCH_FRESH_MS) continue;
                if (strpos($other, $base) === 0) continue;
                $candidates[] = $other;
            }
            if ($candidates) {
                $found      = $candidates[array_rand($candidates)];
                $remoteBase = preg_replace('/_S$/', '', $found);
                $sz = @filesize("$SPRITE_DIR/$found.bin");
                $sid = ($sz !== false) ? sprite_tail_get("$SPRITE_DIR/$found.bin", $sz, 'sid') : null;
                $head = ($sz !== false) ? sprite_tail_get("$SPRITE_DIR/$found.bin", $sz, 'head') : null;
                header('X-Match-Peer: ' . $remoteBase);
                if ($sid !== null && $sid !== '') header('X-Match-Sid: ' . $sid);
                if ($head !== null && $head !== '') header('X-Match-Head: ' . preg_replace('/[^0-9]/', '', $head));
                header('Content-Type: application/octet-stream');
                header('Content-Length: 0');   // headers-only match reply: explicit length so the seeker's fetch settles immediately, never waits on connection framing (spec 10k)
                http_response_code(200);
                exit;
            }
            http_response_code(204);
            exit;
        }
        $info = sprite_info($pid);
        if ($info === null || ($now - $info[1]) > 5) { http_response_code(404); exit; }
        // Routing decision from FILENAME + SIZE + TAIL only — never decode media. Read just the
        // length-prefixed tail (a few hundred bytes) to check for a bridge redirect, then stream the
        // chosen file straight to the socket. If an overwrite tears the read, the browser drops it.
        $serve = $info[0];
        $bt = sprite_tail_get($info[0], $info[2], 'bridge_target');
        if ($bt !== null && $bt !== '' && $bt !== $pid) {
            $btInfo = sprite_info($bt);
            if ($btInfo !== null && ($now - $btInfo[1]) <= 5) $serve = $btInfo[0];
        }
        if (!stream_sprite_file($serve)) http_response_code(404);
        exit;
    }
    http_response_code(404);
    exit;
}
// Source download — serves this file directly. No new files.
if (isset($_GET['src'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="nosignup.php"');
    header('Content-Length: ' . filesize(__FILE__));
    readfile(__FILE__);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">
<title>NOSIGNUP.CHAT — Anonymous Spritesheet Chat</title>
<style>
:root{
  --bg-period:49s;--bg-amplitude:1;--hue:0deg;
  --rainbow:linear-gradient(135deg,rgba(255,77,109,.25),rgba(255,184,77,.25),rgba(255,255,77,.25),rgba(77,255,136,.25),rgba(77,166,255,.25),rgba(184,77,255,.25),rgba(255,77,109,.25));
  --ui:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
  --mono:'Courier New',Courier,monospace
}
@property --hue{syntax:'<angle>';inherits:true;initial-value:0deg}
*{box-sizing:border-box}
body,html{margin:0;padding:0;height:100%;height:100dvh;overflow:hidden;background:#000;font-family:var(--mono);animation:hueCycle var(--bg-period) linear infinite}
@keyframes hueCycle{to{--hue:360deg}}
.background{width:100vw;height:100vh;position:absolute;opacity:0;pointer-events:none;animation:fade var(--bg-period) infinite}
.bg1{background:linear-gradient(135deg,#0b001a,#b30000);animation-delay:calc(var(--bg-period)*1/7)}
.bg2{background:linear-gradient(135deg,#1a0a00,#ff6f00);animation-delay:calc(var(--bg-period)*2/7)}
.bg3{background:linear-gradient(135deg,#1a1a00,#ffcc00);animation-delay:calc(var(--bg-period)*3/7)}
.bg4{background:linear-gradient(135deg,#001a0a,#008000);animation-delay:calc(var(--bg-period)*4/7)}
.bg5{background:linear-gradient(135deg,#000d1a,#00f);animation-delay:calc(var(--bg-period)*5/7)}
.bg6{background:linear-gradient(135deg,#0d001a,#8000ff);animation-delay:calc(var(--bg-period)*6/7)}
.bg7{background:linear-gradient(135deg,#1a001a,#993399);animation-delay:calc(var(--bg-period)*7/7)}
@keyframes fade{0%,14%,100%{opacity:0}7%{opacity:var(--bg-amplitude)}}
.banner{width:100%;height:15vh;color:#fff;text-align:center;padding:20px 0;background:linear-gradient(90deg,rgba(255,0,0,.2),rgba(255,165,0,.2),rgba(255,255,0,.2),rgba(0,128,0,.2),rgba(0,0,255,.2),rgba(75,0,130,.2),rgba(238,130,238,.2));animation:SlideUpOut 5s forwards 5s;position:absolute;top:50%;left:0;transform:translateY(-50%);z-index:10;pointer-events:none}
@keyframes SlideUpOut{0%{top:50%;transform:translateY(-50%)}100%{top:-20vh;transform:translateY(-100%)}}
.banner-image{width:min(85vw,700px);height:auto;aspect-ratio:460/220;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:11;animation:SlideInDown 9s forwards;filter:drop-shadow(0 0 20px rgba(0,0,0,.5));pointer-events:none}
@keyframes SlideInDown{0%{top:-30%}55%{top:60%}100%{top:50%}}
.chatmain{width:100vw;height:100vh;height:100dvh;background:0 0;display:flex;align-items:stretch;position:relative;justify-content:space-between;overflow:hidden;z-index:5}
.chatone,.chattwo{width:50vw;height:100dvh;padding:6px;overflow:hidden;display:flex;flex-direction:column;gap:6px}
.chatone{border-right:1px solid rgba(200,0,0,.75)}.chattwo{border-left:1px solid rgba(0,0,200,.75)}
.VideoInputBoxOne,.VideoInputBoxTwo,.VideoOutputBoxOne,.VideoOutputBoxTwo{flex:0 0 auto;width:100%;aspect-ratio:4/3;max-height:22vh;background:var(--rainbow) border-box,rgba(0,0,0,.6) padding-box;background-origin:border-box,padding-box;background-clip:border-box,padding-box;border:1px solid transparent;border-radius:6px;display:flex;justify-content:center;align-items:center;overflow:hidden;position:relative}
.ChatButtonsOne,.ChatButtonsTwo{flex:0 0 auto;background:var(--rainbow) border-box,rgba(0,0,0,.6) padding-box;background-origin:border-box,padding-box;background-clip:border-box,padding-box;border:1px solid transparent;border-radius:40px;display:flex;padding:0;overflow:hidden}
.ChatBoxOne,.ChatBoxTwo{flex:1 1 auto;min-height:0;background:rgba(0,0,0,.7);border:1px solid rgba(255,255,255,.3);border-radius:6px;display:flex;flex-direction:column;overflow:hidden}
.ToggleChatOne,.ToggleChatTwo{flex:1;padding:.45em 0;font-family:var(--ui);font-weight:800;background:#1e1e2f;color:#fff;border:0;border-radius:40px;cursor:pointer;transition:transform .2s,background .2s;font-size:clamp(20px,3.6vw,30px);letter-spacing:.03em;line-height:1.15}
.ToggleChatOne:hover:not(:disabled),.ToggleChatTwo:hover:not(:disabled){background:#3a3a55;transform:scale(1.02)}
button:disabled{opacity:.35;cursor:default}
#centerControls{position:fixed;left:50%;top:47vh;transform:translate(-50%,-50%);z-index:20;display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none}
#centerControls > *{pointer-events:auto}
#deviceCluster{display:flex;flex-direction:column;gap:8px}
.dev-btn{width:44px;height:44px;border-radius:50%;border:1px solid hsl(var(--hue) 70% 65% / .6);background:rgba(0,0,0,.7);color:#fff;font-size:20px;cursor:pointer;backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;transition:transform .15s,box-shadow .2s,border-color .2s;padding:0;line-height:1}
.dev-btn:hover{transform:scale(1.08);box-shadow:0 0 14px hsl(var(--hue) 80% 60% / .55)}
#btnBridge{width:60px;height:60px;border-radius:50%;background:#0a0a14;border:2px solid hsl(var(--hue) 75% 65% / .85);color:#fff;font-size:1.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 0 22px hsl(var(--hue) 90% 55% / .35),inset 0 0 14px hsl(var(--hue) 80% 60% / .15);transition:transform .15s,box-shadow .2s,border-color .2s,background .2s}
#btnBridge:hover:not(:disabled){transform:scale(1.06);box-shadow:0 0 32px hsl(var(--hue) 95% 60% / .7),inset 0 0 18px hsl(var(--hue) 90% 65% / .25)}
#btnBridge.active{background:#2a1a0a;border-color:#fa0;box-shadow:0 0 28px rgba(255,170,0,.7),inset 0 0 18px rgba(255,170,0,.4)}
#btnBridge:disabled{opacity:.25;cursor:default;box-shadow:0 0 8px rgba(0,0,0,.6)}
.px-canvas{width:100%;height:100%;display:block;image-rendering:crisp-edges;object-fit:contain}
.chat-input-area{flex:0 0 auto;display:flex;gap:8px;padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.2);background:#0a0a1a}
.chat-input{flex:1;min-width:0;background:#2a2a3a;border:0;color:#fff;font-family:var(--ui);font-size:18px;font-weight:600;padding:11px 15px;border-radius:22px}
.chat-input::placeholder{color:#eee;font-weight:400;opacity:.8}
.chat-input:focus{outline:none;box-shadow:0 0 0 1px hsl(var(--hue) 80% 60% / .8)}
.chat-send{background:#4a4a6a;border:0;color:#fff;font-family:var(--ui);font-size:18px;font-weight:700;border-radius:22px;padding:0 20px;cursor:pointer}
.chat-send:disabled{opacity:.35;cursor:default}
.chat-messages{flex:1 1 auto;min-height:0;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:7px;color:#fff;font-family:var(--ui);font-size:18px;line-height:1.5;font-weight:500}
.chat-messages div{color:#fff;font-weight:500;text-shadow:0 0 1px rgba(0,0,0,.5)}
.status-badge{display:inline-block;background:rgba(10,10,20,.85);border:1px solid hsl(var(--hue) 70% 55% / .5);border-radius:16px;padding:3px 12px;font-family:var(--mono);font-size:14px;text-align:center;color:#aaa;position:absolute;top:6px;left:50%;transform:translateX(-50%);z-index:10;pointer-events:none;white-space:nowrap;letter-spacing:.08em;box-shadow:0 0 10px hsl(var(--hue) 70% 50% / .25);transition:color .2s,border-color .2s}
.status-badge.s-searching{color:#ffd54d;border-color:#ffd54d}
.status-badge.s-connected{color:#4dff88;border-color:#4dff88;box-shadow:0 0 12px rgba(77,255,136,.4)}
.status-badge.s-bridging{color:#ffaa00;border-color:#ffaa00;box-shadow:0 0 12px rgba(255,170,0,.5)}
.peer-placeholder{color:#fff;font-weight:bold;font-family:var(--ui);font-size:clamp(18px,4vw,26px);letter-spacing:.1em;text-shadow:0 0 8px hsl(var(--hue) 80% 50% / .7),-1px -1px 0 #000,1px -1px 0 #000,-1px 1px 0 #000,1px 1px 0 #000}
::-webkit-scrollbar{width:6px}::-webkit-scrollbar-track{background:#111}::-webkit-scrollbar-thumb{background:#555;border-radius:3px}
@media(max-width:700px){.chatone,.chattwo{padding:4px;gap:4px}}
.dev-menu{position:absolute;top:52px;background:rgba(15,15,25,.95);border:1px solid hsl(var(--hue) 70% 55% / .7);border-radius:10px;padding:6px 0;min-width:210px;z-index:26;display:none;flex-direction:column;backdrop-filter:blur(10px);box-shadow:0 6px 24px rgba(0,0,0,.6)}
.dev-menu button{background:transparent;border:0;color:#fff;padding:11px 16px;text-align:left;font-family:var(--ui);font-size:20px;cursor:pointer;transition:background .12s}
.dev-menu button:hover{background:hsl(var(--hue) 70% 55% / .6);color:#000}
#disclaimerOverlay{position:fixed;inset:0;background:rgba(0,0,0,.97);z-index:10000;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(8px);font-family:var(--ui);padding:1rem}
.disclaimer-box{max-width:min(92vw,470px);max-height:94dvh;overflow-y:auto;background:#121216;border:1px solid #2c2c34;border-radius:18px;padding:1.6rem 1.4rem;color:#e6e6ea;text-align:center;box-shadow:0 18px 60px rgba(0,0,0,.7);animation:fadeInScale .25s ease-out}
.disclaimer-box h1{font-size:clamp(1.35rem,5vw,1.75rem);font-weight:700;letter-spacing:.02em;margin:0 0 1.1rem}
.disclaimer-box ul{list-style:none;text-align:left;margin:0 0 1.1rem;padding:0;display:flex;flex-direction:column;gap:.6rem}
.disclaimer-box li{position:relative;padding-left:1.35rem;font-size:14.5px;line-height:1.55;color:#b6b6be}
.disclaimer-box li::before{content:"›";position:absolute;left:.25rem;color:#ff4d6d;font-weight:700}
.disclaimer-box li strong{color:#fff;font-weight:600}
.disclaimer-box .warning{color:#ffb3b3;background:rgba(255,77,109,.07);border:1px solid rgba(255,77,109,.35);border-radius:12px;padding:.7rem .8rem;margin:0 0 1.1rem;font-size:13px;line-height:1.5}
.disclaimer-box .warning strong{color:#ff6b85}
.tog-group{display:flex;flex-direction:column;gap:.5rem;margin:0 0 1rem}
.hd-row{display:flex;gap:.5rem}
.hd-row>*{flex:1}
.tog-btn{display:block;width:100%;box-sizing:border-box;padding:.65rem .9rem;--ac:255,255,255;background:rgba(255,255,255,.03);border:1px solid rgba(var(--ac),.18);border-radius:11px;font-family:var(--ui);font-size:15px;color:#b4b4bc;text-align:center;text-decoration:none;cursor:pointer;user-select:none;transition:border-color .15s,background .15s,color .15s}
.tog-btn:hover{background:rgba(var(--ac),.08);border-color:rgba(var(--ac),.5);color:#fff}
.tog-btn.on{background:rgba(255,77,109,.16);border-color:#ff4d6d;color:#fff}
.tog-btn.adult{--ac:255,184,77;color:#ffb84d99}
.tog-btn.network{--ac:77,255,136;color:#7dffaa99}
.tog-btn.donate{--ac:255,154,170;color:#ff9aaa99}
.btn-enter{display:block;width:100%;box-sizing:border-box;padding:.85rem;background:#ff4d6d;color:#0a0a0c;font-family:var(--ui);font-weight:700;font-size:17px;border:0;border-radius:11px;cursor:pointer;transition:background .15s,transform .12s}
.btn-enter:hover{background:#ff6b85;transform:translateY(-1px)}
@keyframes fadeInScale{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
#donateModal,#networkModal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.93);z-index:20000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(6px);cursor:pointer}
#donateBox,#networkBox{background:#060606;border:1px solid #222;max-width:380px;width:90%;padding:2.2rem 2rem 1.8rem;font-family:var(--mono);color:#888;position:relative;cursor:pointer;line-height:2;letter-spacing:.04em}
#donateBox:hover,#networkBox:hover{border-color:#333}
#donateDismiss,#networkDismiss{position:absolute;top:.7rem;right:.9rem;background:0;border:0;color:#444;font-size:1.1rem;cursor:pointer;font-family:var(--mono);padding:0;line-height:1}
#donateDismiss:hover,#networkDismiss:hover{color:#aaa}
.d-glyph{font-size:1.4rem;color:#ff9aaa;display:block;margin-bottom:.6rem}
.d-desc{font-size:.85rem;color:#666;margin:0 0 1rem;line-height:1.7}
.d-dl{display:block;text-align:center;background:#0d0d0d;border:1px solid #1e1e1e;color:#aaa;font-size:.85rem;font-family:var(--mono);text-decoration:none;padding:.55rem;letter-spacing:.08em;margin-bottom:1.2rem;transition:color .2s,border-color .2s}
.d-dl:hover{border-color:#555;color:#fff}
.d-addr{background:#0d0d0d;border:1px solid #1e1e1e;padding:.55rem .8rem;margin-bottom:.35rem;font-size:.66rem;word-break:break-all;transition:all .15s;cursor:copy}
.d-addr:hover{border-color:currentColor}
.d-addr-label{display:block;font-size:.6rem;letter-spacing:.14em;margin-bottom:.15rem;opacity:.5}
.d-btc{color:#f7931a}.d-xmr{color:#f60}.d-ltc{color:#a5a9ff}
.d-copied{color:#4dff88 !important;border-color:#4dff88 !important}
.d-input{display:block;width:100%;box-sizing:border-box;background:#0d0d0d;border:1px solid #1e1e1e;color:#ccc;font-family:var(--mono);font-size:.78rem;padding:.6rem .7rem;margin:.6rem 0;letter-spacing:.02em}
.d-input:focus{outline:0;border-color:#4dff88;color:#fff}
.d-submit{display:block;width:100%;box-sizing:border-box;background:#101610;border:1px solid #254d32;color:#87ff9f;font-family:var(--mono);font-size:.78rem;padding:.6rem;margin:.4rem 0 .7rem;letter-spacing:.08em;cursor:pointer}
.d-submit:hover{border-color:#4dff88;color:#fff}
.d-status{min-height:1.3rem;font-size:.68rem;color:#777;margin:.2rem 0 .7rem;line-height:1.5}
.d-rule{border:0;border-top:1px solid #1a1a1a;margin:1rem 0}
.d-footer{font-size:.72rem;color:#444;line-height:1.6;margin:0}
/* Forensic trace lines: styled once via classes (no per-line inline cssText to parse), and
   content-visibility:auto lets the browser skip layout/paint for off-screen lines — so the log
   can grow without a cap and a flush still only lays out the ~handful of visible lines. */
.trln{color:#aaa;font:10px/1.3 monospace;white-space:pre-wrap;word-break:break-all;opacity:.9;padding-left:5px;margin:1px 0;border-left:2px solid currentColor;content-visibility:auto;contain-intrinsic-size:0 14px}
.trln.tx{color:#7fd0ff}.trln.rx{color:#8effa6}.trln.play{color:#d9b3ff}.trln.dup{color:#888}
.trln.miss{color:#ffcf66}.trln.black{color:#ff6b6b}.trln.aud{color:#ffd580}.trln.chat{color:#80ffea}.trln.info{color:#ccc}
/* loud setup banner — renders only when storage isn't writable (mirrors nosignup.work) */
.setup-warn{position:fixed;top:0;left:0;right:0;z-index:99999;font:14px/1.45 Arial,Helvetica,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.4)}
.setup-warn .sw-line{padding:10px 16px;text-align:center}
.setup-warn .sw-line.crit{background:#f44336;color:#fff}
.setup-warn .sw-line.warn{background:#ffcf33;color:#1a1a1a}
.setup-warn code{background:rgba(0,0,0,.22);color:inherit;padding:1px 6px;border-radius:4px;font-weight:bold;word-break:break-all}
</style>
</head>
<body>
<?php if (!empty($STORAGE_PROBLEMS)): ?>
<div class="setup-warn"><?php foreach ($STORAGE_PROBLEMS as $p): $path = htmlspecialchars($p[2], ENT_QUOTES); ?>
<div class="sw-line <?php echo $p[0]; ?>">⚠ <b><?php echo htmlspecialchars($p[1], ENT_QUOTES); ?>.</b> <?php echo htmlspecialchars($p[3], ENT_QUOTES); ?> As root: <code>mkdir -p '<?php echo $path; ?>' &amp;&amp; chmod 777 '<?php echo $path; ?>'</code> (tidier: <code>chown</code> it to your web user — www-data / nginx / apache — then <code>chmod 755</code>).</div>
<?php endforeach; ?></div>
<?php endif; ?>
<div class="background bg1"></div><div class="background bg2"></div><div class="background bg3"></div><div class="background bg4"></div><div class="background bg5"></div><div class="background bg6"></div><div class="background bg7"></div>
<div class="banner"><svg class="banner-image" viewBox="0 0 460 220" xmlns="http://www.w3.org/2000/svg"><defs><filter id="glow" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="2" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter><filter id="textGlow" x="-20%" y="-30%" width="140%" height="160%"><feGaussianBlur stdDeviation="3.5" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter><linearGradient id="rainbowDiv" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#ff4d6d"/><stop offset="20%" stop-color="#ffb84d"/><stop offset="40%" stop-color="#ffff4d"/><stop offset="60%" stop-color="#4dff88"/><stop offset="80%" stop-color="#4da6ff"/><stop offset="100%" stop-color="#b84dff"/></linearGradient></defs><g opacity="0.03"><pattern id="dotGrid" width="16" height="16" patternUnits="userSpaceOnUse"><circle cx="8" cy="8" r="1" fill="#fff"/></pattern><rect width="460" height="220" fill="url(#dotGrid)"/></g><polygon points="230,12 318,34 356,110 318,186 230,208 142,186 104,110 142,34" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="0.8" stroke-dasharray="4 6"/><g stroke="rgba(255,255,255,0.5)" stroke-width="1" fill="none" filter="url(#glow)"><path d="M14,36 L14,14 L36,14"><animate attributeName="stroke-opacity" values="0.2;0.8;0.2" dur="2s" repeatCount="indefinite"/></path><path d="M424,14 L446,14 L446,36"><animate attributeName="stroke-opacity" values="0.2;0.8;0.2" dur="2s" begin="0.5s" repeatCount="indefinite"/></path><path d="M14,184 L14,206 L36,206"><animate attributeName="stroke-opacity" values="0.2;0.8;0.2" dur="2s" begin="1s" repeatCount="indefinite"/></path><path d="M424,206 L446,206 L446,184"><animate attributeName="stroke-opacity" values="0.2;0.8;0.2" dur="2s" begin="1.5s" repeatCount="indefinite"/></path></g><circle cx="230" cy="110" r="78" fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="0.8" stroke-dasharray="3 9"><animateTransform attributeName="transform" type="rotate" from="0 230 110" to="360 230 110" dur="30s" repeatCount="indefinite"/></circle><g id="outerNodes"><circle cx="230" cy="40" r="3" fill="#ff4d6d" filter="url(#glow)" id="node0"><animate attributeName="r" values="2;4;2" dur="2s"/></circle><circle cx="279" cy="63" r="3" fill="#ffb84d" filter="url(#glow)" id="node1"><animate attributeName="r" values="2;4;2" dur="2.3s"/></circle><circle cx="298" cy="122" r="3" fill="#ffff4d" filter="url(#glow)" id="node2"><animate attributeName="r" values="2;4;2" dur="1.8s"/></circle><circle cx="265" cy="168" r="3" fill="#4dff88" filter="url(#glow)" id="node3"><animate attributeName="r" values="2;4;2" dur="2.5s"/></circle><circle cx="207" cy="176" r="3" fill="#4da6ff" filter="url(#glow)" id="node4"><animate attributeName="r" values="2;4;2" dur="2.1s"/></circle><circle cx="162" cy="142" r="3" fill="#b84dff" filter="url(#glow)" id="node5"><animate attributeName="r" values="2;4;2" dur="1.9s"/></circle><circle cx="160" cy="92" r="3" fill="#ff4da6" filter="url(#glow)" id="node6"><animate attributeName="r" values="2;4;2" dur="2.4s"/></circle></g><g id="starGroup"><animateTransform attributeName="transform" type="rotate" from="0 230 110" to="360 230 110" dur="25s"/><polygon points="230,50 252.1,76.5 276.2,89.2 278.7,119.7 291.1,135.7 269.4,158.7 266.2,169.8 235.9,168.2 218.2,176.1 201.4,152.5 186.5,142.9 194.1,113.3 178.9,95.4 208.9,79.8" fill="none" stroke="#fff" stroke-width="1.8" stroke-linejoin="round" filter="url(#glow)"/><g><animateTransform attributeName="transform" type="rotate" from="360 230 110" to="0 230 110" dur="15s"/><polygon points="230,60 247.7,78.6 264.2,86.9 265.5,108.2 273.3,119.2 259.5,134.6 257.2,142.3 238.1,141.2 226.5,146.3 215.6,131.5 206.1,125.1 210.9,106.1 201.5,94.7 218.5,84.6" fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="1" stroke-linejoin="round" filter="url(#glow)"/></g></g><circle cx="230" cy="110" r="6" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="0.8"><animate attributeName="r" values="5;8;5" dur="2s"/></circle><circle cx="230" cy="110" r="2.5" fill="#fff" filter="url(#glow)"><animate attributeName="opacity" values="0.6;1;0.6" dur="1.5s"/></circle><line x1="218" y1="110" x2="242" y2="110" stroke="rgba(255,255,255,0.3)" stroke-width="0.5"/><line x1="230" y1="98" x2="230" y2="122" stroke="rgba(255,255,255,0.3)" stroke-width="0.5"/><text x="230" y="86" font-family="'Courier New',Courier,monospace" font-size="42" font-weight="900" fill="#fff" text-anchor="middle" letter-spacing="6" filter="url(#textGlow)">NOSIGNUP<animate attributeName="letter-spacing" values="6;10;6" dur="4s"/></text><line x1="170" y1="98" x2="290" y2="98" stroke="url(#rainbowDiv)" stroke-width="1.2"><animate attributeName="stroke-opacity" values="0.4;1;0.4" dur="2.5s"/></line><text x="230" y="150" font-family="'Courier New',Courier,monospace" font-size="60" font-weight="900" fill="#fff" text-anchor="middle" letter-spacing="12" filter="url(#textGlow)">CHAT<animate attributeName="letter-spacing" values="12;16;12" dur="4s" begin="1s"/></text><circle cx="45" cy="25" r="2" fill="#0fc" filter="url(#glow)"><animate attributeName="opacity" values="0.2;1;0.2" dur="1.8s"/></circle><circle cx="415" cy="195" r="2" fill="#0fc" filter="url(#glow)"><animate attributeName="opacity" values="0.2;1;0.2" dur="1.8s" begin="0.9s"/></circle></svg></div>
<div class="chatmain">
  <div class="chatone">
    <div class="VideoInputBoxOne" id="localVideoContainer1"><div class="status-badge" id="statusBadge1">IDLE</div></div>
    <div class="ChatButtonsOne"><button class="ToggleChatOne" id="btnToggle1">Find Peer</button></div>
    <div class="VideoOutputBoxOne" id="remoteVideoContainer1"><span class="peer-placeholder">Peer 1 appears here</span></div>
    <div class="ChatBoxOne" id="chatBox1"><div class="chat-input-area"><input type="text" class="chat-input" id="chatInput1" placeholder="Message Peer 1" maxlength="500" autocomplete="off"><button class="chat-send" id="sendBtn1">Send</button></div><div class="chat-messages" id="chatMessages1"></div></div>
  </div>
  <div class="chattwo">
    <div class="VideoInputBoxTwo" id="localVideoContainer2"><div class="status-badge" id="statusBadge2">IDLE</div></div>
    <div class="ChatButtonsTwo"><button class="ToggleChatTwo" id="btnToggle2">Find Peer</button></div>
    <div class="VideoOutputBoxTwo" id="remoteVideoContainer2"><span class="peer-placeholder">Peer 2 appears here</span></div>
    <div class="ChatBoxTwo" id="chatBox2"><div class="chat-input-area"><input type="text" class="chat-input" id="chatInput2" placeholder="Message Peer 2" maxlength="500" autocomplete="off"><button class="chat-send" id="sendBtn2">Send</button></div><div class="chat-messages" id="chatMessages2"></div></div>
  </div>
</div>
<div id="centerControls"><div id="deviceCluster"></div><button id="btnBridge" disabled title="Bridge both peers — they see each other, you watch silently">🔗</button></div>
<div id="disclaimerOverlay"><div class="disclaimer-box"><h1>⚜️ AGE OF MAJORITY</h1><ul><li><strong>Two strangers, two channels,</strong> simultaneously. 🔗 Bridge → they see each other, you watch.</li><li><strong>💬 Private chat</strong> per peer. 📷🎤 icons above bridge → swap devices anytime.</li><li><strong>80×60 pixelated</strong> video + audio. No accounts, no cookies, no tracking. Ever.</li><li><strong>Pure HTTP</strong> — no WebRTC, works anywhere, low bandwidth.</li></ul><div class="warning">⚠️ You confirm you are of <strong>legal age</strong> in your jurisdiction. Content may be adult. No warranty, no recourse.</div><div class="tog-group"><button class="tog-btn" id="togStats" data-lbl="📊 Performance" onclick="togRow(this)">📊 Performance · OFF</button><button class="tog-btn" id="togTrace" data-lbl="🔬 Trace" onclick="togRow(this)">🔬 Trace · OFF</button><div class="hd-row"><button class="tog-btn" id="togHd" data-lbl="🔉 High Definition" onclick="togRow(this)">🔉 High Definition · OFF</button><a href="https://matias.ma/nsfw/" target="_blank" rel="noopener" class="tog-btn adult">🔞 Adult Definition ;)</a></div><button class="tog-btn network" id="networkBtn">Help the Network</button><button class="tog-btn donate" id="donateBtn">♡ Donate</button></div><button class="btn-enter" id="acceptDisclaimerBtn">✓ I AGREE — ENTER</button></div></div>
<div id="donateModal"><div id="donateBox"><button id="donateDismiss" title="close">×</button><span class="d-glyph">♡</span><p class="d-desc">one .php file. drop it on any PHP host.<br>no build step. no dependencies. it just runs.</p><a class="d-dl" href="index.php?src=1" onclick="event.stopPropagation()">↓ download nosignup.php</a><div class="d-addr d-btc" onclick="_copyAddr(this)"><span class="d-addr-label">BITCOIN · electrum</span>bc1qqnu6n0jztxl4f6krv7klradghle09uhyu7uymz</div><div class="d-addr d-xmr" onclick="_copyAddr(this)"><span class="d-addr-label">MONERO · feather</span>8Ab24DppUvcdtHfm7K8gTqdBTmPCBiak1GwxgPm1C3osYVQL2QdC1C8GMwggKF77RKKzDgP2R8E3VH8ifetsKms5AqkVyVg</div><div class="d-addr d-ltc" onclick="_copyAddr(this)"><span class="d-addr-label">LITECOIN · electrum-ltc</span>ltc1qlpdy8qzejcmjdn6vwarpyz8djdlk780w4qkwyp</div><hr class="d-rule"><p class="d-footer">click any address to copy · close anywhere outside<br><br>$1M buys the domain and one year of my time.<br>I mean it.</p></div></div>
<div id="networkModal"><div id="networkBox"><button id="networkDismiss" title="close">×</button><span class="d-glyph">+</span><p class="d-desc">Drop in this file</p><a class="d-dl" href="index.php?src=1" onclick="event.stopPropagation()">↓ download full index.php</a><p class="d-desc">in to any php enabled hosting, than give us its IP; and it'll be added to the list of mirrors!</p><input id="mirrorInput" class="d-input" type="text" autocomplete="off" placeholder="https://mirror.example/index.php or 1.2.3.4"><button id="mirrorSubmit" class="d-submit" type="button">Submit</button><p id="mirrorStatus" class="d-status"></p><hr class="d-rule"><p class="d-footer">saved locally for the next gossip pass · close anywhere outside</p></div></div>
<script>
(function(){'use strict';

// ===== BLOCK 2: JS CONFIG + STATE =====
//   IN : localStorage deviceId
//   OUT: globals (FRAME_MS, PLAY_RUNWAY, UPLOAD_MS, FETCH_MS, SILENCE_*, MY_BASE, API()) · panels{L,R} state · buildTickPayload()
const DEVICE_KEY = 'nosignup_device_id';
let deviceId = null;
try { deviceId = localStorage.getItem(DEVICE_KEY); } catch (_) {}
if (!deviceId) {
  deviceId = (crypto && crypto.randomUUID)
    ? crypto.randomUUID().replace(/-/g, '').slice(0, 16)
    : (Math.random().toString(36).slice(2, 10) + Date.now().toString(36)).slice(0, 16);
  try { localStorage.setItem(DEVICE_KEY, deviceId); } catch (_) {}
}
const MY_BASE = deviceId;
const API     = r => `index.php?api=${r}&_d=${encodeURIComponent(deviceId)}`;
function makeSid(){
  if (crypto && crypto.getRandomValues) {
    const a = new Uint8Array(8); crypto.getRandomValues(a);
    return [...a].map(b => b.toString(16).padStart(2, '0')).join('');
  }
  return (Math.random().toString(36).slice(2) + Date.now().toString(36)).slice(0, 16);
}
const PAGE_SID = makeSid();
const PX_W=80, PX_H=60;
const PIXEL_DAMAGE_MS = 30000;   // pixel game: one accepted hit blacks that outgoing capture cell for 30s
const PIXEL_HIT_LINGER_MS = 5000; // repeat the tiny tail event briefly so one dropped blob does not lose it
const FRAME_MS    = 256;  // spec: 9 frames × 256ms = 2304ms blob duration, matches audio chunk size
const PLAY_RUNWAY = 8;    // latency/smoothness knob: deepest useful target inside the 9-cell blob
const SWAP_GAP_FRAMES = 48; // backward HEAD jump beyond this means sender swap/reload, not reorder
const MATCH_HEAD_FORWARD_FRAMES = 96; // first post-match blob must still be near the matched _S head
const MATCH_ALIAS_GRACE_MS = 4096; // post-match _S grace: lets the other browser see us before base unlinks _S
const UPLOAD_MS   = 1024;  // 4×FRAME_MS — send cadence on the frame grid so head advances ~+4 per upload (was 1000)
const UPLOAD_ACK_CUTOFF_MS = UPLOAD_MS - 16; // sub-cadence ACK cutoff: 1008ms when UPLOAD_MS is 1024
const JPEG_Q = 0.50;        // fixed quality; do not chase bytes with adaptive quality
const FETCH_MS    = 1024;  // Match UPLOAD_MS on the 4-frame grid; 1000ms polls drift ahead 24ms/tick.
const FETCH_PHASE_NUDGE_MS = FRAME_MS / 2; // repeated no-fresh responses shift phase, never adds a GET
const CHAT_LINGER_MS = 60000;
const SILENCE_ORPHAN_MS = 9000;      // first-blob handshake grace; still shows black/silence while waiting
const SILENCE_ESTABLISHED_MS = 9000; // no-fresh-frame budget for established peers; stale frames never replay
const panelToIdx = {L:1, R:2};
// Per-panel element lookup: $p('chatInput', 'L') → #chatInput1. Folds the getElementById +
// panelToIdx id-construction boilerplate repeated across the UI handlers into one place.
const $p = (prefix, panel) => document.getElementById(prefix + panelToIdx[panel]);
let _frameHead = 0;
let _traceEnabled = false;
let _hdMode       = false;
let _lastUploadAt = 0;
const TEXT_ENCODER = new TextEncoder();
const TEXT_DECODER = new TextDecoder();

// ===== TICK PAYLOAD =====
// Builds the spritesheet + audio for one upload.
async function buildTickPayload() {
  // Snapshot _frameHead BEFORE async encode — captureFrame runs on its own setInterval and
  // may increment _frameHead during the await, making head= in the tail run ahead of the
  // actual newest frame in the spritesheet.
  const headSnap = _frameHead;
  const audioBuf = getAudioChunk();
  // blobMs is total encode wall time; enc carries the stage breakdown.
  const _e0 = performance.now();
  const { videoArr, t } = await createSpritesheet();   // videoArr = JPEG ArrayBuffer
  const blobMs = Math.round(performance.now() - _e0);
  return { videoArr, audioBuf, headSnap, blobMs, enc: t };
}

function makePanelState(panel){
  return {
    panel, peerId: `${MY_BASE}_${panel}`, remoteId: null, targetId: null, remoteSid: '', remoteMatchHead: 0,
    matchAliasUntil: 0,
    seeking: false, active: false, fetchTimer: null, fetchDelayTimer: null,
    epoch: 0, // increments on every channel lifecycle change; stale async fetch/POST callbacks self-drop
    chatLastTs: 0, outgoingMsg: '', outgoingMsgTs: 0, outgoingBridge: null, outgoingPixelHits: [],
    silence: 0, matchedAt: 0, lastFreshAt: 0,
    audioPlayedAbs: null, remoteCanvas: null, _remoteCtx: null,
    _fetchInflight: 0, _disconnecting: false,
    // timeline jitter buffer
    timeline: [],        // [{firstAbs, bitmap, audio, sr}] — firstAbs = sender frame idx of cell 0
    playAnchor: null,    // {abs, time} maps absolute sender frame → performance.now() wall clock
    lastDrawnAbs: null,  // highest abs index ever DRAWN as a real frame — forward-only guard: a
                         // re-anchor may never land at or below it, so no frame is ever shown twice
    _tlRunning: false,   // timeline loop active
    _tlTimer: null,      // setTimeout handle for next frame boundary
    rxCount: 0, lastRxAt: 0, blackTotal: 0, blackEvents: 0, _wasBlack: false, _blackStart: 0,
    _lastBest: null, minLead: 999, audioGlitches: 0, maxDrx: 0
  };
}
const panels = { L: makePanelState('L'), R: makePanelState('R') };
let bridgeActive = false;
let uploadTimer  = null;   // setTimeout handle for the next upload pass (paced off prep)
let _uploadLooping = false;
let _postInflight = false;  // true while a POST is on the wire — single-flight guard for the fire-and-forget upload
const confirmState = {L:false, R:false, Ltimer:null, Rtimer:null};

function matchAliasActive(s, now = Date.now()){
  return !!(s && s.remoteId && s.matchAliasUntil && now < s.matchAliasUntil);
}
function publishPeerId(s, now = Date.now()){
  return (s.seeking || matchAliasActive(s, now)) ? (s.peerId + '_S') : s.peerId;
}
function retireMatchAlias(s){
  if (!s || !s.remoteId) return;
  s.matchAliasUntil = 0;
  s.targetId = s.remoteId;
}
function currentFetchTarget(s, now = Date.now()){
  if (!s) return null;
  if (s.seeking) return s.targetId;
  if (s.remoteId && s.targetId !== s.remoteId) retireMatchAlias(s);
  if (s.remoteId && !matchAliasActive(s, now)) retireMatchAlias(s);
  return s.targetId;
}

// ===== BLOCK 3: UTILITIES =====
//   IN : DOM chat containers
//   OUT: log() chat append · small DOM/element helpers
function log(panel, msg, type){
  const d = document.createElement('div');
  d.textContent = msg;
  d.style.color = type==='self' ? '#8af' : type==='peer' ? '#af8' : '#eee';
  const el = document.getElementById(panel==='L' ? 'chatMessages1' : 'chatMessages2');
  el.appendChild(d);
  el.scrollTop = el.scrollHeight;
}
// ===== BLOCK 4: FORENSIC SPRITESHEET TRACE =====
//   IN : trace events from hot paths · tail strings
//   OUT: trace()/_flushTrace() (buffered ~4Hz, no media-path work) · parseTail()/buildTail() (tail codec) · setStatus() · ape()
// Logs TX/RX/PLAY/BLACK lifecycle per blob into the chat feed. Opt-in, default OFF.
// Colors/styling live in the .trln CSS classes; trace() buffers, _flushTrace() renders 4×/sec.
const _T0 = performance.now();
function _ts(){ const d=new Date(); return d.toTimeString().slice(0,8)+'.'+String(d.getMilliseconds()).padStart(3,'0')+' +'+((performance.now()-_T0)/1000).toFixed(2)+'s'; }
const _traceBuf = { L: [], R: [] };
// Hot path: trace() only buffers; _flushTrace() batches DOM work 4x/sec.
function trace(panel, msg, kind){
  if (!_traceEnabled) return;
  _traceBuf[panel].push(msg + '\x00' + (kind || ''));   // pure push, no DOM, no cap
}
function _flushTrace(){
  for (const panel of ['L', 'R']){
    const buf = _traceBuf[panel];
    if (!buf.length) continue;
    const el = document.getElementById(panel === 'L' ? 'chatMessages1' : 'chatMessages2');
    if (!el) { buf.length = 0; continue; }
    const frag = document.createDocumentFragment();
    for (const entry of buf){
      const sep = entry.indexOf('\x00');
      const d = document.createElement('div');
      d.textContent = entry.slice(0, sep);
      d.className = 'trln ' + entry.slice(sep + 1);   // styled by class, parsed once — not per line
      frag.appendChild(d);
    }
    buf.length = 0;
    el.appendChild(frag);              // ONE DOM insertion for the whole batch
    // Bound node count so long trace sessions stay cheap.
    while (el.childElementCount > 2500) el.removeChild(el.firstChild);
    el.scrollTop = el.scrollHeight;    // ONE scroll per panel per flush
  }
}
setInterval(_flushTrace, 250);
function setStatus(panel, label, klass){
  const el = $p('statusBadge', panel);
  if (!el) return;
  el.textContent = label;
  el.className = 'status-badge' + (klass ? ' s-' + klass : '');
}
function updateToggleBtn(panel){
  const s = panels[panel];
  const btn = $p('btnToggle', panel);
  if (!btn) return;
  if (confirmState[panel]) btn.textContent = 'Are you sure?';
  else if (s.remoteId) btn.textContent = 'Find new peer';
  else if (s.seeking || s.active) btn.textContent = 'Stop Searching';
  else btn.textContent = 'Find Peer';
}
function parseTail(tailStr){
  const kv = {};
  if (!tailStr) return kv;
  for (const line of tailStr.split('\n')) {
    const eq = line.indexOf('=');
    if (eq > 0) kv[line.slice(0, eq)] = line.slice(eq + 1);
  }
  return kv;
}
function currentPixelGrid(){
  // Default is the live capture grid. Future temporary supporter rows should change this
  // together with the encoder/playback dimensions, not hard-code 80x60 in the game path.
  return { w: PX_W, h: PX_H };
}
function clampInt(v, min, max){
  v = Math.floor(+v);
  if (!Number.isFinite(v)) v = min;
  return Math.max(min, Math.min(max, v));
}
function encodePixelHit(hit){
  if (!hit) return '';
  const g = currentPixelGrid();
  const id = String(hit.id || '').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 40);
  if (!id) return '';
  return [
    id,
    clampInt(hit.x, 0, Math.max(0, g.w - 1)),
    clampInt(hit.y, 0, Math.max(0, g.h - 1)),
    Math.max(1, clampInt(hit.w || g.w, 1, 4096)),
    Math.max(1, clampInt(hit.h || g.h, 1, 4096))
  ].join(',');
}
function encodePixelHits(hits){
  if (!Array.isArray(hits) || !hits.length) return '';
  return hits.slice(-8).map(encodePixelHit).filter(Boolean).join(';');
}
function parsePixelHit(raw){
  const p = String(raw || '').split(',');
  if (p.length < 5) return null;
  const id = p[0].replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 40);
  const w = clampInt(p[3], 1, 4096), h = clampInt(p[4], 1, 4096);
  if (!id || w < 1 || h < 1) return null;
  return { id, x: clampInt(p[1], 0, w - 1), y: clampInt(p[2], 0, h - 1), w, h };
}
const _pixelDamage = new Map();      // "x,y" -> {x,y,until}; applied to our outgoing capture
const _pixelSeen = [], _pixelSeenSet = new Set();
function rememberPixelHit(id){
  if (_pixelSeenSet.has(id)) return false;
  _pixelSeenSet.add(id);
  _pixelSeen.push(id);
  while (_pixelSeen.length > 128) _pixelSeenSet.delete(_pixelSeen.shift());
  return true;
}
function receivePixelHit(panel, raw){
  for (const part of String(raw || '').split(';')) {
    const hit = parsePixelHit(part);
    if (!hit || !rememberPixelHit(hit.id)) continue;
    const x = clampInt(hit.x * PX_W / hit.w, 0, PX_W - 1);
    const y = clampInt(hit.y * PX_H / hit.h, 0, PX_H - 1);
    _pixelDamage.set(x + ',' + y, { x, y, until: Date.now() + PIXEL_DAMAGE_MS });
    if (_traceEnabled) trace(panel, `${_ts()}   PIXEL rx id=${hit.id} grid=${hit.w}x${hit.h} -> ${x},${y} ttl=${PIXEL_DAMAGE_MS}ms`, 'info');
  }
}
function applyPixelDamage(ctx){
  if (!_pixelDamage.size) return;
  const now = Date.now();
  ctx.save();
  ctx.fillStyle = '#000';
  for (const [key, d] of Array.from(_pixelDamage.entries())) {
    if (!d || d.until <= now) { _pixelDamage.delete(key); continue; }
    ctx.fillRect(d.x, d.y, 1, 1);
  }
  ctx.restore();
}
function armPixelHit(panel, ev){
  const s = panels[panel];
  if (!s || !s.remoteId || !s.remoteCanvas) return;
  const r = s.remoteCanvas.getBoundingClientRect();
  if (!r.width || !r.height) return;
  const g = currentPixelGrid();
  const x = clampInt((ev.clientX - r.left) * g.w / r.width, 0, g.w - 1);
  const y = clampInt((ev.clientY - r.top) * g.h / r.height, 0, g.h - 1);
  s.outgoingPixelHits.push({
    id: Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8),
    x, y, w: g.w, h: g.h, ts: Date.now()
  });
  while (s.outgoingPixelHits.length > 8) s.outgoingPixelHits.shift();
  if (_traceEnabled) trace(panel, `${_ts()}   PIXEL tx ${x},${y} grid=${g.w}x${g.h} -> ${s.remoteId.slice(0,8)}`, 'info');
}
function buildTail(s, headVal){
  const lines = ['head=' + headVal, 'sid=' + PAGE_SID];
  if (_hdMode) lines.push('sr=' + AUDIO_SR);
  if (s.outgoingMsg) {
    lines.push('msg=' + s.outgoingMsg.replace(/[\r\n]/g, ' '));
    lines.push('msg_ts=' + s.outgoingMsgTs);
  }
  const px = encodePixelHits(s.outgoingPixelHits);
  if (px) lines.push('px_hit=' + px);
  if (s.outgoingBridge) lines.push('bridge_target=' + s.outgoingBridge);
  return lines.join('\n') + '\n';
}
function ape(ctx, w, h){
  const img = ctx.getImageData(0,0,w,h), d = img.data;
  for (let i=0; i<d.length; i+=4) {
    d[i]   = Math.min(255, Math.max(0, 1.3*(d[i]  -128)+128+8));
    d[i+1] = Math.min(255, Math.max(0, 1.3*(d[i+1]-128)+128));
    d[i+2] = Math.min(255, Math.max(0, 1.3*(d[i+2]-128)+128-10));
  }
  ctx.putImageData(img, 0, 0);
}

// ===== BLOCK 5: VIDEO CAPTURE & SPRITESHEET =====
//   IN : _rawVid camera stream
//   OUT: captureFrame() (~4Hz -> _frameBuf + 3x3 sheet, advances _frameHead) · createSpritesheet()
//        (composes sheet on main thread, encodes JPEG in a Worker/OffscreenCanvas off the main thread;
//         main-thread synchronous fallback) -> ArrayBuffer
let _stream = null, _rawVid = null, _fallbackVid = null, _fallbackTimer = null, _fallbackCanvas = null;
const _frameBuf = [];
const _fbCanvas = document.createElement('canvas'); _fbCanvas.width = PX_W; _fbCanvas.height = PX_H;
const _fbCtx = _fbCanvas.getContext('2d', {willReadFrequently:true});
const _sheetCanvas = document.createElement('canvas'); _sheetCanvas.width = PX_W*3; _sheetCanvas.height = PX_H*3;
const _sheetCtx = _sheetCanvas.getContext('2d', {willReadFrequently:true});  // read back once/upload to hand to the encode worker
const _previewCtxs = [];   // local self-preview canvases — painted with the SAME _fbCanvas frame we send,
                           // so the preview is exactly what the peer receives (FOV, posterize, 256ms rate).
// Plain FRAME_MS timer: ~4 wakeups/sec, not a 60Hz rAF loop.
function captureFrame(){
  const v = _rawVid;
  if (!v) return;
  const _cpuT0 = performance.now();
  try {
    if (v instanceof HTMLCanvasElement) {
      _fbCtx.drawImage(v, 0, 0, PX_W, PX_H);
    } else {
      if (v.readyState < 2 || !v.videoWidth) return;
      const cropW = Math.floor(v.videoWidth  * 0.75);
      const cropH = Math.floor(v.videoHeight * 0.75);
      const offX  = Math.floor((v.videoWidth  - cropW) / 2);
      const offY  = Math.floor((v.videoHeight - cropH) / 2);
      _fbCtx.drawImage(v, offX, offY, cropW, cropH, 0, 0, PX_W, PX_H);
    }
    ape(_fbCtx, PX_W, PX_H);
    applyPixelDamage(_fbCtx);
    _frameBuf.push(_fbCtx.getImageData(0, 0, PX_W, PX_H));
    if (_frameBuf.length > 9) _frameBuf.shift();
    _frameHead++;
    // Local self-preview = the exact frame we just sent. Same crop, posterize, size, and 256ms rate.
    for (const p of _previewCtxs) if (p) p.drawImage(_fbCanvas, 0, 0);
  } catch (_) {}
  dbgPushSafe(dbg.cpuRoll, performance.now() - _cpuT0);
}
setInterval(captureFrame, FRAME_MS);
// Off-main-thread JPEG encode. Fallback is synchronous toDataURL, never toBlob callback delivery.
const _ENC_WORKER_SRC = `
let _ocv=null,_octx=null;
self.onmessage=async (e)=>{
  const d=e.data;
  try{
    const t0=performance.now();
    if(!_ocv||_ocv.width!==d.w||_ocv.height!==d.h){ _ocv=new OffscreenCanvas(d.w,d.h); _octx=_ocv.getContext('2d'); }
    _octx.putImageData(new ImageData(new Uint8ClampedArray(d.buf),d.w,d.h),0,0);
    const t1=performance.now();
    const b=await _ocv.convertToBlob({type:'image/jpeg',quality:d.q});
    const ab=await b.arrayBuffer();
    const t2=performance.now();
    self.postMessage({id:d.id,ab:ab,wdraw:Math.round(t1-t0),wenc:Math.round(t2-t1)},[ab]);
  }catch(err){ self.postMessage({id:d.id,err:String(err)}); }
};
`;
let _encWorker = null;          // Worker once created
let _encWorkerUrl = '';
let _encState  = 'init';        // 'init' -> first use tries to spin it up | 'on' | 'off' (fallback)
let _encReason = '';
let _encId     = 0;
const _encPending = new Map();   // id -> {res,rej}
function _encOff(reason){
  const w = _encWorker, url = _encWorkerUrl;
  _encState = 'off';
  _encReason = reason || 'off';
  _encWorker = null;
  _encWorkerUrl = '';
  try { if (w) w.terminate(); } catch (_) {}
  try { if (url) URL.revokeObjectURL(url); } catch (_) {}
  return null;
}
function _encWorkerGet(){
  if (_encState === 'on')  return _encWorker;
  if (_encState === 'off') return null;
  try {
    if (typeof Worker === 'undefined') return _encOff('no Worker');
    if (typeof OffscreenCanvas === 'undefined') return _encOff('no OffscreenCanvas');
    if (typeof OffscreenCanvas.prototype.convertToBlob !== 'function') return _encOff('no OffscreenCanvas.convertToBlob');
    let url = API('encworker');
    _encWorkerUrl = '';
    try {
      _encWorker = new Worker(url);
    } catch (_) {
      // Offline/file fallback only; live hosts with strict CSP should use same-origin api=encworker.
      url = URL.createObjectURL(new Blob([_ENC_WORKER_SRC], {type:'application/javascript'}));
      _encWorkerUrl = url;
      _encWorker = new Worker(url);
    }
    _encWorker.onmessage = (e) => {
      const m = e.data, p = _encPending.get(m.id);
      if (!p) return;
      _encPending.delete(m.id);
      m.err ? p.rej(new Error(m.err)) : p.res(m);   // resolve with whole message: {ab, wdraw, wenc}
    };
    // Worker failure falls through to sync fallback for this blob and the rest of the session.
    _encWorker.onerror = (ev) => {
      // Reason differentiates worker script bugs from CSP/blob-worker blocking.
      const where = ev && (ev.lineno || ev.filename) ? ' @' + (ev.filename || 'blob') + ':' + (ev.lineno || 0) + ':' + (ev.colno || 0) : '';
      _encOff('worker error' + (ev && ev.message ? ': ' + ev.message : ' (no message; likely CSP/blob-worker blocked)') + where);
      for (const p of _encPending.values()) p.rej(new Error(_encReason));
      _encPending.clear();
    };
    _encState = 'on';
    return _encWorker;
  } catch (e) { return _encOff('worker init exception' + (e && e.message ? ': ' + e.message : '')); }
}
function _composeSheet(){
  for (let i=0; i<9; i++) {
    const f = _frameBuf[Math.min(i, _frameBuf.length-1)];
    const col = i%3, row = Math.floor(i/3);
    if (f) _sheetCtx.putImageData(f, col*PX_W, row*PX_H);
    else { _sheetCtx.fillStyle='#111'; _sheetCtx.fillRect(col*PX_W, row*PX_H, PX_W, PX_H); }
  }
}
// Returns JPEG bytes plus timing: compose/read on main, wdraw/wenc in worker, q = delivery overhead.
async function createSpritesheet(){
  const _c0 = performance.now();
  _composeSheet();
  const _c1 = performance.now();
  const ew = _encWorkerGet();
  if (ew) {
    const id  = ++_encId;
    try {
      const img = _sheetCtx.getImageData(0, 0, _sheetCanvas.width, _sheetCanvas.height);
      const buf = img.data.buffer;
      const _c2 = performance.now();
      const pr  = new Promise((res, rej) => _encPending.set(id, {res, rej}));
      ew.postMessage({id, w:_sheetCanvas.width, h:_sheetCanvas.height, buf, q:JPEG_Q}, [buf]);
      const msg = await pr;                                  // {ab, wdraw, wenc}
      const rt  = Math.round(performance.now() - _c2);
      const wdraw = msg.wdraw|0, wenc = msg.wenc|0;
      return { videoArr: msg.ab, t: { path:'wrk', compose:Math.round(_c1-_c0), read:Math.round(_c2-_c1),
               rt, wdraw, wenc, q: Math.max(0, rt - (wdraw + wenc)) } };
    } catch (e) {
      _encPending.delete(id);
      if (_encState !== 'off') _encOff('worker encode error' + (e && e.message ? ': ' + e.message : ''));
    }
  }
  const _f0 = performance.now();
  const url = _sheetCanvas.toDataURL('image/jpeg', JPEG_Q);
  const comma = url.indexOf(',');
  const bin = atob(comma >= 0 ? url.slice(comma + 1) : url);
  const ab = new ArrayBuffer(bin.length);
  const u8 = new Uint8Array(ab);
  for (let i=0; i<bin.length; i++) u8[i] = bin.charCodeAt(i);
  return { videoArr: ab, t: { path:'main', compose:Math.round(_c1-_c0), read:0,
           rt:0, wdraw:0, wenc:Math.round(performance.now()-_f0), q:0, reason:_encReason || 'fallback' } };
}

// ===== BLOCK 6: MEDIA =====
//   IN : getUserMedia camera/mic cascade
//   OUT: _stream, _rawVid · setupLocalPreview() · fallbackStream()
async function getMedia(){
  if (_stream) return _stream;
  if (_rawVid) return null; // existing fallback source is already usable; explicit retry clears it
  const A = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
  const cascade = [
    {video:{facingMode:'user', width:{ideal:320}, height:{ideal:240}}, audio:A},
    {video:{facingMode:'environment', width:{ideal:320}, height:{ideal:240}}, audio:A},
    {video:true, audio:A},
    {video:true, audio:false}
  ];
  let stream = null;
  for (const c of cascade) {
    try { stream = await navigator.mediaDevices.getUserMedia(c); break; }
    catch(e) {}
  }
  if (!stream) {
    log('L', '❌ Camera blocked — fallback active', 'info');
    return fallbackStream();
  }
  _stream = stream;
  const vid = document.createElement('video');
  vid.autoplay = true; vid.muted = true; vid.playsInline = true;
  vid.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0.01;pointer-events:none;z-index:-1';
  document.body.appendChild(vid);
  vid.srcObject = stream;
  _rawVid = vid;
  try { await vid.play(); } catch (_) {}
  log('L', '📷 Camera ready', 'info');
  return stream;
}
function fallbackStream(){
  const cv = document.createElement('canvas'); cv.width = PX_W; cv.height = PX_H;
  const cx = cv.getContext('2d'); let f = 0;
  _fallbackCanvas = cv;
  _fallbackTimer = setInterval(() => {
    cx.fillStyle = '#221133'; cx.fillRect(0, 0, PX_W, PX_H);
    cx.fillStyle = '#ffaa88'; cx.font = 'bold 10px monospace'; cx.textAlign = 'center';
    cx.fillText('CAMERA', PX_W/2, 28);
    cx.fillStyle = '#ddaa66'; cx.font = '8px monospace';
    cx.fillText('BLOCKED', PX_W/2, 46);
    if ((f++ % 30) < 15) { cx.fillStyle = '#ff6600'; cx.fillRect(PX_W-10, PX_H-10, 8, 8); }
  }, 200);
  let fb = null;
  try {
    if (typeof cv.captureStream === 'function') fb = cv.captureStream(1000 / FRAME_MS);
  } catch (_) { fb = null; }
  if (fb) {
    const fv = document.createElement('video');
    fv.srcObject = fb; fv.muted = true; fv.playsInline = true; fv.autoplay = true;
    const pp = fv.play();
    if (pp && pp.catch) pp.catch(() => {});
    _rawVid = fv; _fallbackVid = fv;
  } else {
    _rawVid = cv; _fallbackVid = null;
  }
  ['L','R'].forEach(panel => {
    const container = $p('localVideoContainer', panel);
    container.querySelectorAll('.retry-cam-btn').forEach(b => b.remove());
    const btn = document.createElement('button');
    btn.textContent = '📷 Allow Camera';
    btn.className = 'retry-cam-btn';
    btn.style.cssText = 'position:absolute;bottom:8px;left:50%;transform:translateX(-50%);background:hsl(var(--hue) 85% 55%);border:0;border-radius:20px;padding:4px 10px;font-size:10px;cursor:pointer;z-index:15;font-family:monospace;font-weight:bold;color:#000;white-space:nowrap';
    btn.onclick = async (e) => {
      e.stopPropagation();
      btn.textContent = '…'; btn.disabled = true;
      _stream = null;
      if (_fallbackTimer) { clearInterval(_fallbackTimer); _fallbackTimer = null; }
      if (_fallbackVid) {
        try { _fallbackVid.srcObject.getTracks().forEach(t => t.stop()); } catch (_) {}
        _fallbackVid.srcObject = null; _fallbackVid.remove(); _fallbackVid = null;
      }
      _rawVid = null;
      _fallbackCanvas = null;
      await getMedia();
      if (_stream) {
        reinitMic();
        document.querySelectorAll('.retry-cam-btn').forEach(b => b.remove());
        log('L', '✅ Camera recovered', 'info');
      } else { btn.textContent = '📷 Allow Camera'; btn.disabled = false; }
    };
    container.appendChild(btn);
  });
  return fb;
}
function setupLocalPreview(panel){
  const c = $p('localVideoContainer', panel);
  const badge = c.querySelector('.status-badge');
  c.querySelectorAll('.local-preview-canvas').forEach(n => n.remove());
  const cv = document.createElement('canvas');
  cv.className = 'local-preview-canvas px-canvas';
  cv.width = PX_W; cv.height = PX_H;
  cv.style.cssText = 'width:100%;height:100%;display:block;image-rendering:pixelated;object-fit:contain';
  c.appendChild(cv);
  if (badge && badge.parentNode !== c) c.appendChild(badge);
  const ctx = cv.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  ctx.fillStyle = '#000'; ctx.fillRect(0, 0, PX_W, PX_H);
  ctx.fillStyle = '#aaa'; ctx.font = '6px monospace'; ctx.textAlign = 'center';
  ctx.fillText('starting…', PX_W/2, PX_H/2);
  // No render loop: captureFrame() paints the exact outgoing frame here.
  _previewCtxs[panelToIdx[panel]] = ctx;
}

// ===== BLOCK 7: AUDIO =====
//   IN : _stream mic -> AudioWorklet (ScriptProcessor fallback)
//   OUT: getAudioChunk() (9x u-law, for upload) · playAudioChunk() (RX) · pcm16<->mulaw codec
let audioCtx = null, _micSrc = null, _micProc = null, _micSink = null, _micStarting = null, _audioChunks = [];
let _micMode = 'none', _micGen = 0, _micWorkletCtx = null, _micWorkletBad = false;
// Default 8kHz. HD mode switches to 16kHz at disclaimer accept (before audio context creation).
// Both values are let so the HD toggle can update them before wakeAudio()/ensureMic() fire.
// Audio encoded as G.711 μ-law uint8 — 1 byte per sample (half the size of int16, same perceived quality).
let AUDIO_SR         = 8000;
let AUDIO_CHUNK_BYTES = 2048; // 2048 samples × 1 byte (μ-law); 4096 at 16kHz
const AUDIO_CHUNKS   = 9;
async function wakeAudio(){
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)({sampleRate:AUDIO_SR});
    if (audioCtx.state === 'suspended') {
      await Promise.race([
        audioCtx.resume().catch(() => {}),
        new Promise(res => setTimeout(res, 250))
      ]);
    }
  } catch (_) {}
}
// G.711 μ-law encode/decode — 1 byte per sample, perceptually lossless for voice
function pcm16ToMulaw(s) {
  const sign = s < 0 ? 0x80 : 0;
  if (sign) s = -s;
  s = Math.min(s + 132, 32767);
  let exp = 7, mask = 0x4000;
  while (exp > 0 && !(s & mask)) { exp--; mask >>= 1; }
  return (~(sign | (exp << 4) | ((s >> (exp + 3)) & 0x0F))) & 0xFF;
}
function mulawToPcm16(u) {
  u = ~u & 0xFF;
  const sign = u & 0x80, exp = (u & 0x70) >> 4, mant = u & 0x0F;
  const s = (((mant << 1) | 0x21) << (exp + 2)) - 132;
  return sign ? -s : s;
}
const _MIC_WORKLET_NAME = 'nosignup-ulaw-capture';
const _MIC_WORKLET_SRC = `
class NosignupUlawCapture extends AudioWorkletProcessor {
  constructor(o) {
    super();
    const p = (o && o.processorOptions) || {};
    this.targetRate = p.targetRate || 8000;
    this.chunkBytes = p.chunkBytes || 2048;
    this.ratio = sampleRate / this.targetRate;
    this.next = 0;
    this.acc = [];
  }
  enc(v) {
    let s = Math.max(-32768, Math.min(32767, v * 32768)) | 0;
    let sign = s < 0 ? 128 : 0;
    if (sign) s = -s;
    s = Math.min(s + 132, 32767);
    let exp = 7, mask = 16384;
    while (exp > 0 && !(s & mask)) { exp--; mask >>= 1; }
    return (~(sign | (exp << 4) | ((s >> (exp + 3)) & 15))) & 255;
  }
  emit(b) {
    this.acc.push(b);
    while (this.acc.length >= this.chunkBytes) {
      const out = new Uint8Array(this.acc.splice(0, this.chunkBytes));
      this.port.postMessage({buf: out.buffer}, [out.buffer]);
    }
  }
  process(inputs, outputs) {
    const out = outputs[0] && outputs[0][0];
    if (out) out.fill(0);
    const inp = inputs[0] && inputs[0][0];
    if (!inp) return true;
    const ratio = this.ratio > 0 ? this.ratio : (sampleRate / this.targetRate);
    let pos = this.next;
    while (pos < inp.length) {
      this.emit(this.enc(inp[Math.floor(pos)] || 0));
      pos += ratio;
    }
    this.next = pos - inp.length;
    return true;
  }
}
registerProcessor('${_MIC_WORKLET_NAME}', NosignupUlawCapture);
`;
function pushAudioChunk(buf){
  _audioChunks.push(new Uint8Array(buf));
  if (_audioChunks.length > AUDIO_CHUNKS) _audioChunks.splice(0, _audioChunks.length - AUDIO_CHUNKS);
}
async function wireAudioWorklet(src, gen){
  if (_micWorkletBad || !audioCtx?.audioWorklet || typeof AudioWorkletNode === 'undefined') return false;
  try {
    if (_micWorkletCtx !== audioCtx) {
      const url = URL.createObjectURL(new Blob([_MIC_WORKLET_SRC], {type:'application/javascript'}));
      try { await audioCtx.audioWorklet.addModule(url); }
      finally { URL.revokeObjectURL(url); }
      _micWorkletCtx = audioCtx;
    }
    if (gen !== _micGen) return false;
    const node = new AudioWorkletNode(audioCtx, _MIC_WORKLET_NAME, {
      numberOfInputs: 1, numberOfOutputs: 1, outputChannelCount: [1],
      processorOptions: {targetRate: AUDIO_SR, chunkBytes: AUDIO_CHUNK_BYTES}
    });
    node.port.onmessage = e => { if (e.data && e.data.buf) pushAudioChunk(e.data.buf); };
    const sink = audioCtx.createGain(); sink.gain.value = 0;
    src.connect(node); node.connect(sink); sink.connect(audioCtx.destination);
    _micSrc = src; _micProc = node; _micSink = sink; _micMode = 'worklet';
    return true;
  } catch (_) {
    if (gen === _micGen) _micWorkletBad = true;
    try { src.disconnect(); } catch (_) {}
    return false;
  }
}
function wireScriptProcessor(src, gen){
  if (gen !== _micGen) return false;
  // ScriptProcessor is fallback only. It runs on the main thread, so keep the work tiny and
  // still resample against the context's ACTUAL rate before emitting 256ms u-law chunks.
  const proc = audioCtx.createScriptProcessor(4096, 1, 1);
  let acc = [], next = 0;
  proc.onaudioprocess = e => {
    const inp = e.inputBuffer.getChannelData(0);
    const ratio = (audioCtx.sampleRate || AUDIO_SR) / AUDIO_SR;
    let pos = next;
    while (pos < inp.length) {
      const v = inp[Math.floor(pos)] || 0;
      acc.push(pcm16ToMulaw(Math.max(-32768, Math.min(32767, v * 32768)) | 0));
      pos += ratio;
    }
    next = pos - inp.length;
    while (acc.length >= AUDIO_CHUNK_BYTES) pushAudioChunk(Uint8Array.from(acc.splice(0, AUDIO_CHUNK_BYTES)).buffer);
  };
  const sink = audioCtx.createGain(); sink.gain.value = 0;
  src.connect(proc); proc.connect(sink); sink.connect(audioCtx.destination);
  _micSrc = src; _micProc = proc; _micSink = sink; _micMode = 'script';
  return true;
}
function disconnectMic(){
  _micGen++;
  if (_micProc && _micProc.port) { try { _micProc.port.onmessage = null; } catch (_) {} }
  if (_micSrc)  { try { _micSrc.disconnect(); }  catch (_) {} }
  if (_micProc) { try { _micProc.disconnect(); } catch (_) {} }
  if (_micSink) { try { _micSink.disconnect(); } catch (_) {} }
  _micSrc = _micProc = _micSink = null;
  _micStarting = null;
  _audioChunks = [];
  _micMode = 'none';
}
function ensureMic(){
  if (_micSrc || _micStarting) return _micStarting || Promise.resolve();
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)({sampleRate:AUDIO_SR});
  if (!_stream || _stream.getAudioTracks().length === 0) { _micMode = 'none'; return Promise.resolve(); }
  const src = audioCtx.createMediaStreamSource(_stream);
  const gen = _micGen;
  const task = (async () => {
    _audioChunks = [];
    const worked = await wireAudioWorklet(src, gen);
    if (gen !== _micGen) { try { src.disconnect(); } catch (_) {} return; }
    if (!worked) wireScriptProcessor(src, gen);
  })().catch(() => {
    if (gen === _micGen) { _micMode = 'none'; dbg._wErr++; }
    try { src.disconnect(); } catch (_) {}
  });
  _micStarting = task;
  task.finally(() => { if (_micStarting === task) _micStarting = null; });
  return task;
}
function reinitMic(){
  disconnectMic();
  ensureMic();
}
function getAudioChunk(){
  // The wire format is fixed: 9 audio chunks ride every blob. During startup or after a mic
  // switch, pad missing older chunks with u-law silence instead of emitting a partial audio body
  // that the receiver must ignore.
  const chunkBytes = AUDIO_CHUNK_BYTES;
  const need = AUDIO_CHUNKS * chunkBytes;
  const combined = new Uint8Array(need);
  combined.fill(0xFF); // u-law silence
  const chunks = _audioChunks.slice(-AUDIO_CHUNKS);
  let off = need - chunks.length * chunkBytes;
  for (const c of chunks) {
    if (c.length === chunkBytes) combined.set(c, off);
    else if (c.length > chunkBytes) combined.set(c.subarray(c.length - chunkBytes), off);
    else combined.set(c, off + chunkBytes - c.length);
    off += chunkBytes;
  }
  return combined.buffer;
}
// Play one 256ms u-law chunk at its video frame's Web Audio clock position.
function playAudioChunk(mu, sr, whenCtx){
  if (!audioCtx || !mu || mu.length === 0) return;
  const buf = audioCtx.createBuffer(1, mu.length, sr || AUDIO_SR);
  const ch  = buf.getChannelData(0);
  for (let i=0; i<mu.length; i++) ch[i] = mulawToPcm16(mu[i]) / 32768;
  const src = audioCtx.createBufferSource();
  src.buffer = buf; src.connect(audioCtx.destination);
  src.start(Math.max(whenCtx, audioCtx.currentTime));
}

// ===== BLOCK 8: PACK BLOB =====
//   IN : video JPEG + audio u-law + tail string
//   OUT: packBlob() -> [4 vLen][jpeg][4 aLen][audio][tail] Uint8Array (the wire blob)
async function packBlob(videoArr, audioBuf, tailStr){
  const vLen = videoArr.byteLength, aLen = audioBuf.byteLength;
  const tailArr = TEXT_ENCODER.encode(tailStr || '');
  const out = new Uint8Array(4 + vLen + 4 + aLen + tailArr.length);
  const dv  = new DataView(out.buffer);
  dv.setUint32(0, vLen, false);
  out.set(new Uint8Array(videoArr), 4);
  dv.setUint32(4 + vLen, aLen, false);
  out.set(new Uint8Array(audioBuf), 4 + vLen + 4);
  out.set(tailArr, 4 + vLen + 4 + aLen);
  return new Blob([out], {type:'application/octet-stream'});
}

// ===== BLOCK 9: DEBUG OVERLAY =====
//   IN : dbg.* counters (written by blocks 10/11/12)
//   OUT: createDebugOverlay()/renderDebug() · dbg{} store · dbgPush/dbgAvg
const dbg = {
  upLastMs: 0, upRoll: [], upOkRoll: [],
  prepLastMs: 0, upQueueMs: null, upCodeMs: null, upWrites: null, upStore: null,  // upload-stall breakdown
  fetchLastMs: 0, fetchRoll: [], fetchLastStatus: 0,
  fetchStatus: {L:0, R:0},
  lastSize:    {L:0, R:0},
  jpegBytes:   {L:0, R:0},
  blobAge:     {L:0, R:0},
  lastHead:    {L:0, R:0},
  connectedAt:    {L:0, R:0},
  disconnects:    {L:0, R:0},
  lastDisconnect: {L:'—', R:'—'},
  cpuRoll: [],
  encPath: '—', encReason: '', encRoll: [], encQRoll: [],   // encode path/reason, worker compute roll, delivery-overhead roll
  _wErr: 0, _wTimeout: 0, _wStart: Date.now(),
  sessionStart: Date.now()
};
function dbgPush(arr, v){ arr.unshift(v); if (arr.length > 10) arr.pop(); }
function dbgPushSafe(arr, v){ dbgPush(arr, Math.round(v * 10) / 10); }
function dbgAvg(arr){ if (!arr.length) return 0; return Math.round(arr.reduce((a,b)=>a+b,0) / arr.length); }
let _dbgEl = null, _dbgEnabled = false;
function createDebugOverlay(){
  if (_dbgEl) return;
  _dbgEl = document.createElement('div');
  _dbgEl.id = 'dbgOverlay';
  _dbgEl.style.cssText = 'position:fixed;top:8px;right:8px;background:rgba(0,0,0,.82);color:#0f0;font:11px/1.4 monospace;padding:7px 10px;border-radius:8px;z-index:9999;pointer-events:auto;cursor:pointer;white-space:pre;backdrop-filter:blur(4px);border:1px solid #0f05;letter-spacing:.02em';
  _dbgEl.title = 'click to dim';
  let hidden = false;
  _dbgEl.onclick = () => { hidden = !hidden; _dbgEl.style.opacity = hidden ? '0.25' : '1'; };
  document.body.appendChild(_dbgEl);
}
function renderDebug(){
  if (!_dbgEnabled || !_dbgEl) return;
  const L = panels.L, R = panels.R;
  const now = Date.now();
  const activePanels = ['L', 'R'].filter(p => panels[p].active);
  const uploadActive = activePanels.length > 0;

  // Windowed rates reset every 10s
  const elapsed = (now - dbg._wStart) / 1000;
  if (elapsed >= 10) { dbg._wErr=0; dbg._wTimeout=0; dbg._wStart=now; }
  const w   = Math.max(elapsed, 1);
  const eR   = (dbg._wErr / w * 10).toFixed(1);
  const toR  = (dbg._wTimeout / w * 10).toFixed(1);

  // Session age
  const sesS  = Math.round((now - dbg.sessionStart) / 1000);
  const sesStr= sesS < 60 ? sesS+'s' : Math.floor(sesS/60)+'m'+(sesS%60)+'s';

  // Audio context state
  const actxState = audioCtx ? audioCtx.state : 'none';
  const micOk     = !!_micSrc;
  const fbLen     = _frameBuf.length;

  const panelStr = (p, s) => {
    const state = s.seeking ? 'SEEKING' : s.remoteId ? 'CONN' : s.active ? 'ACTIVE' : 'idle';
    const connAge = (s.remoteId && dbg.connectedAt[p])
      ? Math.round((now - dbg.connectedAt[p]) / 1000) + 's'
      : '—';
    const blobAgeMs = dbg.blobAge[p]
      ? Math.round(now - dbg.blobAge[p]) + 'ms'
      : 'never';
    const totalBytes = dbg.lastSize[p];
    const jpegB  = dbg.jpegBytes[p];
    const audioB = totalBytes - jpegB - 8;
    const blobDetail = totalBytes
      ? `${totalBytes}B (jpeg=${jpegB}B audio=${Math.max(0,audioB)}B)`
      : 'none';
    const tqDepth = s.timeline ? s.timeline.length : 0;
    let leadNow = '—';
    if (s.timeline && s.timeline.length && s.playAnchor) {
      const wa = s.playAnchor.abs + Math.floor((performance.now() - s.playAnchor.time) / FRAME_MS);
      leadNow = (s.timeline[s.timeline.length - 1].firstAbs + 8 - wa) + 'f';
    }
    const minLeadStr = s.minLead === 999 ? '—' : s.minLead + 'f';
    const tgt    = (s.targetId || '—').slice(0, 12);
    const bridgeStr = s.outgoingBridge ? '→'+s.outgoingBridge.slice(0,8) : 'off';
    const discStr = dbg.disconnects[p];
    const fetchSt = dbg.fetchStatus[p] || '—';
    const since = dbg.lastHead[p] > 0 ? s.lastFreshAt : s.matchedAt;
    const silAge = s.silence && since ? Math.round((Date.now() - since) / 1000) + 's' : '0s';
    const discLine = discStr > 0 ? `\n  ${p} last_disconnect: ${dbg.lastDisconnect[p]}` : '';
    return (
      `  ${p} [${state}] connected=${connAge}  disconnects=${discStr}  target=${tgt}\n` +
      `  ${p} fetch_status=${fetchSt}  silence=${s.silence}/${silAge}  lastHead=${dbg.lastHead[p]}\n` +
      `  ${p} blob=${blobDetail}  age=${blobAgeMs}  tq=${tqDepth}  lead=${leadNow} (min ${minLeadStr}, runway ${PLAY_RUNWAY})\n` +
      `  ${p} gaps=${s.blackEvents} (${(s.blackTotal/1000).toFixed(1)}s)  audio_gaps=${s.audioGlitches}  maxΔrx=${s.maxDrx}ms\n` +
      `  ${p} bridge=${bridgeStr}` + discLine
    );
  };

  const line = '─'.repeat(44);
  const upLine = uploadActive
    ? `UP   last=${dbg.upLastMs}ms  avg=${dbgAvg(dbg.upRoll)}ms  cap_head=${_frameHead}  active=${activePanels.join(',')}  single-flight${dbgAvg(dbg.upRoll) > UPLOAD_MS ? ' ⚠link-limited' : ''}`
    : `UP   idle  active=0  wire=${_postInflight ? 'draining-old-post' : 'none'}  cap_head=${_frameHead}`;
  const upDetail = uploadActive
    ? `     prep=${dbg.prepLastMs}ms  post=${dbg.upLastMs}ms  (server queue=${dbg.upQueueMs ?? '—'}ms code=${dbg.upCodeMs ?? '—'}ms writes=${dbg.upWrites ?? '—'} store=${dbg.upStore ?? '—'})`
    : `     last_post=${dbg.upLastMs}ms  avg=${dbgAvg(dbg.upRoll)}ms  last_server=(queue=${dbg.upQueueMs ?? '—'}ms code=${dbg.upCodeMs ?? '—'}ms writes=${dbg.upWrites ?? '—'} store=${dbg.upStore ?? '—'})`;
  _dbgEl.textContent =
    `${line}\n` +
    `NOSIGNUP.CHAT  session=${sesStr}  clock=${new Date().toTimeString().slice(0,8)}  device=${MY_BASE.slice(0,8)}  buf=${panels.L.timeline.length}/${panels.R.timeline.length}\n` +
    `${line}\n` +
    upLine + `\n` +
    upDetail + `\n` +
    `     ENC  path=${dbg.encPath === 'wrk' ? 'worker(off-thread)' : dbg.encPath === 'main' ? 'main(SYNC FALLBACK)' : '—'}  reason=${dbg.encReason || '—'}  enc=${dbgAvg(dbg.encRoll)}ms  delivery_q=${dbgAvg(dbg.encQRoll)}ms${dbg.encPath === 'wrk' && dbgAvg(dbg.encQRoll) > 200 ? ' ⚠gate-moved-to-delivery' : ''}\n` +
    `     err/10s=${eR}  timeout/10s=${toR}\n` +
    `GET  last=${dbg.fetchLastMs}ms  avg=${dbgAvg(dbg.fetchRoll)}ms  last_status=${dbg.fetchLastStatus}\n` +
    `CPU  frame_cap=${dbgAvg(dbg.cpuRoll)}ms  framebuf=${fbLen}/9\n` +
    `AUD  ctx=${actxState}  mic=${micOk?'ok':'NO'}  path=${_micMode}  stream=${!!_stream}  sr=${AUDIO_SR}Hz\n` +
    `${line}\n` +
    panelStr('L', L) + '\n' +
    `${line}\n` +
    panelStr('R', R) + '\n' +
    `${line}\n` +
    `FRAME_MS=${FRAME_MS}  UPLOAD_MS=${UPLOAD_MS}  FETCH_MS=${FETCH_MS}  SILENCE=${SILENCE_ORPHAN_MS/1000}s/${SILENCE_ESTABLISHED_MS/1000}s  q=${JPEG_Q}  runway=${PLAY_RUNWAY}  bridge=${bridgeActive}`;
}
setInterval(renderDebug, 500);
window.dbg = dbg;
window._setTrace = (v) => { _traceEnabled = !!v; try { localStorage.setItem('nosignup_trace', _traceEnabled?'1':'0'); } catch(_){} return _traceEnabled; };

// ===== BLOCK 10: UPLOAD LOOP =====
//   IN : buildTickPayload, packBlob, getAudioChunk, panel.seeking/peerId
//   OUT: self-paced single-flight POST -> api=upload (sub-cadence ACK cutoff) · dbg.up* · starts seek fetch after POST attempt
// Prep-paced single-flight: one POST on wire; next pass scheduled off prep, not ACK.
async function uploadTick() {
  // Idle sends nothing; old server blobs expire naturally.
  const active = ['L', 'R'].filter(p => panels[p].active);
  if (active.length === 0) return;
  if (!_rawVid || (!(_rawVid instanceof HTMLCanvasElement) && !_rawVid.srcObject)) return;
  if (_postInflight) {
    dbg.prepLastMs = 0;
    if (_traceEnabled) for (const panel of active) trace(panel, `${_ts()}    ↑ PREP skipped (prev POST still on wire)`, 'tx');
    return;
  }
  const _prepT0 = performance.now();   // encode/pack cost (the unmeasured half of the upload pass)

  // ---- PREP (no gate held across await) ----
  let videoArr, audioBuf, headSnap, _blobMs = 0, _enc = null;
  try {
    ({ videoArr, audioBuf, headSnap, blobMs: _blobMs, enc: _enc } = await buildTickPayload());
  } catch (_) { dbg._wErr++; return; }
  // Record encode breakdown for the overlay (path + worker compute + delivery overhead).
  if (_enc) {
    dbg.encPath = _enc.path;
    dbg.encReason = _enc.reason || (_enc.path === 'wrk' ? 'ok' : '');
    dbgPush(dbg.encRoll, _enc.wenc);
    dbgPush(dbg.encQRoll, _enc.path === 'wrk' ? _enc.q : 0);
  }
  const nowTs = Date.now();

  let uploadUrl = API('upload'), uploadBody = null;
  const seekStart = [];
  try {
    // Build tails for all active panels (expire stale chat first)
    const tails = {};
    for (const panel of active) {
      const s = panels[panel];
      if (s.outgoingMsg && (nowTs - s.outgoingMsgTs) > CHAT_LINGER_MS) {
        s.outgoingMsg = ''; s.outgoingMsgTs = 0;
      }
      if (s.outgoingPixelHits && s.outgoingPixelHits.length) {
        s.outgoingPixelHits = s.outgoingPixelHits.filter(h => (nowTs - h.ts) <= PIXEL_HIT_LINGER_MS);
      }
      tails[panel] = buildTail(s, headSnap);
    }
    const sameTail = active.length === 1 || (active.length === 2 && tails.L === tails.R);
    const qs = new URLSearchParams();
    if (sameTail) {
      // One raw body, one or two destinations. No multipart parser on the PHP side.
      const blob = await packBlob(videoArr, audioBuf, tails[active[0]]);
      qs.set('mode', 'same');
      uploadBody = blob;
      for (const panel of active) {
        const s = panels[panel];
        qs.set('peerId' + panel, publishPeerId(s, nowTs));
        if (s.seeking && !s.fetchTimer && !s.fetchDelayTimer) seekStart.push({panel, epoch:s.epoch});
      }
    } else {
      // Tails differ (bridge/chat) — concatenate L/R blobs and let PHP slice by byte length.
      qs.set('mode', 'split');
      const parts = [];
      for (const panel of active) {
        const s = panels[panel];
        const blob = await packBlob(videoArr, audioBuf, tails[panel]);
        qs.set('peerId' + panel, publishPeerId(s, nowTs));
        qs.set('len' + panel, blob.size);
        parts.push(blob);
        if (s.seeking && !s.fetchTimer && !s.fetchDelayTimer) seekStart.push({panel, epoch:s.epoch});
      }
      uploadBody = new Blob(parts, {type:'application/octet-stream'});
    }
    uploadUrl += '&' + qs.toString();
  } catch (_) { dbg._wErr++; return; }

  if (_traceEnabled) {
    const nowP = performance.now();
    const dTx  = _lastUploadAt ? Math.round(nowP - _lastUploadAt) : 0;
    _lastUploadAt = nowP;
    for (const panel of active) {
      const s = panels[panel];
      const dest = publishPeerId(s, nowTs);
      const _e = _enc || {path:'?'};
      const _encStr = _e.path === 'wrk'
        ? `[wrk compose=${_e.compose} read=${_e.read} rt=${_e.rt} wenc=${_e.wenc} wdraw=${_e.wdraw} q=${_e.q}]`
        : `[main-sync compose=${_e.compose} enc=${_e.wenc} reason=${_e.reason || '?'}]`;
      trace(panel, `${_ts()} ↑ TX head=${headSnap} file=${dest.endsWith('_S') ? '_S' : 'base'} epoch=${s.epoch} jpeg=${videoArr.byteLength}b aud=${audioBuf.byteLength}b prep=${Math.round(nowP - _prepT0)}ms (blob=${_blobMs}) ${_encStr} Δtx=${dTx}ms → ${dest.slice(0,14)}`, 'tx');
    }
  }

  // ---- POST: fire-and-forget under single-flight guard. Cadence = max(prep, UPLOAD_MS).
  const _t0 = performance.now();
  dbg.prepLastMs = Math.round(_t0 - _prepT0);   // encode+pack time (always tracked, trace/debug independent)
  _postInflight = true;
  const ctrl = new AbortController();
  // Cut slow ACKs before the next send slot, but not inside normal POST tail.
  const _tmo = UPLOAD_ACK_CUTOFF_MS;
  const tm = setTimeout(() => ctrl.abort(), _tmo);
  let _postStatus = 'ok';
  fetch(uploadUrl, { method: 'POST', headers:{'Content-Type':'application/octet-stream'}, body: uploadBody, signal: ctrl.signal })
    .then(res => {
      if (!res || !res.ok) { dbg._wErr++; _postStatus = 'err' + (res ? res.status : ''); dbg.upQueueMs = dbg.upCodeMs = dbg.upWrites = null; }
      else {
        const q = res.headers.get('X-Upload-Queue-Ms'), c = res.headers.get('X-Upload-Code-Ms');
        const w = res.headers.get('X-Upload-Writes'),    st = res.headers.get('X-Sprite-Store');
        if (q !== null) dbg.upQueueMs = +q;
        if (c !== null) dbg.upCodeMs  = +c;
        if (w !== null) dbg.upWrites  = +w;
        if (st)         dbg.upStore   = st;
      }
    })
    .catch(e => {
      if (e && e.name === 'AbortError') { dbg._wTimeout++; _postStatus = 'timeout'; }
      else { dbg._wErr++; _postStatus = 'err'; }
      dbg.upQueueMs = dbg.upCodeMs = dbg.upWrites = null;   // no response read — unknown, not stale
    })
    .finally(() => {
      clearTimeout(tm);
      _postInflight = false;
      dbg.upLastMs = Math.round(performance.now() - _t0);   // post_ms = POST round-trip
      dbgPush(dbg.upRoll, dbg.upLastMs);
      if (_postStatus === 'ok') dbgPush(dbg.upOkRoll, dbg.upLastMs);
      // Per-POST attribution: queue=pre-PHP, code=write/KYC, neither=browser/socket path.
      if (_traceEnabled) {
        for (const panel of active) {
          trace(panel, `${_ts()}    ↑ POST post=${dbg.upLastMs}ms/tmo=${_tmo}ms (queue=${dbg.upQueueMs ?? '?'}ms code=${dbg.upCodeMs ?? '?'}ms) writes=${dbg.upWrites ?? '?'} store=${dbg.upStore ?? '?'} ${_postStatus}`, 'tx');
        }
      }
      for (const item of seekStart) {
        const s = panels[item.panel];
        if (s.seeking && s.epoch === item.epoch && !s.fetchTimer && !s.fetchDelayTimer) startFetch(item.panel);
      }
    });
}

// Loop paces off prep; _postInflight inside uploadTick owns single-flight.
function startUploadLoop() {
  if (uploadTimer || _uploadLooping) return;
  _uploadLooping = true;
  const pass = async () => {
    const t0 = performance.now();
    try { await uploadTick(); } catch (_) { dbg._wErr++; }
    uploadTimer = setTimeout(pass, Math.max(0, UPLOAD_MS - (performance.now() - t0)));
  };
  pass();
}

// ===== BLOCK 11: FETCH LOOP =====
//   IN : panel.targetId (own _S while seeking -> matched base when connected)
//   OUT: GET api=sprite · decode (createImageBitmap) -> panel.timeline by abs index · silence/teardown · sender-swap detect
// setInterval on the same 1024ms frame grid as upload; R staggers FETCH_MS/2 after L. One GET in
// flight per panel (minimize requests). The head-indexed receiver in _fetchTickBody drops any blob
// with no frame past our high-water, so arrival order never matters — nothing to defend against.
function nudgeFetchPhase(panel, s, why) {
  if (!s || s.seeking || !s.active || !s.fetchTimer) return;
  clearInterval(s.fetchTimer);
  s.fetchTimer = null;
  if (s.fetchDelayTimer) clearTimeout(s.fetchDelayTimer);
  s.fetchDelayTimer = setTimeout(() => {
    s.fetchDelayTimer = null;
    if (!s.targetId || !s.active || s.seeking) return;
    s.fetchTimer = setInterval(() => fetchTick(panel), FETCH_MS);
    fetchTick(panel);
  }, FETCH_MS + FETCH_PHASE_NUDGE_MS);
  if (_traceEnabled) trace(panel, `${_ts()}    GET phase +${FETCH_PHASE_NUDGE_MS}ms after ${why}`, 'miss');
}
function startFetch(panel) {
  const s = panels[panel];
  if (s.fetchTimer || s.fetchDelayTimer) return;
  const phase = panel === 'R' ? FETCH_MS / 2 : 0;
  s.fetchDelayTimer = setTimeout(() => {
    s.fetchDelayTimer = null;
    if (!s.targetId) return;
    s.fetchTimer = setInterval(() => fetchTick(panel), FETCH_MS);
    fetchTick(panel);
  }, phase);
}

async function fetchTick(panel) {
  const s = panels[panel];
  const targetId = currentFetchTarget(s);
  if (!targetId) return;
  if (s._fetchInflight >= 1) { return; }
  s._fetchInflight++;
  const fetchEpoch = s.epoch;

  const url = API('sprite') + '&peerId=' + encodeURIComponent(targetId);
  const ctrl = new AbortController();
  const tm = setTimeout(() => ctrl.abort(), 5000);
  let res;
  const _ft0 = performance.now();
  try {
    res = await fetch(url, { signal: ctrl.signal });
  } catch (_) {
    clearTimeout(tm);
    if (fetchEpoch === s.epoch) {
      s._fetchInflight = Math.max(0, s._fetchInflight - 1);
      if (!s.seeking) bumpSilence(panel, s, 'fetch');
    }
    return;
  }
  clearTimeout(tm);
  if (fetchEpoch !== s.epoch) return;
  dbg.fetchLastMs = Math.round(performance.now() - _ft0);
  dbg.fetchLastStatus = res.status;
  dbg.fetchStatus[panel] = res.status;
  dbgPush(dbg.fetchRoll, dbg.fetchLastMs);

  try { await _fetchTickBody(panel, s, res, fetchEpoch); }
  finally {
    if (fetchEpoch === s.epoch) s._fetchInflight = Math.max(0, s._fetchInflight - 1);
  }
}

// Single staleness mechanism. Any connected fetch with NO decoded new frame (404/204, fetch
// error, invalid blob, decode failure, or head not past high-water) bumps one counter for the
// overlay, but teardown is elapsed-time based so slow GETs/retries cannot stretch or burn the budget.
function bumpSilence(panel, s, why) {
  if (!s.active) return;
  s.silence++;
  const established = dbg.lastHead[panel] > 0;
  const now = Date.now();
  const since = established
    ? (s.lastFreshAt || dbg.connectedAt[panel] || now)
    : (s.matchedAt || dbg.connectedAt[panel] || now);
  const silentMs = now - since;
  const limitMs = established ? SILENCE_ESTABLISHED_MS : SILENCE_ORPHAN_MS;
  if (silentMs >= limitMs && !s._disconnecting) {
    s._disconnecting = true;
    const connAge = dbg.connectedAt[panel] ? Math.round((Date.now() - dbg.connectedAt[panel]) / 1000) + 's' : '?';
    dbg.lastDisconnect[panel] = `silence=${Math.round(silentMs)}ms ×${s.silence} via=${why} connected=${connAge}`;
    log(panel, established ? '👻 Peer disconnected — click Find Peer to search again' : '👻 Phantom match — re-searching…', 'info');
    resetChannel(panel, !established);   // re-seek if phantom, otherwise go idle
    return;
  }
  if (s.silence >= 2) nudgeFetchPhase(panel, s, why);
}

async function _fetchTickBody(panel, s, res, fetchEpoch) {
  if (fetchEpoch !== s.epoch || !s.active) return;
  if (s.seeking) {
    if (res.status === 200) {
      const matchPeer = res.headers.get('X-Match-Peer');
      if (matchPeer && matchPeer.length >= 8) {
        const matchSid = (res.headers.get('X-Match-Sid') || '').replace(/[^a-zA-Z0-9_-]/g, '');
        const matchHead = +(res.headers.get('X-Match-Head') || 0);
        const nowD = Date.now();
        s.remoteId = matchPeer;
        s.targetId = matchPeer;
        s.remoteSid = matchSid;
        s.remoteMatchHead = Number.isFinite(matchHead) ? matchHead : 0;
        s.matchAliasUntil = nowD + MATCH_ALIAS_GRACE_MS;
        s.seeking  = false;
        s.silence  = 0;
        s.matchedAt = nowD;
        s.lastFreshAt = 0;
        s._disconnecting = false;
        dbg.connectedAt[panel] = nowD;
        dbg.lastHead[panel] = 0;
        setStatus(panel, 'CONNECTED', 'connected');
        updateToggleBtn(panel);
        log(panel, '✅ Matched with ' + matchPeer.slice(0, 8));
        if (_traceEnabled && (matchSid || matchHead)) trace(panel, `${_ts()}    MATCH sid=${matchSid ? matchSid.slice(0,8) : 'none'} head=${s.remoteMatchHead || 'none'} publish_alias=${MATCH_ALIAS_GRACE_MS}ms`, 'info');
        $p('chatInput', panel).disabled = false;
        $p('sendBtn', panel).disabled = false;
        if (panels.L.remoteId && panels.R.remoteId) {
          document.getElementById('btnBridge').disabled = false;
        }
      }
    }
    return;
  }

  // No file on server (peer gone / file expired). Counts as silence — handled by the
  // single staleness check below, shared with the no-new-frame (stalled/late) case.
  if (res.status === 404 || res.status === 204) {
    if (_traceEnabled) trace(panel, `${_ts()} × RX ${res.status} silence=${s.silence + 1}`, 'miss');
    bumpSilence(panel, s, res.status);
    return;
  }
  if (!res.ok) { bumpSilence(panel, s, 'http' + res.status); return; }

  let buf;
  try { buf = await res.arrayBuffer(); } catch (_) { if (fetchEpoch === s.epoch) bumpSilence(panel, s, 'body'); return; }
  if (fetchEpoch !== s.epoch || !s.active) return;
  if (buf.byteLength < 8) { bumpSilence(panel, s, 'short'); return; }

  // Update size and age for overlay display.
  dbg.lastSize[panel] = buf.byteLength;
  dbg.blobAge[panel]  = Date.now();
  const dv   = new DataView(buf);
  const vLen = dv.getUint32(0, false);
  if (vLen <= 0 || 4 + vLen + 4 > buf.byteLength) { bumpSilence(panel, s, 'vlen'); return; }
  dbg.jpegBytes[panel] = vLen;
  const aLen = dv.getUint32(4 + vLen, false);
  const need = 4 + vLen + 4 + aLen;
  if (need > buf.byteLength) { bumpSilence(panel, s, 'alen'); return; }

  const videoData = buf.slice(4, 4 + vLen);
  const audioData = buf.slice(4 + vLen + 4, need);
  const tailStr = TEXT_DECODER.decode(new Uint8Array(buf, need));
  const kv      = parseTail(tailStr);
  if (kv.px_hit) receivePixelHit(panel, kv.px_hit);

  // Chat rides the tail, independent of video freshness — deliver before the frame gate so a
  // stalled/reordered blob still gets the message through. ts>chatLastTs keeps it idempotent.
  if (kv.msg && kv.msg_ts) {
    const ts = +kv.msg_ts;
    if (ts > s.chatLastTs) {
      s.chatLastTs = ts;
      log(panel, 'Peer: ' + kv.msg, 'peer');
      if (_traceEnabled) trace(panel, `${_ts()}   💬 CHAT rx "${kv.msg.slice(0,40)}"`, 'chat');
    }
  }

  // ===== HEAD-ANCHORED JITTER BUFFER =====
  // head = absolute index of the NEWEST frame (cell 8); firstAbs = cell 0. Frames schedule by
  // ABSOLUTE index, not arrival time, so playback is monotonic across overlapping blobs and arrival
  // order never matters. head dedups, schedules, and detects swaps client-side (server stays dumb).
  const head = kv.head ? +kv.head : 0;
  if (!head) { bumpSilence(panel, s, 'head'); return; }  // no frame index -> nothing to schedule
  const firstAbs = head - 8;
  const prevHead = dbg.lastHead[panel];

  // Match-generation guard: the first post-match blob must be the same stream generation as the `_S`
  // blob that produced X-Match-Peer. This rejects stale stable-peerId blobs before they can turn
  // a phantom match into an established ghost.
  const sid = (kv.sid || '').replace(/[^a-zA-Z0-9_-]/g, '');
  if (s.remoteSid && sid !== s.remoteSid) {
    if (_traceEnabled) trace(panel, `${_ts()} × SID ${sid ? sid.slice(0,8) : 'none'} != ${s.remoteSid.slice(0,8)} silence=${s.silence + 1}`, 'miss');
    bumpSilence(panel, s, 'sid');
    return;
  }
  if (!prevHead && s.remoteMatchHead > 0) {
    const low = Math.max(0, s.remoteMatchHead - SWAP_GAP_FRAMES);
    const high = s.remoteMatchHead + MATCH_HEAD_FORWARD_FRAMES;
    if (head < low || head > high) {
      if (_traceEnabled) trace(panel, `${_ts()} × MATCH-HEAD head=${head} expected=${low}..${high} silence=${s.silence + 1}`, 'miss');
      bumpSilence(panel, s, 'match-head');
      return;
    }
  }

  // LIVENESS + SENDER-SWAP — one decision, keyed on the absolute head index.
  if (prevHead && head <= prevHead) {
    if (head <= prevHead - SWAP_GAP_FRAMES) {
      // Big backward jump → sender changed (bridge redirect or peer reload) → re-anchor. A
      // near-equal-head swap instead reads as no-new below and self-heals via head-climb or reseek.
      if (_traceEnabled) trace(panel, `${_ts()} ↻ SENDER SWAP head=${head} ⟵ ${prevHead} — re-anchor`, 'info');
      clearTimeline(s);
      dbg.lastHead[panel] = 0;
      // fall through: accept this blob as the first frame of the new stream
    } else {
      // No new frame: re-served stalled blob or a reordered fire-and-forget blob → bump the ONE
      // silence counter and drop (a fresh blob next fetch resets it).
      if (_traceEnabled) trace(panel, `${_ts()} · no-new head=${head} ≤ ${prevHead} silence=${s.silence + 1} (${buf.byteLength}b — dropped)`, 'dup');
      bumpSilence(panel, s, 'stale');
      return;
    }
  }
  const nowP = performance.now();
  const dRx = s.lastRxAt ? Math.round(nowP - s.lastRxAt) : 0;
  s.lastRxAt = nowP;
  if (dRx > s.maxDrx) s.maxDrx = dRx;   // jitter tail — the worst arrival gap is what breaches runway
  if (_traceEnabled) {
    s.rxCount++;
    trace(panel, `${_ts()} ↓ RX#${s.rxCount} head=${head} firstAbs=${firstAbs} Δrx=${dRx}ms ${buf.byteLength}b`, 'rx');
  }

  let bitmap;
  try { bitmap = await createImageBitmap(new Blob([videoData], {type:'image/jpeg'})); }
  catch (_) { if (fetchEpoch === s.epoch) bumpSilence(panel, s, 'decode'); return; }
  if (fetchEpoch !== s.epoch || !s.active) {
    if (bitmap && bitmap.close) bitmap.close();
    return;
  }

  // New decoded frames (or first after a fresh match / swap) — peer is alive. Do this only after
  // JPEG decode succeeds; a torn blob with a valid tail must not advance high-water or reset liveness.
  s.silence = 0;
  s.lastFreshAt = Date.now();
  dbg.lastHead[panel] = head;

  // Prune blobs whose newest frame is already behind the play cursor.
  const wantAbs = s.playAnchor
    ? s.playAnchor.abs + Math.floor((nowP - s.playAnchor.time) / FRAME_MS)
    : firstAbs;
  pruneTimeline(s, wantAbs);
  s.timeline.push({firstAbs, bitmap, audio: (audioData && audioData.byteLength) ? new Uint8Array(audioData) : null, sr: kv.sr ? +kv.sr : AUDIO_SR});
  s.timeline.sort((a, b) => a.firstAbs - b.firstAbs);

  // Train-on-rails re-anchor: free-run on jitter; skip forward only on first/underrun/runaway/swap.
  // Forward-only prevents replay; true outage still blanks.
  const newestAbs = s.timeline[s.timeline.length - 1].firstAbs + 8;
  const target = newestAbs - PLAY_RUNWAY;
  if (!s.playAnchor) {
    s.playAnchor = { abs: target, time: nowP };
  } else {
    const cur  = s.playAnchor.abs + Math.floor((nowP - s.playAnchor.time) / FRAME_MS);
    const lead = newestAbs - cur;                       // rails laid ahead of the train
    if (lead < 0 || lead > PLAY_RUNWAY * 2) {
      const floor = (s.lastDrawnAbs == null) ? target : s.lastDrawnAbs + 1;
      s.playAnchor = { abs: Math.max(target, floor), time: nowP };   // skip forward, never replay
    }
  }

  ensureRemoteCanvas(panel);
  if (audioCtx && audioCtx.state === 'suspended') { try { audioCtx.resume(); } catch (_) {} }
  if (!s._tlRunning) startTimeline(panel);
}

function ensureRemoteCanvas(panel){
  const s = panels[panel];
  if (s.remoteCanvas && document.body.contains(s.remoteCanvas)) return;
  const ct = $p('remoteVideoContainer', panel);
  ct.innerHTML = '';
  ct.style.position = 'relative';
  const cv = document.createElement('canvas');
  cv.className = 'px-canvas'; cv.width = PX_W; cv.height = PX_H;
  cv.style.cssText = 'width:100%;height:100%;display:block;image-rendering:auto;object-fit:contain;filter:saturate(1.4) contrast(1.05)';
  cv.addEventListener('click', e => armPixelHit(panel, e));
  ct.appendChild(cv);
  // Vignette overlay — pure CSS, zero cost
  const vg = document.createElement('div');
  vg.style.cssText = 'position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse at center,transparent 55%,rgba(0,0,0,.55) 100%)';
  ct.appendChild(vg);
  s.remoteCanvas = cv;
  s._remoteCtx = cv.getContext('2d');
  s._remoteCtx.imageSmoothingEnabled = true;
  s._remoteCtx.imageSmoothingQuality = 'medium';
}

// ===== TIMELINE HELPERS =====
// Single source of truth for timeline cleanup.
function clearTimeline(s){
  for (const e of s.timeline) { if (e.bitmap && e.bitmap.close) e.bitmap.close(); }
  s.timeline = [];
  s._lastBest = null;
  s.playAnchor = null;
  s.audioPlayedAbs = null;
  s.lastDrawnAbs = null;
}
function pruneTimeline(s, wantAbs){
  s.timeline = s.timeline.filter(e => {
    if (e.firstAbs + 8 < wantAbs - 1) { if (e.bitmap && e.bitmap.close) e.bitmap.close(); return false; }
    return true;
  });
}

// ===== BLOCK 12: PLAYBACK TIMELINE LOOP =====
//   IN : panel.timeline (frames keyed by absolute index)
//   OUT: 256ms-grid cursor -> draws newest covering cell + bonds/plays audio once · BLANK+silence on underrun
// Wakes on the 256ms frame grid, not 60fps. Gaps blank; arrival re-anchors.
function startTimeline(panel) {
  const s = panels[panel];
  if (s._tlRunning) return;
  s._tlRunning = true;
  _tlTick(panel);
}
function _tlTick(panel) {
  const s = panels[panel];
  if (!s._tlRunning) return;
  const now = performance.now();
  const ctx = s._remoteCtx;
  if (!s.timeline.length || !s.playAnchor) { s._tlRunning = false; return; }

  // playAnchor is established and resynced on blob arrival (see _fetchTickBody).
  // Here we only advance the wall-clock cursor and draw the covering frame.
  const wantAbs = s.playAnchor.abs + Math.floor((now - s.playAnchor.time) / FRAME_MS);

  // Pick newest blob covering wantAbs (redundant overlap → freshest image).
  let best = null;
  for (const e of s.timeline) {
    if (e.firstAbs <= wantAbs && wantAbs <= e.firstAbs + 8) best = e;
  }

  // Jitter-buffer LEAD: frames the newest buffered frame sits ahead of the cursor — the headroom
  // PLAY_RUNWAY is meant to hold. It drains toward 0 before any gap, so it's the leading indicator.
  const newestAbs = s.timeline.length ? s.timeline[s.timeline.length - 1].firstAbs + 8 : wantAbs;
  const lead = newestAbs - wantAbs;
  if (lead < s.minLead) s.minLead = lead;

  if (ctx) {
    if (best) {
      const cell = wantAbs - best.firstAbs;       // 0..8
      ctx.drawImage(best.bitmap, (cell%3)*PX_W, Math.floor(cell/3)*PX_H, PX_W, PX_H, 0, 0, PX_W, PX_H);
      s.lastDrawnAbs = wantAbs;                    // forward-only guard: re-anchor can't land ≤ this
      if (_traceEnabled && best !== s._lastBest) {
        trace(panel, `${_ts()}   ▶ PLAY head=${best.firstAbs + 8} lead=${lead}f tq=${s.timeline.length} abs=${wantAbs} cell=${cell}`, 'play');
        s._lastBest = best;
      }
      // Bond audio to absolute frame index; overlap never replays a chunk.
      if (audioCtx && best.audio && best.audio.length >= AUDIO_CHUNKS * AUDIO_CHUNK_BYTES) {
        if (s.audioPlayedAbs == null || wantAbs > s.audioPlayedAbs + 1) {
          // First chunk or discontinuity: re-anchor audio and count audible dropout.
          if (s.audioPlayedAbs != null) {
            s.audioGlitches++;
            if (_traceEnabled) trace(panel, `${_ts()}   🔇 AUDIO gap (re-anchor #${s.audioGlitches}) abs=${wantAbs} ⟵ played=${s.audioPlayedAbs}`, 'black');
          }
          s.audioPlayedAbs = wantAbs - 1;
        }
        const ctxNow = audioCtx.currentTime;
        const schedTo = Math.min(best.firstAbs + 8, wantAbs + 2);  // small look-ahead, seamless but crisp on resync
        for (let abs = s.audioPlayedAbs + 1; abs <= schedTo; abs++) {
          const idx = abs - best.firstAbs;          // which chunk in this blob (0..8)
          if (idx < 0 || idx >= AUDIO_CHUNKS) continue;
          const chunk = best.audio.subarray(idx * AUDIO_CHUNK_BYTES, (idx + 1) * AUDIO_CHUNK_BYTES);
          const whenCtx = ctxNow + (abs - wantAbs) * (FRAME_MS / 1000);  // frame offset → seconds
          playAudioChunk(chunk, best.sr, whenCtx);
          s.audioPlayedAbs = abs;
        }
      }
    } else {
      // Cursor past the newest buffered frame: peer hasn't delivered the next frame yet.
      // Blank per spec ("Exhausted with no new blob → blank/silence, no loop"). Arrival re-anchors.
      ctx.clearRect(0, 0, PX_W, PX_H);
    }
  }

  // Prune blobs fully behind the cursor.
  pruneTimeline(s, wantAbs);

  // Gap trace
  if (_traceEnabled) {
    const isBlack = !best;
    if (isBlack && !s._wasBlack) { s._wasBlack = true; s._blackStart = now; }
    if (!isBlack && s._wasBlack) {
      const dur = Math.round(now - s._blackStart);
      s.blackTotal += dur; s.blackEvents++;
      trace(panel, `${_ts()} ⬛ GAP ${dur}ms ended (total ${(s.blackTotal/1000).toFixed(1)}s over ${s.blackEvents})`, 'black');
      s._wasBlack = false;
    }
  }

  if (!s.timeline.length) { s._tlRunning = false; return; }
  // Wake at next frame boundary relative to the anchor.
  const sinceAnchor = now - s.playAnchor.time;
  const msToNext = FRAME_MS - (((sinceAnchor % FRAME_MS) + FRAME_MS) % FRAME_MS);
  s._tlTimer = setTimeout(() => _tlTick(panel), Math.max(1, msToNext));
}
function enterMatchQueue(panel){
  const s = panels[panel];
  s.epoch++;
  s._fetchInflight = 0;
  s.active   = true;
  s.seeking  = true;
  s.remoteId = null;
  s.remoteSid = ''; s.remoteMatchHead = 0; s.matchAliasUntil = 0;
  s.targetId = s.peerId + '_S';
  s.silence  = 0;
  setStatus(panel, 'SEARCHING', 'searching');
  updateToggleBtn(panel);
  log(panel, '🔍 Searching…', 'info');
  // startFetch is called after the first POST attempt containing this panel's _S filename
  // completes or hits its ACK deadline, so the first poll does not race ahead of publication.
}
function resetChannel(panel, reEnter){
  const s = panels[panel];
  if (bridgeActive) toggleBridge(true);
  if (_traceEnabled) {
    const was = s.seeking ? 'seeking' : s.remoteId ? 'connected' : s.active ? 'active' : 'idle';
    trace(panel, `${_ts()}    RESET reEnter=${reEnter ? 1 : 0} epoch=${s.epoch}->${s.epoch + 1} was=${was} target=${(s.targetId || 'none').slice(0,14)}`, 'info');
  }
  s.epoch++;
  if (s.fetchDelayTimer) { clearTimeout(s.fetchDelayTimer); s.fetchDelayTimer = null; }
  if (s.fetchTimer) { clearInterval(s.fetchTimer); s.fetchTimer = null; }
  s._tlRunning = false;
  if (s._tlTimer) { clearTimeout(s._tlTimer); s._tlTimer = null; }
  clearTimeline(s);
  s.remoteId = null; s.targetId = null; s.remoteSid = ''; s.remoteMatchHead = 0; s.matchAliasUntil = 0;
  s.seeking = false; s.active = false; s.silence = 0; s.matchedAt = 0; s.lastFreshAt = 0;
  s.outgoingMsg = ''; s.outgoingMsgTs = 0; s.outgoingBridge = null; s.outgoingPixelHits = []; s.chatLastTs = 0;
  s._disconnecting = false; s._remoteCtx = null; s._fetchInflight = 0;
  s.rxCount = 0; s.lastRxAt = 0; s.blackTotal = 0; s.blackEvents = 0; s.minLead = 999; s.audioGlitches = 0; s.maxDrx = 0;
  s._wasBlack = false; s._blackStart = 0;
  dbg.fetchStatus[panel] = 0;
  dbg.lastSize[panel] = 0;
  dbg.jpegBytes[panel] = 0;
  dbg.blobAge[panel] = 0;
  dbg.lastHead[panel]  = 0;
  dbg.connectedAt[panel] = 0;
  const ct = $p('remoteVideoContainer', panel);
  ct.innerHTML = '<span class="peer-placeholder">Peer ' + panelToIdx[panel] + ' appears here</span>';
  s.remoteCanvas = null;
  const ci = $p('chatInput', panel);
  ci.value = ''; ci.disabled = true;
  $p('sendBtn', panel).disabled = true;
  const bb = document.getElementById('btnBridge');
  bb.disabled = true; bb.classList.remove('active'); bb.innerHTML = '🔗';
  setStatus(panel, 'IDLE');
  updateToggleBtn(panel);
  if (!reEnter) dbg.disconnects[panel]++;
  if (reEnter) enterMatchQueue(panel);
}
function toggleBridge(forceOff){
  if (!forceOff && (!panels.L.remoteId || !panels.R.remoteId)) return;
  bridgeActive = forceOff ? false : !bridgeActive;
  const btn = document.getElementById('btnBridge');
  if (bridgeActive) {
    panels.L.outgoingBridge = panels.R.remoteId;
    panels.R.outgoingBridge = panels.L.remoteId;
    btn.innerHTML = '🔗✨'; btn.classList.add('active');
    btn.title = '🔗 Bridge active — peers see each other, you watch silently';
    setStatus('L', 'BRIDGING', 'bridging'); setStatus('R', 'BRIDGING', 'bridging');
    log('L', '🔗 Bridge: peers now see each other'); log('R', '🔗 Bridge: peers now see each other');
  } else {
    panels.L.outgoingBridge = null; panels.R.outgoingBridge = null;
    btn.innerHTML = '🔗'; btn.classList.remove('active');
    btn.title = '🔗 Bridge inactive';
    if (panels.L.remoteId) setStatus('L', 'CONNECTED', 'connected');
    if (panels.R.remoteId) setStatus('R', 'CONNECTED', 'connected');
    log('L', '🔗 Bridge OFF: original feeds restored'); log('R', '🔗 Bridge OFF: original feeds restored');
  }
}
function sendChat(panel){
  const s = panels[panel];
  if (!s.remoteId) return;
  const inp = $p('chatInput', panel);
  const msg = inp.value.trim();
  if (!msg) return;
  s.outgoingMsg   = msg;
  s.outgoingMsgTs = Date.now();
  log(panel, 'You: ' + msg, 'self');
  if (_traceEnabled) trace(panel, `${_ts()}   💬 CHAT tx "${msg.slice(0,40)}"`, 'chat');
  inp.value = '';
}

// ===== BLOCK 13: DEVICE SWITCHERS + SVG LINES =====
//   IN : enumerateDevices · bridge state
//   OUT: camera/mic switch handlers · bridge SVG connector lines
let _deviceListenersAdded = false;
function addDeviceSwitchers(){
  const cluster = document.getElementById('deviceCluster');
  cluster.innerHTML = '';
  const make = (icon, label) => {
    const w = document.createElement('div'); w.style.position = 'relative';
    const b = document.createElement('button'); b.className = 'dev-btn'; b.innerHTML = icon;
    b.title = label; b.setAttribute('aria-label', label);
    const m = document.createElement('div'); m.className = 'dev-menu';
    w.appendChild(b); w.appendChild(m);
    return {w, b, m};
  };
  const cam = make('📷', 'Switch camera');
  const mic = make('🎤', 'Switch microphone');
  cluster.appendChild(cam.w); cluster.appendChild(mic.w);
  const flip = which => e => {
    e.stopPropagation();
    document.querySelectorAll('.dev-menu').forEach(x => { if (x !== which) x.style.display = 'none'; });
    which.style.display = which.style.display === 'flex' ? 'none' : 'flex';
  };
  cam.b.onclick = flip(cam.m); mic.b.onclick = flip(mic.m);
  (async function populate(){
    if (!navigator.mediaDevices?.enumerateDevices) return;
    const devs = await navigator.mediaDevices.enumerateDevices();
    const vids = devs.filter(d => d.kind === 'videoinput');
    const auds = devs.filter(d => d.kind === 'audioinput');
    const swap = async (kind, deviceId) => {
      if (!_stream) return;
      const c = kind === 'video'
        ? {video:{deviceId}, audio:true}
        : {video:true, audio:{deviceId}};
      const fr = await navigator.mediaDevices.getUserMedia(c);
      const newT  = kind === 'video' ? fr.getVideoTracks()[0]  : fr.getAudioTracks()[0];
      const keepT = kind === 'video' ? _stream.getAudioTracks()[0] : _stream.getVideoTracks()[0];
      const out = new MediaStream();
      if (kind === 'video') { if (newT) out.addTrack(newT); if (keepT) out.addTrack(keepT); }
      else                  { if (keepT) out.addTrack(keepT); if (newT) out.addTrack(newT); }
      _stream = out; if (_rawVid) _rawVid.srcObject = out;
      if (kind === 'video' && _fallbackTimer) { clearInterval(_fallbackTimer); _fallbackTimer = null; _fallbackVid = null; }
      reinitMic();
      log('L', kind === 'video' ? '🔄 Switched camera' : '🔄 Switched mic');
    };
    vids.forEach((d,i) => { const b=document.createElement('button'); b.textContent=d.label||('Camera '+(i+1)); b.onclick=async()=>{cam.m.style.display='none'; await swap('video', d.deviceId);}; cam.m.appendChild(b); });
    auds.forEach((d,i) => { const b=document.createElement('button'); b.textContent=d.label||('Mic '+(i+1));    b.onclick=async()=>{mic.m.style.display='none'; await swap('audio', d.deviceId);}; mic.m.appendChild(b); });
    if (!vids.length) cam.m.innerHTML = '<div style="padding:8px;color:#aaa">No cameras</div>';
    if (!auds.length) mic.m.innerHTML = '<div style="padding:8px;color:#aaa">No mics</div>';
  })();
  if (!_deviceListenersAdded) {
    document.addEventListener('click', () => {
      document.querySelectorAll('.dev-menu').forEach(m => m.style.display = 'none');
    });
    _deviceListenersAdded = true;
  }
}
function initSvgLines(){
  const svg = document.querySelector('svg.banner-image'); if (!svg) return;
  const colors = ['#ff4d6d','#ffb84d','#ffff4d','#4dff88','#4da6ff','#b84dff','#ff4da6','#ff884d','#88ff4d','#4dffb8','#b84dff','#ff4dff'];
  const rand = a => a[Math.floor(Math.random()*a.length)];
  const nodes = [];
  for (let i=0; i<7; i++) { const el = document.getElementById('node'+i); if (el) nodes.push(el); }
  const pairs = [[0,1],[1,2],[2,3],[3,4],[4,5],[5,6],[6,0],[0,2],[1,3],[2,4],[3,5],[4,6],[5,0],[6,1],[0,3],[1,4],[2,5],[3,6],[4,0],[5,1],[6,2]];
  const sg = svg.querySelector('#starGroup') || svg;
  pairs.forEach(([a,b]) => {
    const n1 = nodes[a], n2 = nodes[b]; if (!n1 || !n2) return;
    const ln = document.createElementNS('http://www.w3.org/2000/svg','line');
    ln.setAttribute('x1', n1.getAttribute('cx')); ln.setAttribute('y1', n1.getAttribute('cy'));
    ln.setAttribute('x2', n2.getAttribute('cx')); ln.setAttribute('y2', n2.getAttribute('cy'));
    ln.setAttribute('stroke', rand(colors));
    ln.setAttribute('stroke-width', (0.5 + Math.random()*1).toFixed(2));
    const an = document.createElementNS('http://www.w3.org/2000/svg','animate');
    const op = (0.2 + Math.random()*0.4).toFixed(2);
    an.setAttribute('attributeName', 'opacity');
    an.setAttribute('values', (op*0.3).toFixed(2)+';'+op+';'+(op*0.3).toFixed(2));
    an.setAttribute('dur', (3 + Math.random()*5).toFixed(1) + 's');
    an.setAttribute('begin', (Math.random()*4).toFixed(1) + 's');
    an.setAttribute('repeatCount', 'indefinite');
    ln.appendChild(an); sg.appendChild(ln);
  });
}

// ===== BLOCK 14: BINDINGS + DISCLAIMER FLOW =====
//   IN : DOM events (accept, find-peer, toggles, donate/network)
//   OUT: wires UI -> state · togRow/_getTog/_setTog · on accept: start capture/audio/upload
// Spec: "from first camera/mic grant" — start uploading the moment camera is available.
// Load all prefs FIRST so audio context is created at the correct sample rate for returning users.
window.togRow = btn => { const on=btn.classList.toggle('on'); btn.textContent=btn.dataset.lbl+' · '+(on?'ON':'OFF'); };
const _setTog=(id,v)=>{const b=document.getElementById(id);if(!b)return;b.classList.toggle('on',!!v);b.textContent=b.dataset.lbl+' · '+(v?'ON':'OFF');};
const _getTog=id=>!!document.getElementById(id)?.classList.contains('on');
try {
  _setTog('togStats',localStorage.getItem('nosignup_show_stats')==='1');
  _setTog('togTrace',localStorage.getItem('nosignup_trace')==='1');
  _hdMode=localStorage.getItem('nosignup_hd')==='1';
  _setTog('togHd',_hdMode);
  if(_hdMode){AUDIO_SR=16000;AUDIO_CHUNK_BYTES=4096;}
} catch (_) {}
getMedia().then(() => {
  ensureMic();
  startUploadLoop();
}).catch(() => {}); // new user — no permission yet, granted at disclaimer accept
document.getElementById('acceptDisclaimerBtn').onclick = async () => {
  const want = _getTog('togStats');
  try { localStorage.setItem('nosignup_show_stats', want ? '1' : '0'); } catch (_) {}
  _dbgEnabled = want;
  if (want) createDebugOverlay();
  _traceEnabled = _getTog('togTrace');
  try { localStorage.setItem('nosignup_trace', _traceEnabled ? '1' : '0'); } catch (_) {}
  if (_traceEnabled) { log('L','🔬 Trace ON — TX/RX/PLAY/BLACK below','info'); log('R','🔬 Trace ON — TX/RX/PLAY/BLACK below','info'); }
  const wantHd = _getTog('togHd');
  try { localStorage.setItem('nosignup_hd', wantHd ? '1' : '0'); } catch (_) {}
  if (wantHd !== _hdMode) {
    _hdMode = wantHd;
    AUDIO_SR          = _hdMode ? 16000 : 8000;
    AUDIO_CHUNK_BYTES = _hdMode ? 4096  : 2048;
    if (audioCtx) { try { audioCtx.close(); } catch(_){} audioCtx = null; }
    if (_micSrc)  { try { _micSrc.disconnect(); }  catch(_){} _micSrc = null; }
    if (_micProc) { try { _micProc.disconnect(); } catch(_){} _micProc = null; }
  }
  document.getElementById('disclaimerOverlay').remove();
  document.getElementById('btnToggle1').disabled = true;
  document.getElementById('btnToggle2').disabled = true;
  addDeviceSwitchers(); initSvgLines();
  await wakeAudio();
  await getMedia();
  ensureMic();
  startUploadLoop();
  setupLocalPreview('L'); setupLocalPreview('R');
  log('L', '✅ Ready — click Find Peer'); log('R', '✅ Ready — click Find Peer');
  document.getElementById('btnToggle1').disabled = false;
  document.getElementById('btnToggle2').disabled = false;
};

function mkToggle(panel, btnId, label){
  return () => {
    const tk = panel === 'L' ? 'Ltimer' : 'Rtimer';
    if (panels[panel].remoteId) {
      if (!confirmState[panel]) {
        confirmState[panel] = true;
        updateToggleBtn(panel);
        if (confirmState[tk]) clearTimeout(confirmState[tk]);
        confirmState[tk] = setTimeout(() => {
          confirmState[panel] = false;
          updateToggleBtn(panel);
        }, 3000);
      } else {
        if (confirmState[tk]) clearTimeout(confirmState[tk]);
        confirmState[tk] = null; confirmState[panel] = false;
        resetChannel(panel, true);
      }
    } else if (panels[panel].seeking || panels[panel].active) {
      if (_traceEnabled) trace(panel, `${_ts()}    STOP SEARCH clicked epoch=${panels[panel].epoch} target=${(panels[panel].targetId || 'none').slice(0,14)}`, 'info');
      resetChannel(panel, false);
    } else {
      enterMatchQueue(panel);
    }
  };
}
document.getElementById('btnToggle1').onclick = mkToggle('L', 'btnToggle1', 'Find Peer');
document.getElementById('btnToggle2').onclick = mkToggle('R', 'btnToggle2', 'Find Peer');
document.getElementById('btnBridge').onclick  = () => toggleBridge(false);
document.getElementById('sendBtn1').onclick   = () => sendChat('L');
document.getElementById('sendBtn2').onclick   = () => sendChat('R');
document.getElementById('chatInput1').addEventListener('keypress', e => { if (e.key === 'Enter') sendChat('L'); });
document.getElementById('chatInput2').addEventListener('keypress', e => { if (e.key === 'Enter') sendChat('R'); });

log('L', '🔍 Ready – accept disclaimer to begin');
log('R', '🔍 Ready – accept disclaimer to begin');

// ===== DONATE MODAL =====
const _modal = document.getElementById('donateModal');
window._copyAddr = el => {
  const addr = el.childNodes[el.childNodes.length-1].textContent.trim();
  navigator.clipboard?.writeText(addr).catch(() => {});
  el.classList.add('d-copied');
  setTimeout(() => el.classList.remove('d-copied'), 1200);
};
document.getElementById('donateBtn').onclick = e => {
  e.stopPropagation();
  _modal.style.display = _modal.style.display === 'flex' ? 'none' : 'flex';
};
_modal.onclick = () => { _modal.style.display = 'none'; };
document.getElementById('donateBox').onclick = e => { e.stopPropagation(); };
document.getElementById('donateDismiss').onclick = e => { e.stopPropagation(); _modal.style.display = 'none'; };

// ===== NETWORK MODAL (mirror staging — sec 0: censorship resistance via disposable mirrors) =====
// Mirror URLs/IPs are STAGED CLIENT-SIDE ONLY (localStorage, FIFO cap 64) for a future gossip pass.
// No server endpoint, no new request, no new file — the contract's NO-NEW-MOVING-PARTS rule holds.
const _netModal = document.getElementById('networkModal');
document.getElementById('networkBtn').onclick = e => {
  e.stopPropagation();
  _netModal.style.display = _netModal.style.display === 'flex' ? 'none' : 'flex';
};
_netModal.onclick = () => { _netModal.style.display = 'none'; };
document.getElementById('networkBox').onclick = e => { e.stopPropagation(); };
document.getElementById('networkDismiss').onclick = e => { e.stopPropagation(); _netModal.style.display = 'none'; };
const MIRROR_KEY = 'nosignup_pending_mirrors', MIRROR_CAP = 64;
function _stageMirror(){
  const inp = document.getElementById('mirrorInput'), st = document.getElementById('mirrorStatus');
  const raw = (inp.value || '').trim();
  const bare = raw.replace(/^https?:\/\//i, '');
  if (raw.length < 4 || raw.length > 200 || !/^[a-z0-9][a-z0-9.\-:\/_~%\[\]]*$/i.test(bare)) {
    st.textContent = '✗ enter a URL or IP'; return;
  }
  let list = []; try { list = JSON.parse(localStorage.getItem(MIRROR_KEY) || '[]'); } catch (_) { list = []; }
  if (!Array.isArray(list)) list = [];
  if (list.indexOf(raw) === -1) {
    list.push(raw);
    while (list.length > MIRROR_CAP) list.shift();
    try { localStorage.setItem(MIRROR_KEY, JSON.stringify(list)); } catch (_) {}
  }
  st.textContent = '✓ staged locally (' + list.length + ' pending)';
  inp.value = '';
}
document.getElementById('mirrorSubmit').onclick = e => { e.stopPropagation(); _stageMirror(); };
document.getElementById('mirrorInput').onclick  = e => { e.stopPropagation(); };
document.getElementById('mirrorInput').addEventListener('keypress', e => { if (e.key === 'Enter') _stageMirror(); });

})();
</script>
</body></html>
