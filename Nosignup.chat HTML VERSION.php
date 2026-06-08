<?php
/**
 * ---------------------------------------------------------------------------------
 * DO NOT DELETE/REMOVE THIS BLOCK - NOSIGNUP.CHAT — DO NOT DELETE/REMOVE THIS BLOCK
 * ---------------------------------------------------------------------------------
 * Anonymous Spritesheet HTTP Chat. Pure HTTP, no WebRTC, cheap PHP hosting.
 *
 * HOSTING: No WebSockets/streaming/persistent connections. Clients do the work. Drop > buffer.
 *   Elegance through simplicity/reuse; perfection costs complexity, aim for acceptable thresholds.
 * >>> ACTIVELY MINIMIZE HTTP requests, the server should aim to recieve and send ONE packet per user! <<<
 * >>> DO NOT INTRODUCE NEW FILES. 
 * >>> ONLY ONE BLOB PER USER COMING IN (two when bridging). ONLY 1-2 BLOBS PER USER GOING OUT.
 * >>> ONLY ONE KYC FOLDER PER IP, CONTAINING A SINGLE METADATA TEXT FILE, AND 1% OF THEIR SPRITESHEETS! <<<
 * >>> DO NOT DEVIATE FROM THIS 'DO NOT DELETE/REMOVE' BLOCK! IT IS CORRECT, YOUR CODE IS NOT.
 *
 * BLOB FORMAT: [4:vLen][JPEG×9][4:aLen][G.711 μ-law uint8 mono (8kHz default; 16kHz in HD mode, tail: sr=16000)][UTF-8 tail: key=value\n...]
 *   Tail keys: seq, head, msg, msg_ts, bridge_target, sr
 *   Content: 9 frames (2304ms @ 256ms/frame) + 2304ms audio (9 chunks @ 256ms, 8kHz/16kHz G.711 μ-law) + last chat msg.
 *   Push: one blob/1000ms per client via HTTP, fire-and-forget. Upload loop starts at first camera/mic grant;
 *   ticks are no-ops until at least one panel is seeking or connected. Idle client produces no blobs.
 *   Server: holds ONE blob/user in RAM (new replaces old), reads FILENAME+SIZE+TAIL only — never processes
 *   content. Filename+size MUST encode all routing metadata for the server decision (Send/Drop/Save).
 *
 * PLAYBACK: Constant-latency jitter buffer. Each blob scheduled to play LATENCY_MS after arrival.
 *   No anchor needed — avoids clock drift over long sessions (sender rAF ≠ exactly FRAME_MS).
 *   Natural overlap: 2304ms blob duration vs ~1000ms arrival interval = ~1300ms buffer depth.
 *   Timeline loop draws whichever blob covers current time — no skip, no discard of overlap frames.
 *   Duplicate: same seq as previous → skip. Exhausted with no new blob → blank/silence (no loop).
 *   STALE_LIMIT=6: same seq 6× → peer disconnected.
 *   seq (tail key) used for dedup; head (tail key) used for PLAY trace logging.
 *
 * IDENTITY: Client generates UUID once → localStorage as MY_BASE (deviceId). Stable across reloads.
 *   Without stable peerId: every reload creates a new sprite file, directory bloats with orphans,
 *   glob() in match scan slows down, GET /sprite latency climbs — observed runaway slowdown.
 *   Same user always overwrites same sprite file; directory size = unique devices ever seen.
 *   No sessions, no cookies, no server fingerprinting, no salt, no PHP $_SESSION.
 *   Two browsers = two IDs, no NAT collision. localStorage ≠ cookie; disclaimer holds.
 *   NOTE: KYC is separate from identity — sharded by IP, saves a fingerprint with every ~100th
 *   spritesheet received (probabilistic). Has no relation to localStorage deviceId.
 *
 * STORAGE:
 *   Ephemeral:  /dev/shm/nosignup/sprite/{peerId}.bin  (fallback: sys_get_temp_dir)
 *               ONE blob per user in RAM. Flat directory, no subdirectories ever.
 *               Routing state goes in the filename (_S for seeking only).
 *               No cleanup logic needed. Reboot wipes it.
 *   Persistent: /var/lib/nosignup/kyc/{crc32(IP)}/metadata.log + sample.jpg
 *               1-in-100 sample via mt_rand — 99% of uploads skip all KYC I/O.
 *               ONE folder per IP, ONE metadata text file, ONE sample spritesheet (overwritten).
 *               Lazy delete: mtime > 365d → unlink on next access. No cron, no counter file.
 *
 * POLLING: Simple setInterval. No ETag, no 304.
 * CAMERA:  Hardcoded center crop 0.75 → downscale to 80×60. 3×3 spritesheet, JPEG q=0.50. No slider.
 * MATCHING: Stateless. Seek/match routing encoded in filename; bridge routing via tail.
 *
 * BRIDGE: Operator sets bridge_target={peerId} in tail. /sprite checks target's tail; if fresh,
 *   serves that file transparently instead. Operator's own fetch (targetId=remoteId) unaffected.
 *
 * CHAT:        Push to feed only if message differs from last received.
 * DIAGNOSTICS: Verbose stats overlay, opt-in checkbox, persisted in localStorage. Default OFF.
 * DEVELOPMENT: Correctness first, minify after.
 * ---------------------------------------------------------------------------------
 * DO NOT DELETE/REMOVE THIS BLOCK - NOSIGNUP.CHAT — DO NOT DELETE/REMOVE THIS BLOCK
 * ---------------------------------------------------------------------------------
 */

// ===== BLOCK 1: PHP CONFIG + STORAGE DIRS =====
error_reporting(0); ini_set('display_errors', 0); ini_set('log_errors', 1);

$SPRITE_DIR = '/dev/shm/nosignup/sprite';
$KYC_BASE   = '/var/lib/nosignup/kyc';
if (!is_dir($SPRITE_DIR) && !@mkdir($SPRITE_DIR, 0755, true)) {
    $SPRITE_DIR = sys_get_temp_dir() . '/nosignup_sprite';
    @mkdir($SPRITE_DIR, 0755, true);
}
if (!is_dir($KYC_BASE) && !@mkdir($KYC_BASE, 0755, true)) {
    $KYC_BASE = sys_get_temp_dir() . '/nosignup_kyc';
    @mkdir($KYC_BASE, 0755, true);
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

function blob_tail_get($blob, $key) {
    $n = strlen($blob);
    if ($n < 8) return null;
    $vLen = unpack('N', substr($blob, 0, 4))[1];
    if ($vLen < 0 || 4 + $vLen + 4 > $n) return null;
    $aLen = unpack('N', substr($blob, 4 + $vLen, 4))[1];
    $tailStart = 4 + $vLen + 4 + $aLen;
    if ($aLen < 0 || $tailStart > $n) return null;
    $tail = substr($blob, $tailStart);
    foreach (explode("\n", $tail) as $line) {
        $eq = strpos($line, '=');
        if ($eq !== false && substr($line, 0, $eq) === $key) {
            $v = substr($line, $eq + 1);
            return preg_replace('/[^a-zA-Z0-9_\-]/', '', $v);
        }
    }
    return null;
}

function log_kyc($peerId, $blob) {
    global $KYC_BASE;
    if (mt_rand(1, 100) !== 1) return;
    $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $peerId);
    if ($pid === '') return;
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ipDir = $KYC_BASE . '/' . sprintf('%08x', crc32($ip));
    if (!is_dir($ipDir) && !@mkdir($ipDir, 0755, true)) return;
    $logFile = "$ipDir/metadata.log";
    lazy_expire($logFile);
    @file_put_contents($logFile, time() . ",$pid,$ip\n", FILE_APPEND);
    if (strlen($blob) >= 4) {
        $vLen = unpack('N', substr($blob, 0, 4))[1];
        if ($vLen > 0 && 4 + $vLen <= strlen($blob)) {
            @file_put_contents("$ipDir/sample.jpg", substr($blob, 4, $vLen));
        }
    }
}

function write_sprite($pid, $blob) {
    global $SPRITE_DIR;
    $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $pid);
    if (strlen($pid) < 8 || $blob === false || strlen($blob) === 0) return;
    @file_put_contents("$SPRITE_DIR/$pid.bin", $blob, LOCK_EX);
    $kpid = preg_match('/^(.+)_S$/', $pid, $m) ? $m[1] : $pid;
    log_kyc($kpid, $blob);
}

$a = $_GET['api'] ?? '';
if ($a !== '') {
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // ===== UPLOAD =====
    if ($a === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        $handled = false;
        // Common case: one blob, one or two destinations (tails identical — no bridge/chat)
        if (isset($_FILES['blob'])) {
            $blobData = @file_get_contents($_FILES['blob']['tmp_name']);
            foreach (['L', 'R'] as $ch) {
                if (isset($_POST['peerId' . $ch])) {
                    write_sprite($_POST['peerId' . $ch], $blobData);
                    $handled = true;
                }
            }
            if (!$handled && isset($_POST['peerId'])) {
                write_sprite($_POST['peerId'], $blobData);
                $handled = true;
            }
        }
        // Bridge/chat case: per-panel blobs with different tails
        foreach (['L', 'R'] as $ch) {
            if (isset($_POST['peerId' . $ch], $_FILES['blob' . $ch])) {
                $blob = @file_get_contents($_FILES['blob' . $ch]['tmp_name']);
                write_sprite($_POST['peerId' . $ch], $blob);
                $handled = true;
            }
        }
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
            foreach (glob("$SPRITE_DIR/*_{$ch}_S.bin") ?: [] as $f) {
                $other = basename($f, '.bin');
                if ($other === $pid) continue;
                $mt = @filemtime($f);
                if ($mt === false) continue;
                if ($now - $mt > 10) continue;
                if (strpos($other, $base) === 0) continue;
            $candidates[] = $other;
            }
            if ($candidates) {
                $found      = $candidates[array_rand($candidates)];
                $remoteBase = preg_replace('/_S$/', '', $found);
                header('X-Match-Peer: ' . $remoteBase);
                header('Content-Type: application/octet-stream');
                http_response_code(200);
                exit;
            }
            http_response_code(204);
            exit;
        }
        $info = sprite_info($pid);
        if ($info === null || ($now - $info[1]) > 10) { http_response_code(404); exit; }
        $blob = @file_get_contents($info[0]);
        if ($blob === false) { http_response_code(404); exit; }
        $bt = blob_tail_get($blob, 'bridge_target');
        if ($bt !== null && $bt !== '' && $bt !== $pid) {
            $btInfo = sprite_info($bt);
            if ($btInfo !== null && ($now - $btInfo[1]) <= 10) {
                $btBlob = @file_get_contents($btInfo[0]);
                if ($btBlob !== false) $blob = $btBlob;
            }
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($blob));
        echo $blob;
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
:root{--bg-period:49s;--bg-amplitude:1;--hue:0deg}
@property --hue{syntax:'<angle>';inherits:true;initial-value:0deg}
*{box-sizing:border-box}
/* F7: 100dvh progressive enhancement — iOS Safari clips 100vh under the address bar.
   100dvh (dynamic viewport height) is the correct modern value; 100vh is the fallback. */
body,html{margin:0;padding:0;height:100%;height:100dvh;overflow:hidden;background:#000;font-family:'Courier New',monospace;animation:hueCycle var(--bg-period) linear infinite}
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
.chatone,.chattwo{width:50vw;height:100vh;height:100dvh;padding:0;overflow-y:auto;display:flex;flex-direction:column;gap:2px}
.chatone{border-right:1px solid rgba(200,0,0,.75)}.chattwo{border-left:1px solid rgba(0,0,200,.75)}
.VideoInputBoxOne,.VideoInputBoxTwo,.VideoOutputBoxOne,.VideoOutputBoxTwo{height:25%;background:linear-gradient(135deg,rgba(255,77,109,.25),rgba(255,184,77,.25),rgba(255,255,77,.25),rgba(77,255,136,.25),rgba(77,166,255,.25),rgba(184,77,255,.25),rgba(255,77,109,.25)) border-box,rgba(0,0,0,.6) padding-box;background-origin:border-box,padding-box;background-clip:border-box,padding-box;border:1px solid transparent;border-radius:6px;display:flex;justify-content:center;align-items:center;overflow:hidden;position:relative}
.ChatButtonsOne,.ChatButtonsTwo{height:10%;background:linear-gradient(135deg,rgba(255,77,109,.25),rgba(255,184,77,.25),rgba(255,255,77,.25),rgba(77,255,136,.25),rgba(77,166,255,.25),rgba(184,77,255,.25),rgba(255,77,109,.25)) border-box,rgba(0,0,0,.6) padding-box;background-origin:border-box,padding-box;background-clip:border-box,padding-box;border:1px solid transparent;border-radius:40px;display:flex;justify-content:center;align-items:stretch;gap:6px;overflow:hidden}
.ChatBoxOne,.ChatBoxTwo{height:38%;background:rgba(0,0,0,.7);border:1px solid rgba(255,255,255,.3);border-radius:6px;display:flex;flex-direction:column;overflow:hidden}
.ToggleChatOne,.ToggleChatTwo{flex:1;padding:0;font-family:monospace;font-weight:900;background:#1e1e2f;color:#fff;border:0;border-radius:40px;cursor:pointer;transition:transform .2s,background .2s;font-size:clamp(1.4rem,6vw,2.4rem);letter-spacing:.06em;white-space:normal;word-break:keep-all;text-align:center;line-height:1.2}
.ToggleChatOne:hover:not(:disabled),.ToggleChatTwo:hover:not(:disabled){background:#3a3a55;transform:scale(1.02)}
button:disabled{opacity:.35;cursor:default}
#centerControls{position:fixed;left:50%;top:47vh;transform:translate(-50%,-50%);z-index:20;display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none}
#centerControls > *{pointer-events:auto}
#deviceCluster{display:flex;flex-direction:column;gap:6px}
.dev-btn{width:36px;height:36px;border-radius:50%;border:1px solid hsl(var(--hue) 70% 65% / .6);background:rgba(0,0,0,.7);color:#fff;font-size:16px;cursor:pointer;backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;transition:transform .15s,box-shadow .2s,border-color .2s;padding:0;line-height:1}
.dev-btn:hover{transform:scale(1.08);box-shadow:0 0 14px hsl(var(--hue) 80% 60% / .55)}
#btnBridge{width:56px;height:56px;border-radius:50%;background:#0a0a14;border:2px solid hsl(var(--hue) 75% 65% / .85);color:#fff;font-size:1.3rem;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 0 22px hsl(var(--hue) 90% 55% / .35),inset 0 0 14px hsl(var(--hue) 80% 60% / .15);transition:transform .15s,box-shadow .2s,border-color .2s,background .2s}
#btnBridge:hover:not(:disabled){transform:scale(1.06);box-shadow:0 0 32px hsl(var(--hue) 95% 60% / .7),inset 0 0 18px hsl(var(--hue) 90% 65% / .25)}
#btnBridge.active{background:#2a1a0a;border-color:#fa0;box-shadow:0 0 28px rgba(255,170,0,.7),inset 0 0 18px rgba(255,170,0,.4)}
#btnBridge:disabled{opacity:.25;cursor:default;box-shadow:0 0 8px rgba(0,0,0,.6)}
.px-canvas{width:100%;height:100%;display:block;image-rendering:crisp-edges;object-fit:contain}
.chat-messages{flex:1;overflow-y:auto;padding:4px 6px;color:#fff;font-size:clamp(12px,3.5vw,16px);font-weight:500;display:flex;flex-direction:column;gap:3px}
.chat-messages div{color:#fff;font-weight:500;text-shadow:0 0 1px rgba(0,0,0,.5)}
.chat-input-area{display:flex;border-top:1px solid rgba(255,255,255,.2);padding:4px 6px;gap:4px;background:#0a0a1a}
.chat-input{flex:1;background:#2a2a3a;border:0;color:#fff;font-size:clamp(14px,4vw,18px);font-weight:bold;padding:6px 10px;border-radius:20px;font-family:monospace}
.chat-input::placeholder{color:#eee;font-weight:normal;opacity:.8}
.chat-input:focus{outline:none;box-shadow:0 0 0 1px hsl(var(--hue) 80% 60% / .8)}
.chat-send{background:#4a4a6a;border:0;color:#fff;font-size:clamp(14px,4vw,18px);font-weight:bold;border-radius:20px;padding:0 12px;cursor:pointer}
.chat-send:disabled{opacity:.35;cursor:default}
.status-badge{display:inline-block;background:rgba(10,10,20,.85);border:1px solid hsl(var(--hue) 70% 55% / .5);border-radius:16px;padding:2px 10px;font-size:11px;text-align:center;color:#aaa;position:absolute;top:6px;left:50%;transform:translateX(-50%);z-index:10;pointer-events:none;white-space:nowrap;letter-spacing:.08em;box-shadow:0 0 10px hsl(var(--hue) 70% 50% / .25);transition:color .2s,border-color .2s}
.status-badge.s-searching{color:#ffd54d;border-color:#ffd54d}
.status-badge.s-connected{color:#4dff88;border-color:#4dff88;box-shadow:0 0 12px rgba(77,255,136,.4)}
.status-badge.s-bridging{color:#ffaa00;border-color:#ffaa00;box-shadow:0 0 12px rgba(255,170,0,.5)}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:#111}::-webkit-scrollbar-thumb{background:#555;border-radius:2px}
@media(max-width:700px){.chatone,.chattwo{gap:0}.ToggleChatOne,.ToggleChatTwo{font-size:clamp(1.2rem,5vw,1.8rem)}}
#disclaimerOverlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.96);z-index:10000;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(8px);font-family:'Courier New',monospace}
.disclaimer-box{max-width:min(90vw,500px);background:#111;border:2px solid #ff4d6d;border-radius:24px;padding:1.2rem;color:#fff;text-align:center;box-shadow:0 0 30px rgba(255,77,109,.3);animation:fadeInScale .25s ease-out}
.disclaimer-box h1{font-size:clamp(1.4rem,6vw,1.8rem);margin:0 0 .5rem}
.disclaimer-box ul{text-align:left;margin:.6rem 0;padding-left:1.2rem}
.disclaimer-box li{margin:.3rem 0;font-size:.9rem;line-height:1.35}
.disclaimer-box .warning{color:#ff8888;font-weight:bold;border-top:1px solid #ff4d6d;border-bottom:1px solid #ff4d6d;padding:.5rem;margin:.6rem 0;font-size:.82rem;line-height:1.4}
.tog-btn{display:block;width:100%;padding:.6rem .9rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,77,109,.35);border-radius:14px;font-size:.85rem;color:#ffd4dc;cursor:pointer;font-family:inherit;user-select:none;transition:all .15s;text-align:center;margin:.4rem 0}
.tog-btn:hover{background:rgba(255,77,109,.12);border-color:rgba(255,77,109,.65)}
.tog-btn.on{background:rgba(255,77,109,.15);border-color:#ff4d6d;color:#fff}
.hd-row{display:flex;gap:6px;margin:.4rem 0}
.hd-row .tog-btn{margin:0;flex:1}
.ad-lnk{padding:.6rem .9rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,184,77,.35);border-radius:14px;font-size:.85rem;color:#ffb84d88;text-decoration:none;display:flex;align-items:center;justify-content:center;transition:all .15s;white-space:nowrap}
.ad-lnk:hover{background:rgba(255,184,77,.08);border-color:rgba(255,184,77,.65);color:#ffb84d}
.disclaimer-box button{background:#ff4d6d;color:#000;font-weight:bold;font-size:1rem;font-family:monospace;padding:8px 20px;border:0;border-radius:40px;cursor:pointer;transition:all .2s;margin-top:.3rem}
.disclaimer-box button:hover{background:#ff6b85;transform:scale(1.02)}
@keyframes fadeInScale{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.dev-menu{position:absolute;top:42px;background:rgba(15,15,25,.95);border:1px solid hsl(var(--hue) 70% 55% / .7);border-radius:10px;padding:6px 0;min-width:160px;z-index:26;display:none;flex-direction:column;backdrop-filter:blur(10px);box-shadow:0 6px 24px rgba(0,0,0,.6)}
.dev-menu button{background:transparent;border:0;color:#fff;padding:7px 14px;text-align:left;font-family:monospace;font-size:12px;cursor:pointer;transition:background .12s}
.dev-menu button:hover{background:hsl(var(--hue) 70% 55% / .6);color:#000}
.peer-placeholder{color:#fff;font-weight:bold;font-size:clamp(14px,4vw,24px);letter-spacing:.12em;text-shadow:0 0 8px hsl(var(--hue) 80% 50% / .7),-1px -1px 0 #000,1px -1px 0 #000,-1px 1px 0 #000,1px 1px 0 #000}
#donateModal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.93);z-index:20000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(6px);cursor:pointer}
#donateBox{background:#060606;border:1px solid #222;max-width:380px;width:90%;padding:2.2rem 2rem 1.8rem;font-family:'Courier New',monospace;color:#888;position:relative;cursor:pointer;line-height:2;letter-spacing:.04em}
#donateBox:hover{border-color:#333}
#donateDismiss{position:absolute;top:.7rem;right:.9rem;background:0;border:0;color:#444;font-size:1.1rem;cursor:pointer;font-family:monospace;padding:0;line-height:1}
#donateDismiss:hover{color:#aaa}
.d-glyph{font-size:1.4rem;color:#ff9aaa;display:block;margin-bottom:.6rem}
.d-desc{font-size:.78rem;color:#555;margin:0 0 1rem;line-height:1.7}
.d-dl{display:block;text-align:center;background:#0d0d0d;border:1px solid #1e1e1e;color:#aaa;font-size:.78rem;font-family:monospace;text-decoration:none;padding:.5rem;letter-spacing:.08em;margin-bottom:1.2rem;transition:color .2s,border-color .2s}
.d-dl:hover{border-color:#555;color:#fff}
.d-addr{background:#0d0d0d;border:1px solid #1e1e1e;padding:.55rem .8rem;margin-bottom:.35rem;font-size:.65rem;word-break:break-all;transition:all .15s;cursor:copy}
.d-addr:hover{border-color:currentColor}
.d-addr-label{display:block;font-size:.58rem;letter-spacing:.14em;margin-bottom:.15rem;opacity:.5}
.d-btc{color:#f7931a}.d-xmr{color:#f60}.d-ltc{color:#a5a9ff}
.d-copied{color:#4dff88 !important;border-color:#4dff88 !important}
.d-rule{border:0;border-top:1px solid #1a1a1a;margin:1rem 0}
.d-footer{font-size:.7rem;color:#333;line-height:1.6;margin:0}
</style>
</head>
<body>
<div class="background bg1"></div><div class="background bg2"></div><div class="background bg3"></div><div class="background bg4"></div><div class="background bg5"></div><div class="background bg6"></div><div class="background bg7"></div>
<div class="banner"><svg class="banner-image" viewBox="0 0 460 220" xmlns="http://www.w3.org/2000/svg"><defs><filter id="glow" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="2" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter><filter id="textGlow" x="-20%" y="-30%" width="140%" height="160%"><feGaussianBlur stdDeviation="3.5" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter><linearGradient id="rainbowDiv" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#ff4d6d"/><stop offset="20%" stop-color="#ffb84d"/><stop offset="40%" stop-color="#ffff4d"/><stop offset="60%" stop-color="#4dff88"/><stop offset="80%" stop-color="#4da6ff"/><stop offset="100%" stop-color="#b84dff"/></linearGradient></defs><g opacity="0.03"><pattern id="dotGrid" width="16" height="16" patternUnits="userSpaceOnUse"><circle cx="8" cy="8" r="1" fill="#fff"/></pattern><rect width="460" height="220" fill="url(#dotGrid)"/></g><polygon points="230,12 318,34 356,110 318,186 230,208 142,186 104,110 142,34" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="0.8" stroke-dasharray="4 6"/><g stroke="rgba(255,255,255,0.5)" stroke-width="1" fill="none" filter="url(#glow)"><path d="M14,36 L14,14 L36,14"><animate attributeName="stroke-opacity" values="0.2;0.8;0.2" dur="2s" repeatCount="indefinite"/></path><path d="M424,14 L446,14 L446,36"><animate attributeName="stroke-opacity" values="0.2;0.8;0.2" dur="2s" begin="0.5s" repeatCount="indefinite"/></path><path d="M14,184 L14,206 L36,206"><animate attributeName="stroke-opacity" values="0.2;0.8;0.2" dur="2s" begin="1s" repeatCount="indefinite"/></path><path d="M424,206 L446,206 L446,184"><animate attributeName="stroke-opacity" values="0.2;0.8;0.2" dur="2s" begin="1.5s" repeatCount="indefinite"/></path></g><circle cx="230" cy="110" r="78" fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="0.8" stroke-dasharray="3 9"><animateTransform attributeName="transform" type="rotate" from="0 230 110" to="360 230 110" dur="30s" repeatCount="indefinite"/></circle><g id="outerNodes"><circle cx="230" cy="40" r="3" fill="#ff4d6d" filter="url(#glow)" id="node0"><animate attributeName="r" values="2;4;2" dur="2s"/></circle><circle cx="279" cy="63" r="3" fill="#ffb84d" filter="url(#glow)" id="node1"><animate attributeName="r" values="2;4;2" dur="2.3s"/></circle><circle cx="298" cy="122" r="3" fill="#ffff4d" filter="url(#glow)" id="node2"><animate attributeName="r" values="2;4;2" dur="1.8s"/></circle><circle cx="265" cy="168" r="3" fill="#4dff88" filter="url(#glow)" id="node3"><animate attributeName="r" values="2;4;2" dur="2.5s"/></circle><circle cx="207" cy="176" r="3" fill="#4da6ff" filter="url(#glow)" id="node4"><animate attributeName="r" values="2;4;2" dur="2.1s"/></circle><circle cx="162" cy="142" r="3" fill="#b84dff" filter="url(#glow)" id="node5"><animate attributeName="r" values="2;4;2" dur="1.9s"/></circle><circle cx="160" cy="92" r="3" fill="#ff4da6" filter="url(#glow)" id="node6"><animate attributeName="r" values="2;4;2" dur="2.4s"/></circle></g><g id="starGroup"><animateTransform attributeName="transform" type="rotate" from="0 230 110" to="360 230 110" dur="25s"/><polygon points="230,50 252.1,76.5 276.2,89.2 278.7,119.7 291.1,135.7 269.4,158.7 266.2,169.8 235.9,168.2 218.2,176.1 201.4,152.5 186.5,142.9 194.1,113.3 178.9,95.4 208.9,79.8" fill="none" stroke="#fff" stroke-width="1.8" stroke-linejoin="round" filter="url(#glow)"/><g><animateTransform attributeName="transform" type="rotate" from="360 230 110" to="0 230 110" dur="15s"/><polygon points="230,60 247.7,78.6 264.2,86.9 265.5,108.2 273.3,119.2 259.5,134.6 257.2,142.3 238.1,141.2 226.5,146.3 215.6,131.5 206.1,125.1 210.9,106.1 201.5,94.7 218.5,84.6" fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="1" stroke-linejoin="round" filter="url(#glow)"/></g></g><circle cx="230" cy="110" r="6" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="0.8"><animate attributeName="r" values="5;8;5" dur="2s"/></circle><circle cx="230" cy="110" r="2.5" fill="#fff" filter="url(#glow)"><animate attributeName="opacity" values="0.6;1;0.6" dur="1.5s"/></circle><line x1="218" y1="110" x2="242" y2="110" stroke="rgba(255,255,255,0.3)" stroke-width="0.5"/><line x1="230" y1="98" x2="230" y2="122" stroke="rgba(255,255,255,0.3)" stroke-width="0.5"/><text x="230" y="86" font-family="'Courier New',Courier,monospace" font-size="42" font-weight="900" fill="#fff" text-anchor="middle" letter-spacing="6" filter="url(#textGlow)">NOSIGNUP<animate attributeName="letter-spacing" values="6;10;6" dur="4s"/></text><line x1="170" y1="98" x2="290" y2="98" stroke="url(#rainbowDiv)" stroke-width="1.2"><animate attributeName="stroke-opacity" values="0.4;1;0.4" dur="2.5s"/></line><text x="230" y="150" font-family="'Courier New',Courier,monospace" font-size="60" font-weight="900" fill="#fff" text-anchor="middle" letter-spacing="12" filter="url(#textGlow)">CHAT<animate attributeName="letter-spacing" values="12;16;12" dur="4s" begin="1s"/></text><circle cx="45" cy="25" r="2" fill="#0fc" filter="url(#glow)"><animate attributeName="opacity" values="0.2;1;0.2" dur="1.8s"/></circle><circle cx="415" cy="195" r="2" fill="#0fc" filter="url(#glow)"><animate attributeName="opacity" values="0.2;1;0.2" dur="1.8s" begin="0.9s"/></circle></svg></div>
<div class="chatmain">
  <div class="chatone">
    <div class="VideoInputBoxOne" id="localVideoContainer1"><div class="status-badge" id="statusBadge1">IDLE</div></div>
    <div class="ChatButtonsOne"><button class="ToggleChatOne" id="btnToggle1">Find Peer</button></div>
    <div class="VideoOutputBoxOne" id="remoteVideoContainer1"><span class="peer-placeholder">Peer 1 appears here</span></div>
    <div class="ChatBoxOne" id="chatBox1"><div class="chat-messages" id="chatMessages1"></div><div class="chat-input-area"><input type="text" class="chat-input" id="chatInput1" placeholder="Message Peer 1" maxlength="500" autocomplete="off"><button class="chat-send" id="sendBtn1">Send</button></div></div>
  </div>
  <div class="chattwo">
    <div class="VideoInputBoxTwo" id="localVideoContainer2"><div class="status-badge" id="statusBadge2">IDLE</div></div>
    <div class="ChatButtonsTwo"><button class="ToggleChatTwo" id="btnToggle2">Find Peer</button></div>
    <div class="VideoOutputBoxTwo" id="remoteVideoContainer2"><span class="peer-placeholder">Peer 2 appears here</span></div>
    <div class="ChatBoxTwo" id="chatBox2"><div class="chat-messages" id="chatMessages2"></div><div class="chat-input-area"><input type="text" class="chat-input" id="chatInput2" placeholder="Message Peer 2" maxlength="500" autocomplete="off"><button class="chat-send" id="sendBtn2">Send</button></div></div>
  </div>
</div>
<div id="centerControls"><div id="deviceCluster"></div><button id="btnBridge" disabled title="Bridge both peers — they see each other, you watch silently">🔗</button></div>
<div id="disclaimerOverlay"><div class="disclaimer-box"><h1>⚜️ AGE OF MAJORITY</h1><ul><li><strong>Two strangers, two channels,</strong> simultaneously. 🔗 Bridge → they see each other, you watch.</li><li><strong>💬 Private chat</strong> per peer. 📷🎤 icons above bridge → swap devices anytime.</li><li><strong>80×60 pixelated</strong> video + audio. No accounts, no cookies, no tracking. Ever.</li><li><strong>Pure HTTP</strong> — no WebRTC, works anywhere, low bandwidth.</li></ul><div class="warning">⚠️ You confirm you are of <strong>legal age</strong> in your jurisdiction. Content may be adult. No warranty, no recourse.</div><button class="tog-btn" id="togStats" data-lbl="📊 Performance" onclick="togRow(this)">📊 Performance · OFF</button><button class="tog-btn" id="togTrace" data-lbl="🔬 Trace" onclick="togRow(this)">🔬 Trace · OFF</button><div class="hd-row"><button class="tog-btn" id="togHd" data-lbl="🔉 High Definition" onclick="togRow(this)">🔉 High Definition · OFF</button><a href="https://matias.ma/nsfw/" target="_blank" rel="noopener" class="ad-lnk">🔞 Adult Definition ;)</a></div><button class="tog-btn" id="donateBtn" style="border-color:rgba(255,154,170,.35);color:#ff9aaa88" onmouseover="this.style.borderColor='rgba(255,154,170,.7)';this.style.color='#ff9aaa'" onmouseout="this.style.borderColor='rgba(255,154,170,.35)';this.style.color='#ff9aaa88'">♡ Donate</button><button id="acceptDisclaimerBtn">✓ I AGREE — ENTER</button></div></div>
<div id="donateModal"><div id="donateBox"><button id="donateDismiss" title="close">×</button><span class="d-glyph">♡</span><p class="d-desc">one .php file. drop it on any PHP host.<br>no build step. no dependencies. it just runs.</p><a class="d-dl" href="index.php?src=1" onclick="event.stopPropagation()">↓ download nosignup.php</a><div class="d-addr d-btc" onclick="_copyAddr(this)"><span class="d-addr-label">BITCOIN · electrum</span>bc1qqnu6n0jztxl4f6krv7klradghle09uhyu7uymz</div><div class="d-addr d-xmr" onclick="_copyAddr(this)"><span class="d-addr-label">MONERO · feather</span>8Ab24DppUvcdtHfm7K8gTqdBTmPCBiak1GwxgPm1C3osYVQL2QdC1C8GMwggKF77RKKzDgP2R8E3VH8ifetsKms5AqkVyVg</div><div class="d-addr d-ltc" onclick="_copyAddr(this)"><span class="d-addr-label">LITECOIN · electrum-ltc</span>ltc1qlpdy8qzejcmjdn6vwarpyz8djdlk780w4qkwyp</div><hr class="d-rule"><p class="d-footer">click any address to copy · close anywhere outside<br><br>$1M buys the domain and one year of my time.<br>I mean it.</p></div></div>
<script>
(function(){'use strict';

// ===== BLOCK 7: JS CONFIG + STATE =====
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
const PX_W=80, PX_H=60;
const FRAME_MS    = 256;  // spec: 9 frames × 256ms = 2304ms blob duration, matches audio chunk size
const UPLOAD_MS   = 1000;
const FETCH_MS    = 1000;
const STALE_LIMIT = 6;
const CHAT_LINGER_MS = 60000;
const LATENCY_MS = 0;    // no artificial delay — blob plays the moment it arrives. Gap protection comes entirely from 2304ms blob duration, not from added latency.
const panelToIdx = {L:1, R:2};
let _uploadSeq = 0;      // monotonic counter; incremented each uploadTick; sent in tail for dup detection
let _frameHead = 0;
let _traceEnabled = false;
let _hdMode       = false;
let _lastUploadAt = 0;
const TEXT_ENCODER = new TextEncoder();
const TEXT_DECODER = new TextDecoder();

// ===== SHARED PAYLOAD CACHE =====
const SHARED_PAYLOAD_TTL_MS = UPLOAD_MS * 0.6;
let _lastTickPayload = { ts: -Infinity, videoArr: null, audioBuf: null };
async function getSharedTickPayload() {
  const now = performance.now();
  if (now - _lastTickPayload.ts < SHARED_PAYLOAD_TTL_MS && _lastTickPayload.videoArr) {
    return _lastTickPayload;
  }
  // Snapshot _frameHead BEFORE the async toBlob — frameLoop runs via rAF and may
  // increment _frameHead during the await, causing head= in the tail to be ahead
  // of the actual newest frame in the spritesheet.
  const headSnap = _frameHead;
  const videoBlob = await createSpritesheet();
  _lastTickPayload = {
    ts: now,
    videoArr: await videoBlob.arrayBuffer(),
    audioBuf: getAudioChunk(),
    headSnap
  };
  return _lastTickPayload;
}

function makePanelState(panel){
  return {
    panel, peerId: `${MY_BASE}_${panel}`, remoteId: null, targetId: null,
    seeking: false, active: false, fetchTimer: null, fetchDelayTimer: null,
    chatLastTs: 0, outgoingMsg: '', outgoingMsgTs: 0, outgoingBridge: null, ghost: 0,
    currentAudio: null, remoteCanvas: null, _remoteCtx: null,
    _fetchInflight: false, _disconnecting: false,
    // timeline jitter buffer
    timeline: [],        // [{startTime, bitmap, firstAbs}] sorted by startTime
    _tlRunning: false,   // timeline loop active
    _tlTimer: null,      // setTimeout handle for next frame boundary
    rxCount: 0, lastRxAt: 0, blackTotal: 0, blackEvents: 0, _wasBlack: false, _blackStart: 0,
    _lastBest: null
  };
}
const panels = { L: makePanelState('L'), R: makePanelState('R') };
let bridgeActive = false;
let uploadTimer  = null;
const confirmState = {L:false, R:false, Ltimer:null, Rtimer:null};

// ===== BLOCK 8: UTILITIES =====
function log(panel, msg, type){
  const d = document.createElement('div');
  d.textContent = msg;
  d.style.color = type==='self' ? '#8af' : type==='peer' ? '#af8' : '#eee';
  const el = document.getElementById(panel==='L' ? 'chatMessages1' : 'chatMessages2');
  el.appendChild(d);
  while (el.children.length > 50) el.firstChild.remove();
  el.scrollTop = el.scrollHeight;
}
// ===== BLOCK 12C: FORENSIC SPRITESHEET TRACE =====
// Logs TX/RX/PLAY/BLACK lifecycle per blob into the chat feed. Opt-in, default OFF.
const _TRACE_COLORS = {tx:'#7fd0ff',rx:'#8effa6',play:'#d9b3ff',dup:'#888',miss:'#ffcf66',black:'#ff6b6b'};
const _T0 = performance.now();
function _ts(){ return ((performance.now()-_T0)/1000).toFixed(2)+'s'; }
function trace(panel, msg, kind){
  if (!_traceEnabled) return;
  const el = document.getElementById(panel==='L' ? 'chatMessages1' : 'chatMessages2');
  if (!el) return;
  const d = document.createElement('div');
  d.textContent = msg;
  const c = _TRACE_COLORS[kind] || '#aaa';
  d.style.cssText = 'color:'+c+';font-family:monospace;font-size:10px;line-height:1.3;white-space:pre-wrap;word-break:break-all;opacity:.9;padding-left:5px;margin:1px 0;border-left:2px solid '+c+'55';
  el.appendChild(d);
  while (el.children.length > 400) el.firstChild.remove();
  el.scrollTop = el.scrollHeight;
}
function setStatus(panel, label, klass){
  const el = document.getElementById('statusBadge' + panelToIdx[panel]);
  if (!el) return;
  el.textContent = label;
  el.className = 'status-badge' + (klass ? ' s-' + klass : '');
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
function buildTail(s, headVal){
  const lines = ['seq=' + _uploadSeq, 'head=' + headVal];
  if (_hdMode) lines.push('sr=' + AUDIO_SR);
  if (s.outgoingMsg) {
    lines.push('msg=' + s.outgoingMsg.replace(/[\r\n]/g, ' '));
    lines.push('msg_ts=' + s.outgoingMsgTs);
  }
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

// ===== BLOCK 9: VIDEO CAPTURE & SPRITESHEET =====
let _stream = null, _rawVid = null, _fallbackVid = null, _fallbackTimer = null;
const _frameBuf = [];
const _fbCanvas = document.createElement('canvas'); _fbCanvas.width = PX_W; _fbCanvas.height = PX_H;
const _fbCtx = _fbCanvas.getContext('2d', {willReadFrequently:true});
const _sheetCanvas = document.createElement('canvas'); _sheetCanvas.width = PX_W*3; _sheetCanvas.height = PX_H*3;
const _sheetCtx = _sheetCanvas.getContext('2d');
let _fbLast = 0;
(function frameLoop(ts){
  requestAnimationFrame(frameLoop);
  const v = _rawVid;
  if (!v || v.readyState < 2 || !v.videoWidth) return;
  if (ts - _fbLast < FRAME_MS) return;
  _fbLast = ts;
  const _cpuT0 = performance.now();
  try {
    const cropW = Math.floor(v.videoWidth  * 0.75);
    const cropH = Math.floor(v.videoHeight * 0.75);
    const offX  = Math.floor((v.videoWidth  - cropW) / 2);
    const offY  = Math.floor((v.videoHeight - cropH) / 2);
    _fbCtx.drawImage(v, offX, offY, cropW, cropH, 0, 0, PX_W, PX_H);
    ape(_fbCtx, PX_W, PX_H);
    _frameBuf.push(_fbCtx.getImageData(0, 0, PX_W, PX_H));
    if (_frameBuf.length > 9) _frameBuf.shift();
    _frameHead++;
  } catch (_) {}
  dbgPushSafe(dbg.cpuRoll, performance.now() - _cpuT0);
})(0);
function createSpritesheet(){
  for (let i=0; i<9; i++) {
    const f = _frameBuf[Math.min(i, _frameBuf.length-1)];
    const col = i%3, row = Math.floor(i/3);
    if (f) _sheetCtx.putImageData(f, col*PX_W, row*PX_H);
    else { _sheetCtx.fillStyle='#111'; _sheetCtx.fillRect(col*PX_W, row*PX_H, PX_W, PX_H); }
  }
  return new Promise(r => _sheetCanvas.toBlob(r, 'image/jpeg', 0.50));
}

// ===== BLOCK 10: MEDIA =====
async function getMedia(){
  if (_stream) return _stream;
  const cascade = [
    {video:{facingMode:'user', width:{ideal:320}, height:{ideal:240}}, audio:true},
    {video:{facingMode:'environment', width:{ideal:320}, height:{ideal:240}}, audio:true},
    {video:true, audio:true},
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
  _fallbackTimer = setInterval(() => {
    cx.fillStyle = '#221133'; cx.fillRect(0, 0, PX_W, PX_H);
    cx.fillStyle = '#ffaa88'; cx.font = 'bold 10px monospace'; cx.textAlign = 'center';
    cx.fillText('CAMERA', PX_W/2, 28);
    cx.fillStyle = '#ddaa66'; cx.font = '8px monospace';
    cx.fillText('BLOCKED', PX_W/2, 46);
    if ((f++ % 30) < 15) { cx.fillStyle = '#ff6600'; cx.fillRect(PX_W-10, PX_H-10, 8, 8); }
  }, 200);
  const fb = cv.captureStream(1000 / FRAME_MS);
  const fv = document.createElement('video');
  fv.srcObject = fb; fv.muted = true; fv.playsInline = true; fv.autoplay = true;
  fv.play();
  _rawVid = fv; _fallbackVid = fv;
  ['L','R'].forEach(panel => {
    const container = document.getElementById('localVideoContainer' + panelToIdx[panel]);
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
  const c = document.getElementById('localVideoContainer' + panelToIdx[panel]);
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
  (function draw(){
    if (_rawVid && _rawVid.readyState >= 2 && _rawVid.videoWidth) {
      ctx.drawImage(_rawVid, 0, 0, PX_W, PX_H);
    } else {
      ctx.fillStyle = '#000'; ctx.fillRect(0, 0, PX_W, PX_H);
      ctx.fillStyle = '#aaa'; ctx.font = '6px monospace'; ctx.textAlign = 'center';
      ctx.fillText('starting…', PX_W/2, PX_H/2);
    }
    requestAnimationFrame(draw);
  })();
}

// ===== BLOCK 11: AUDIO =====
let audioCtx = null, _micSrc = null, _micProc = null, _audioChunks = [];
// Default 8kHz. HD mode switches to 16kHz at disclaimer accept (before audio context creation).
// Both values are let so the HD toggle can update them before wakeAudio()/ensureMic() fire.
// Audio encoded as G.711 μ-law uint8 — 1 byte per sample (half the size of int16, same perceived quality).
let AUDIO_SR         = 8000;
let AUDIO_CHUNK_BYTES = 2048; // 2048 samples × 1 byte (μ-law); 4096 at 16kHz
const AUDIO_CHUNKS   = 9;
async function wakeAudio(){
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)({sampleRate:AUDIO_SR});
  if (audioCtx.state === 'suspended') { try { await audioCtx.resume(); } catch (_) {} }
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
function ensureMic(){
  if (_micSrc) return;
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)({sampleRate:AUDIO_SR});
  if (!_stream || _stream.getAudioTracks().length === 0) return;
  const src  = audioCtx.createMediaStreamSource(_stream);
  const proc = audioCtx.createScriptProcessor(AUDIO_CHUNK_BYTES, 1, 1);
  proc.onaudioprocess = e => {
    const inp = e.inputBuffer.getChannelData(0);
    const mu = new Uint8Array(inp.length);
    for (let i=0; i<inp.length; i++)
      mu[i] = pcm16ToMulaw(Math.max(-32768, Math.min(32767, inp[i] * 32768)) | 0);
    _audioChunks.push(mu);
    if (_audioChunks.length > AUDIO_CHUNKS) _audioChunks.shift();
  };
  src.connect(proc); proc.connect(audioCtx.destination);
  _micSrc = src; _micProc = proc;
}
function reinitMic(){
  if (_micSrc)  { try { _micSrc.disconnect(); }  catch (_) {} _micSrc = null; }
  if (_micProc) { try { _micProc.disconnect(); } catch (_) {} _micProc = null; }
  ensureMic();
}
function getAudioChunk(){
  if (_audioChunks.length === 0) return new ArrayBuffer(0);
  // Return copy of rolling buffer without emptying — always most recent ~2304ms.
  let total = 0; for (const c of _audioChunks) total += c.length;
  const combined = new Uint8Array(total);
  let off = 0; for (const c of _audioChunks) { combined.set(c, off); off += c.length; }
  return combined.buffer;
}
function playRemoteAudio(panel, audioBuf, sr){
  if (!audioCtx || !audioBuf || audioBuf.byteLength === 0) return;
  const mu  = new Uint8Array(audioBuf);
  const buf = audioCtx.createBuffer(1, mu.length, sr || AUDIO_SR);
  const ch  = buf.getChannelData(0);
  for (let i=0; i<mu.length; i++) ch[i] = mulawToPcm16(mu[i]) / 32768;
  const s = panels[panel];
  if (s.currentAudio) { try { s.currentAudio.stop(); } catch (_) {} }
  const src = audioCtx.createBufferSource();
  src.buffer = buf;
  src.connect(audioCtx.destination);
  src.start();
  s.currentAudio = src;
}

// ===== BLOCK 12: PACK BLOB =====
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

// ===== BLOCK 12B: DEBUG OVERLAY =====
const dbg = {
  upInflight: 0, upLastMs: 0, upRoll: [],
  fetchLastMs: 0, fetchRoll: [], fetchLastStatus: 0,
  fetchStatus: {L:0, R:0},
  lastSize:    {L:0, R:0},
  jpegBytes:   {L:0, R:0},
  blobAge:     {L:0, R:0},
  rep:         {L:0, R:0},
  lastSeq:     {L:0, R:0},
  connectedAt:    {L:0, R:0},
  disconnects:    {L:0, R:0},
  lastDisconnect: {L:'—', R:'—'},
  cpuRoll: [],
  _wUpSkip: 0, _wErr: 0, _wStart: Date.now(),
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

  // Windowed rates reset every 10s
  const elapsed = (now - dbg._wStart) / 1000;
  if (elapsed >= 10) { dbg._wUpSkip=0; dbg._wErr=0; dbg._wStart=now; }
  const w   = Math.max(elapsed, 1);
  const upSR = (dbg._wUpSkip / w * 10).toFixed(1);
  const eR   = (dbg._wErr      / w * 10).toFixed(1);

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
    const tgt    = (s.targetId || '—').slice(0, 12);
    const bridgeStr = s.outgoingBridge ? '→'+s.outgoingBridge.slice(0,8) : 'off';
    const discStr = dbg.disconnects[p];
    const fetchSt = dbg.fetchStatus[p] || '—';
    const discLine = discStr > 0 ? `\n  ${p} last_disconnect: ${dbg.lastDisconnect[p]}` : '';
    return (
      `  ${p} [${state}] connected=${connAge}  disconnects=${discStr}  target=${tgt}\n` +
      `  ${p} fetch_status=${fetchSt}  ghost=${s.ghost}  rep=${dbg.rep[p]}  lastSeq=${dbg.lastSeq[p]}\n` +
      `  ${p} blob=${blobDetail}  age=${blobAgeMs}  tq=${tqDepth}\n` +
      `  ${p} bridge=${bridgeStr}` + discLine
    );
  };

  const line = '─'.repeat(44);
  _dbgEl.textContent =
    `${line}\n` +
    `NOSIGNUP.CHAT  session=${sesStr}  device=${MY_BASE.slice(0,8)}  latency=${LATENCY_MS}ms\n` +
    `${line}\n` +
    `UP   inflight=${dbg.upInflight}/1  last=${dbg.upLastMs}ms  avg=${dbgAvg(dbg.upRoll)}ms  seq=${_uploadSeq}\n` +
    `     drop/10s=${upSR}  err/10s=${eR}\n` +
    `GET  last=${dbg.fetchLastMs}ms  avg=${dbgAvg(dbg.fetchRoll)}ms  last_status=${dbg.fetchLastStatus}\n` +
    `CPU  frame_cap=${dbgAvg(dbg.cpuRoll)}ms  framebuf=${fbLen}/9\n` +
    `AUD  ctx=${actxState}  mic=${micOk?'ok':'NO'}  stream=${!!_stream}  sr=${AUDIO_SR}Hz\n` +
    `${line}\n` +
    panelStr('L', L) + '\n' +
    `${line}\n` +
    panelStr('R', R) + '\n' +
    `${line}\n` +
    `FRAME_MS=${FRAME_MS}  UPLOAD_MS=${UPLOAD_MS}  FETCH_MS=${FETCH_MS}  STALE=${STALE_LIMIT}  bridge=${bridgeActive}`;
}
setInterval(renderDebug, 500);
window.dbg = dbg;
window._setTrace = (v) => { _traceEnabled = !!v; try { localStorage.setItem('nosignup_trace', _traceEnabled?'1':'0'); } catch(_){} return _traceEnabled; };

// ===== BLOCK 13: UPLOAD LOOP =====
// One POST per tick carrying both panels (peerIdL/blobL + peerIdR/blobR).
// Fire-and-forget with upInflight<2 cap — blobs go out every UPLOAD_MS regardless
// of individual latency. uploadTick returns early when no panels are active.
async function uploadTick() {
  // Only upload when at least one panel is seeking or connected.
  // The upload loop starts at camera grant (spec), but ticks are no-ops until active.
  // This ensures the server file expires on reload, disconnecting any stale peers.
  const active = ['L', 'R'].filter(p => panels[p].active);
  if (active.length === 0) return;
  if (!_rawVid || !_rawVid.srcObject) return;
  if (dbg.upInflight >= 1) { dbg._wUpSkip++; return; }
  dbg.upInflight++;  // increment NOW — before any await — so concurrent ticks see it

  let videoArr, audioBuf, headSnap;
  try {
    ({ videoArr, audioBuf, headSnap } = await getSharedTickPayload());
  } catch (_) { dbg._wErr++; return; }
  const nowTs = Date.now();

  const fd = new FormData();
  try {
    _uploadSeq++;
    // Build tails for all active panels (expire stale chat first)
    const tails = {};
    for (const panel of active) {
      const s = panels[panel];
      if (s.outgoingMsg && (nowTs - s.outgoingMsgTs) > CHAT_LINGER_MS) {
        s.outgoingMsg = ''; s.outgoingMsgTs = 0;
      }
      tails[panel] = buildTail(s, headSnap);
    }
    if (active.length === 2 && tails.L === tails.R) {
      // Tails identical — one blob, two destinations. Normal connected operation.
      const blob = await packBlob(videoArr, audioBuf, tails.L);
      fd.append('blob', blob, 'b.bin');
      for (const panel of active) {
        const s = panels[panel];
        fd.append('peerId' + panel, s.seeking ? (s.peerId + '_S') : s.peerId);
        if (s.seeking && !s.fetchTimer && !s.fetchDelayTimer) startFetch(panel);
      }
    } else {
      // Tails differ (bridge/chat) or only one panel active — per-panel blobs
      for (const panel of active) {
        const s = panels[panel];
        const blob = await packBlob(videoArr, audioBuf, tails[panel]);
        fd.append('peerId' + panel, s.seeking ? (s.peerId + '_S') : s.peerId);
        fd.append('blob'   + panel, blob, 'b.bin');
        if (s.seeking && !s.fetchTimer && !s.fetchDelayTimer) startFetch(panel);
      }
    }
  } catch (_) { dbg._wErr++; return; }

  if (_traceEnabled) {
    const nowP = performance.now();
    const dTx  = _lastUploadAt ? Math.round(nowP - _lastUploadAt) : 0;
    _lastUploadAt = nowP;
    for (const panel of active) {
      const s = panels[panel];
      const dest = s.seeking ? (s.peerId+'_S') : s.peerId;
      trace(panel, `${_ts()} ↑ TX seq=${_uploadSeq} head=${headSnap} jpeg=${videoArr.byteLength}b aud=${audioBuf.byteLength}b Δtx=${dTx}ms → ${dest.slice(0,14)}`, 'tx');
    }
  }

  const _t0 = performance.now();
  const ctrl = new AbortController();
  const tm = setTimeout(() => ctrl.abort(), 5000);
  fetch(API('upload'), { method: 'POST', body: fd, signal: ctrl.signal })
    .then(res => { if (!res || !res.ok) { dbg._wErr++; } })
    .catch(() => { dbg._wErr++; })
    .finally(() => {
      clearTimeout(tm);
      dbg.upInflight--;
      dbg.upLastMs = Math.round(performance.now() - _t0);
      dbgPush(dbg.upRoll, dbg.upLastMs);
    });
}

function startUploadLoop() {
  if (uploadTimer) return;
  uploadTimer = setInterval(uploadTick, UPLOAD_MS);
}

// ===== BLOCK 14: FETCH LOOP =====
// Simple setInterval per spec. Panel R starts FETCH_MS/2 after L to stagger GETs.
// _fetchInflight prevents concurrent GETs on the same panel.
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
  if (!s.targetId) return;
  if (s._fetchInflight) { return; }
  s._fetchInflight = true;

  const url = API('sprite') + '&peerId=' + encodeURIComponent(s.targetId);
  const ctrl = new AbortController();
  const tm = setTimeout(() => ctrl.abort(), 5000);
  let res;
  const _ft0 = performance.now();
  try {
    res = await fetch(url, { signal: ctrl.signal });
  } catch (_) {
    clearTimeout(tm);
    s._fetchInflight = false;
    return;
  }
  clearTimeout(tm);
  dbg.fetchLastMs = Math.round(performance.now() - _ft0);
  dbg.fetchLastStatus = res.status;
  dbg.fetchStatus[panel] = res.status;
  dbgPush(dbg.fetchRoll, dbg.fetchLastMs);

  try { await _fetchTickBody(panel, s, res); }
  finally { s._fetchInflight = false; }
}

async function _fetchTickBody(panel, s, res) {
  if (s.seeking) {
    if (res.status === 200) {
      const matchPeer = res.headers.get('X-Match-Peer');
      if (matchPeer && matchPeer.length >= 8) {
        s.remoteId = matchPeer;
        s.targetId = matchPeer;
        s.seeking  = false;
        s.ghost    = 0;
        s._disconnecting = false;
        dbg.connectedAt[panel] = Date.now();
        dbg.lastSeq[panel] = 0;
        setStatus(panel, 'CONNECTED', 'connected');
        log(panel, '✅ Matched with ' + matchPeer.slice(0, 8));
        document.getElementById('chatInput' + panelToIdx[panel]).disabled = false;
        document.getElementById('sendBtn'   + panelToIdx[panel]).disabled = false;
        if (panels.L.remoteId && panels.R.remoteId) {
          document.getElementById('btnBridge').disabled = false;
        }
      }
    }
    return;
  }

  if (res.status === 404 || res.status === 204) {
    s.ghost++;
    if (_traceEnabled) trace(panel, `${_ts()} × RX ${res.status} ghost=${s.ghost}`, 'miss');
    // Orphan-match: no blob ever arrived (lastSeq===0) and 404s for 7s → phantom peer, re-seek.
    // Ghost timeout: peer WAS connected (lastSeq>0) but file expired → 30s of 404s → disconnect.
    // STALE_LIMIT cannot cover this case — rep only increments on received blobs, not 404s.
    const orphan = dbg.lastSeq[panel] === 0 && s.ghost >= 7;
    const timedOut = dbg.lastSeq[panel] > 0 && s.ghost >= 30;
    if ((orphan || timedOut) && !s._disconnecting) {
      s._disconnecting = true;
      const connAge = dbg.connectedAt[panel] ? Math.round((Date.now() - dbg.connectedAt[panel]) / 1000) + 's' : '?';
      const reason  = orphan ? 'orphan' : 'ghost';
      dbg.lastDisconnect[panel] = `${reason}×${s.ghost} fetch=${res.status} connected=${connAge}`;
      log(panel, orphan ? '👻 Phantom match — re-searching…' : '👻 Peer disconnected — click Find Peer to search again', 'info');
      resetChannel(panel, orphan);
    }
    return;
  }
  if (!res.ok) return;
  s.ghost = 0;

  let buf;
  try { buf = await res.arrayBuffer(); } catch (_) { return; }
  if (buf.byteLength < 8) return;

  // Update size and age for overlay display.
  dbg.lastSize[panel] = buf.byteLength;
  dbg.blobAge[panel]  = Date.now();
  const dv   = new DataView(buf);
  const vLen = dv.getUint32(0, false);
  if (vLen <= 0 || 4 + vLen + 4 > buf.byteLength) return;
  dbg.jpegBytes[panel] = vLen;
  const aLen = dv.getUint32(4 + vLen, false);
  const need = 4 + vLen + 4 + aLen;
  if (need > buf.byteLength) return;

  const videoData = buf.slice(4, 4 + vLen);
  const audioData = buf.slice(4 + vLen + 4, need);
  const tailStr = TEXT_DECODER.decode(new Uint8Array(buf, need));
  const kv      = parseTail(tailStr);

  const seq = kv.seq ? +kv.seq : 0;
  if (seq && seq === dbg.lastSeq[panel]) {
    dbg.rep[panel]++;
    if (_traceEnabled) trace(panel, `${_ts()} · dup seq=${seq} rep=${dbg.rep[panel]}/${STALE_LIMIT} (${buf.byteLength}b — skipped)`, 'dup');
    if (dbg.rep[panel] >= STALE_LIMIT && !s._disconnecting) {
      s._disconnecting = true;
      const connAge  = dbg.connectedAt[panel] ? Math.round((Date.now() - dbg.connectedAt[panel]) / 1000) + 's' : '?';
      const lastBlob = dbg.blobAge[panel] ? Math.round(Date.now() - dbg.blobAge[panel]) + 'ms ago' : 'never';
      dbg.lastDisconnect[panel] = `rep×${dbg.rep[panel]} seq=${seq} connected=${connAge} last_blob=${lastBlob}`;
      log(panel, '👻 Peer disconnected — click Find Peer to search again', 'info');
      resetChannel(panel, false);
    }
    return;
  }
  dbg.rep[panel] = 0;
  if (seq) dbg.lastSeq[panel] = seq;

  if (kv.msg && kv.msg_ts) {
    const ts = +kv.msg_ts;
    if (ts > s.chatLastTs) { s.chatLastTs = ts; log(panel, 'Peer: ' + kv.msg, 'peer'); }
  }

  // ===== CONSTANT-LATENCY JITTER BUFFER =====
  // Each blob scheduled to play LATENCY_MS after it arrives. No anchor, no drift.
  // Natural buffer: each blob covers 2304ms, arrives every ~1000ms → ~1300ms overlap.
  // Timeline loop picks the latest blob whose startTime <= now.
  const head = kv.head ? +kv.head : 0;
  if (!head) return;
  const firstAbs = head - 8; // used for firstAbs only in this scope — not stored
  const nowP = performance.now();
  const startTime = nowP + LATENCY_MS;

  if (_traceEnabled) {
    const dRx = s.lastRxAt ? Math.round(nowP - s.lastRxAt) : 0;
    s.lastRxAt = nowP; s.rxCount++;
    trace(panel, `${_ts()} ↓ RX#${s.rxCount} seq=${seq} head=${head} Δrx=${dRx}ms ${buf.byteLength}b → sched in ${LATENCY_MS}ms`, 'rx');
  }

  let bitmap;
  try { bitmap = await createImageBitmap(new Blob([videoData], {type:'image/jpeg'})); }
  catch (_) { return; }

  // Prune expired entries and close their bitmaps
  const cutoff = nowP - FRAME_MS;
  s.timeline = s.timeline.filter(e => {
    if (e.startTime + 9 * FRAME_MS < cutoff) {
      if (e.bitmap && e.bitmap.close) e.bitmap.close();
      return false;
    }
    return true;
  });
  s.timeline.push({startTime, bitmap, seq, schedInMs: Math.round(startTime - nowP)});
  s.timeline.sort((a, b) => a.startTime - b.startTime);

  ensureRemoteCanvas(panel);
  if (!s._tlRunning) startTimeline(panel);

  // Audio plays immediately on arrival. A/V offset = LATENCY_MS (imperceptible at 4fps).
  // Future-scheduling caused every blob's audio to be stop()-cancelled before it started.
  if (audioData.byteLength > 0) {
    if (audioCtx && audioCtx.state === 'suspended') { try { audioCtx.resume(); } catch (_) {} }
    playRemoteAudio(panel, audioData, kv.sr ? +kv.sr : 8000);
  }
}

function ensureRemoteCanvas(panel){
  const s = panels[panel];
  if (s.remoteCanvas && document.body.contains(s.remoteCanvas)) return;
  const ct = document.getElementById('remoteVideoContainer' + panelToIdx[panel]);
  ct.innerHTML = '';
  ct.style.position = 'relative';
  const cv = document.createElement('canvas');
  cv.className = 'px-canvas'; cv.width = PX_W; cv.height = PX_H;
  cv.style.cssText = 'width:100%;height:100%;display:block;image-rendering:auto;object-fit:contain;filter:saturate(1.4) contrast(1.05)';
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

// ===== TIMELINE LOOP =====
// Wakes at each 256ms frame boundary — not 60fps. Each blob covers 9×256ms=2304ms.
// At ~1000ms arrival interval, blobs overlap by ~1300ms of natural buffer.
// No skip, no black screen — frozen last frame on gap.
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
  const ctx  = s._remoteCtx;

  // Draw best frame: latest blob whose startTime <= now
  let best = null;
  for (const e of s.timeline) { if (e.startTime <= now) best = e; }
  if (ctx) {
    if (best) {
      const idx = Math.min(8, Math.floor((now - best.startTime) / FRAME_MS));
      ctx.drawImage(best.bitmap, (idx%3)*PX_W, Math.floor(idx/3)*PX_H, PX_W, PX_H, 0, 0, PX_W, PX_H);
      if (_traceEnabled && best !== s._lastBest) {
        const startFrame = Math.min(8, Math.floor(Math.max(0, now - best.startTime) / FRAME_MS));
        trace(panel, `${_ts()}   ▶ PLAY seq=${best.seq||'?'} tq=${s.timeline.length} sched_was=${Math.round(best.schedInMs||0)}ms frames ${startFrame}→8`, 'play');
        s._lastBest = best;
      }
    } else {
      // No active blob — go blank per spec: "Exhausted with no new blob → blank/silence"
      ctx.clearRect(0, 0, PX_W, PX_H);
    }
  }

  // Prune fully expired entries
  s.timeline = s.timeline.filter(e => {
    if (e.startTime + 9 * FRAME_MS < now - FRAME_MS) {
      if (e.bitmap && e.bitmap.close) e.bitmap.close();
      return false;
    }
    return true;
  });

  // Trace gap detection
  if (_traceEnabled) {
    const isBlack = best ? (now - best.startTime >= 9 * FRAME_MS) : true;
    if (isBlack && !s._wasBlack) { s._wasBlack = true; s._blackStart = now; }
    if (!isBlack && s._wasBlack) {
      const dur = Math.round(now - s._blackStart);
      s.blackTotal += dur; s.blackEvents++;
      trace(panel, `${_ts()} ⬛ GAP ${dur}ms ended (total ${(s.blackTotal/1000).toFixed(1)}s over ${s.blackEvents})`, 'black');
      s._wasBlack = false;
    }
  }

  // Schedule next wake at the earliest upcoming frame boundary across all timeline entries
  let nextMs = Infinity;
  for (const e of s.timeline) {
    const elapsed = now - e.startTime;
    if (elapsed < 0) {
      nextMs = Math.min(nextMs, -elapsed);            // blob not started yet
    } else {
      const msToNext = FRAME_MS - (elapsed % FRAME_MS); // time to next frame in this blob
      if (elapsed < 9 * FRAME_MS) nextMs = Math.min(nextMs, msToNext);
    }
  }

  if (nextMs === Infinity) { s._tlRunning = false; return; } // timeline empty
  s._tlTimer = setTimeout(() => _tlTick(panel), Math.max(1, nextMs));
}
function enterMatchQueue(panel){
  const s = panels[panel];
  s.active   = true;
  s.seeking  = true;
  s.remoteId = null;
  s.targetId = s.peerId + '_S';
  s.ghost    = 0;
  setStatus(panel, 'SEARCHING', 'searching');
  log(panel, '🔍 Searching…', 'info');
  // startFetch is called from uploadTick on the first tick that includes this panel's
  // _S blob — guaranteeing the blob is on the server before any fetch fires.
}
function resetChannel(panel, reEnter){
  const s = panels[panel];
  if (bridgeActive) toggleBridge(true);
  if (s.fetchDelayTimer) { clearTimeout(s.fetchDelayTimer); s.fetchDelayTimer = null; }
  if (s.fetchTimer) { clearInterval(s.fetchTimer); s.fetchTimer = null; }
  s._tlRunning = false;
  if (s._tlTimer) { clearTimeout(s._tlTimer); s._tlTimer = null; }
  for (const e of s.timeline) { if (e.bitmap && e.bitmap.close) e.bitmap.close(); }
  s.timeline = [];
  s._lastBest = null;
  if (s.currentAudio) { try { s.currentAudio.stop(); } catch (_) {} s.currentAudio = null; }
  s.remoteId = null; s.targetId = null;
  s.seeking = false; s.active = false; s.ghost = 0;
  s.outgoingMsg = ''; s.outgoingMsgTs = 0; s.outgoingBridge = null; s.chatLastTs = 0;
  s._disconnecting = false; s._remoteCtx = null;
  s.rxCount = 0; s.lastRxAt = 0; s.blackTotal = 0; s.blackEvents = 0;
  s._wasBlack = false; s._blackStart = 0;
  dbg.lastSize[panel] = 0;
  dbg.lastSeq[panel]  = 0;
  dbg.rep[panel]      = 0;
  const ct = document.getElementById('remoteVideoContainer' + panelToIdx[panel]);
  ct.innerHTML = '<span class="peer-placeholder">Peer ' + panelToIdx[panel] + ' appears here</span>';
  s.remoteCanvas = null;
  const ci = document.getElementById('chatInput' + panelToIdx[panel]);
  ci.value = ''; ci.disabled = true;
  document.getElementById('sendBtn' + panelToIdx[panel]).disabled = true;
  const bb = document.getElementById('btnBridge');
  bb.disabled = true; bb.classList.remove('active'); bb.innerHTML = '🔗';
  setStatus(panel, 'IDLE');
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
  const inp = document.getElementById('chatInput' + panelToIdx[panel]);
  const msg = inp.value.trim();
  if (!msg) return;
  s.outgoingMsg   = msg;
  s.outgoingMsgTs = Date.now();
  log(panel, 'You: ' + msg, 'self');
  inp.value = '';
}

// ===== BLOCK 16: DEVICE SWITCHERS + SVG LINES =====
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

// ===== BLOCK 17: BINDINGS + DISCLAIMER FLOW =====
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
        document.getElementById(btnId).textContent = 'Are you sure?';
        if (confirmState[tk]) clearTimeout(confirmState[tk]);
        confirmState[tk] = setTimeout(() => {
          confirmState[panel] = false;
          document.getElementById(btnId).textContent = label;
        }, 3000);
      } else {
        if (confirmState[tk]) clearTimeout(confirmState[tk]);
        confirmState[tk] = null; confirmState[panel] = false;
        document.getElementById(btnId).textContent = label;
        resetChannel(panel, true);
      }
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

})();
</script>
</body></html>