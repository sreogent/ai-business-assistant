<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Public Direct Web Call Page
 * Позволяет клиенту общаться с менеджером по голосовой связи прямо в браузере без регистрации.
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Security.php';
require_once __DIR__ . '/includes/AgoraService.php';

$channel = trim($_GET['channel'] ?? $_GET['room'] ?? '');
$channel = preg_replace('/[^a-zA-Z0-9_\-]/', '', $channel);
$name = Security::sanitize(trim($_GET['name'] ?? 'Клиент'));

if (empty($channel)) {
    $channel = 'call_guest_' . time();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Голосовой звонок — AI Business Assistant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://download.agora.io/sdk/release/AgoraRTC_N-4.22.0.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0b0f19;
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .call-card {
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 24px;
            width: 100%;
            max-width: 420px;
            padding: 36px 28px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            position: relative;
            overflow: hidden;
        }
        .avatar-box {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            color: #fff;
            box-shadow: 0 0 35px rgba(99,102,241,0.35);
            animation: pulse-avatar 2s infinite ease-in-out;
        }
        @keyframes pulse-avatar {
            0% { transform: scale(1); box-shadow: 0 0 25px rgba(99,102,241,0.3); }
            50% { transform: scale(1.05); box-shadow: 0 0 45px rgba(99,102,241,0.6); }
            100% { transform: scale(1); box-shadow: 0 0 25px rgba(99,102,241,0.3); }
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 24px;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #f59e0b;
        }
        .timer {
            font-size: 36px;
            font-weight: 800;
            color: #38bdf8;
            margin-bottom: 30px;
            font-variant-numeric: tabular-nums;
        }
        .controls {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        .btn-call {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            transition: all 0.2s;
        }
        .btn-start {
            background: #10b981;
            color: #fff;
            box-shadow: 0 8px 25px rgba(16,185,129,0.4);
        }
        .btn-start:hover { transform: scale(1.08); background: #059669; }
        .btn-hangup {
            background: #ef4444;
            color: #fff;
            box-shadow: 0 8px 25px rgba(239,68,68,0.4);
        }
        .btn-hangup:hover { transform: scale(1.08); background: #dc2626; }
        .btn-mic {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .btn-mic:hover { background: rgba(255,255,255,0.2); }
        .btn-mic.muted {
            background: #f59e0b;
            color: #000;
        }
    </style>
</head>
<body>

<div class="call-card">
    <div class="avatar-box">
        <i class="fas fa-headset"></i>
    </div>

    <h2 style="font-size:20px; font-weight:700; margin-bottom:6px;">Связь с менеджером</h2>
    <div style="font-size:13px; color:#94a3b8; margin-bottom:14px;">Комната: <?= htmlspecialchars($channel) ?></div>

    <div class="status-badge">
        <span class="status-dot" id="dot"></span>
        <span id="status-text">Нажмите «Начать разговор»</span>
    </div>

    <div class="timer" id="timer">00:00</div>

    <div class="controls">
        <button id="btn-start" class="btn-call btn-start" onclick="connectCall()" title="Начать разговор">
            <i class="fas fa-phone"></i>
        </button>

        <button id="btn-mic" class="btn-call btn-mic" onclick="toggleMic()" style="display:none;" title="Микрофон">
            <i class="fas fa-microphone" id="mic-icon"></i>
        </button>

        <button id="btn-hangup" class="btn-call btn-hangup" onclick="hangup()" style="display:none;" title="Завершить">
            <i class="fas fa-phone-slash"></i>
        </button>
    </div>
</div>

<script>
const CHANNEL = "<?= htmlspecialchars($channel, ENT_QUOTES) ?>";
let agoraClient = null;
let localTrack = null;
let isMuted = false;
let timerInt = null;
let seconds = 0;

async function connectCall() {
    document.getElementById('status-text').innerText = "Подключение к связи...";
    document.getElementById('btn-start').style.display = 'none';

    try {
        // 1. Запрашиваем токен с сервера
        const resp = await fetch('/api/agora_token.php?guest=1&channel_name=' + encodeURIComponent(CHANNEL));
        const data = await resp.json();

        if (!data.success) {
            alert("Ошибка получения доступа: " + data.message);
            location.reload();
            return;
        }

        // 2. Инициализируем Agora RTC
        agoraClient = AgoraRTC.createClient({ mode: "rtc", codec: "vp8" });

        agoraClient.on("user-published", async (user, mediaType) => {
            await agoraClient.subscribe(user, mediaType);
            if (mediaType === "audio") {
                user.audioTrack.play();
                document.getElementById('status-text').innerText = "В эфире (собеседник подключен)";
                document.getElementById('dot').style.background = "#10b981";
                startTimer();
            }
        });

        agoraClient.on("user-unpublished", () => {
            document.getElementById('status-text').innerText = "Собеседник отключился";
            document.getElementById('dot').style.background = "#f59e0b";
        });

        // 3. Подключаемся к каналу
        await agoraClient.join(data.app_id, data.channel_name, data.token, 0);

        // 4. Захватываем микрофон
        localTrack = await AgoraRTC.createMicrophoneAudioTrack();
        await agoraClient.publish([localTrack]);

        document.getElementById('status-text').innerText = "Ожидание собеседника...";
        document.getElementById('btn-mic').style.display = 'flex';
        document.getElementById('btn-hangup').style.display = 'flex';
    } catch (e) {
        console.error(e);
        alert("Ошибка доступа к микрофону: " + e.message);
        location.reload();
    }
}

function toggleMic() {
    if (!localTrack) return;
    isMuted = !isMuted;
    localTrack.setEnabled(!isMuted);
    document.getElementById('mic-icon').className = isMuted ? "fas fa-microphone-slash" : "fas fa-microphone";
    document.getElementById('btn-mic').className = isMuted ? "btn-call btn-mic muted" : "btn-call btn-mic";
}

async function hangup() {
    if (localTrack) {
        localTrack.stop();
        localTrack.close();
        localTrack = null;
    }
    if (agoraClient) {
        await agoraClient.leave();
        agoraClient = null;
    }
    clearInterval(timerInt);
    document.getElementById('status-text').innerText = "Разговор завершён";
    document.getElementById('dot').style.background = "#ef4444";
    document.getElementById('btn-mic').style.display = 'none';
    document.getElementById('btn-hangup').style.display = 'none';
    setTimeout(() => { location.reload(); }, 2000);
}

function startTimer() {
    if (timerInt) return;
    seconds = 0;
    timerInt = setInterval(() => {
        seconds++;
        const m = String(Math.floor(seconds / 60)).padStart(2, '0');
        const s = String(seconds % 60).padStart(2, '0');
        document.getElementById('timer').innerText = `${m}:${s}`;
    }, 1000);
}
</script>

</body>
</html>
