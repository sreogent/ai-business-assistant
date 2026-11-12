/**
 * Corporate Chat Telephony, Direct Calling & Voice Notes Engine v3
 * Powered by Agora RTC & Web Audio API
 * 
 * Features:
 * - Direct 1-on-1 Phone Calls with realistic Dial Tone (гудки) & Ringtone (звонок)
 * - Corporate Channel Voice/Video Meetings with Camera & Microphone selection
 * - Live participant tiles, avatars & mute indicators
 * - Voice Notes (голосовые сообщения) playback engine
 * - NO CALL RECORDING (recording completely removed per configuration)
 * - Global background detector for incoming calls across any page
 */

(function () {
    'use strict';

    /* ─────────────────── Audio Synthesis (Гудки, Рингтон, Занято) ─────────────────── */
    let ringAudioCtx = null;
    let ringOscillators = [];
    let dialToneTimer = null;
    let ringToneTimer = null;
    let titleBlinkInterval = null;
    let originalDocTitle = document.title;
    let directCallPollInterval = null;

    function isAbortError(err) {
        if (!err) return false;
        const msg = String(err.message || (err.toString ? err.toString() : '') || err || '').toUpperCase();
        const code = String(err.code || '').toUpperCase();
        const name = String(err.name || '').toUpperCase();
        return msg.includes('WS_ABORT') || msg.includes('ABORT') || msg.includes('LEAVE') ||
               code.includes('WS_ABORT') || code.includes('ABORT') || name.includes('ABORT');
    }

    function getAudioContext() {
        if (!ringAudioCtx || ringAudioCtx.state === 'closed') {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (AudioCtx) ringAudioCtx = new AudioCtx();
        }
        if (ringAudioCtx && ringAudioCtx.state === 'suspended') {
            ringAudioCtx.resume().catch(() => {});
        }
        return ringAudioCtx;
    }

    // Реалистичные длинные телефонные гудки ожидания ответа (425 Гц, 1 сек гудок / 3 сек пауза)
    window.playDialTone = function() {
        stopAllTones();
        const ctx = getAudioContext();
        if (!ctx) return;

        function beep() {
            try {
                if (ctx.state === 'suspended') ctx.resume();
                const now = ctx.currentTime;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();

                osc.type = 'sine';
                osc.frequency.setValueAtTime(425, now);

                // Плавное нарастание и затухание для мягкого звука трубки
                gain.gain.setValueAtTime(0.001, now);
                gain.gain.exponentialRampToValueAtTime(0.12, now + 0.05);
                gain.gain.setValueAtTime(0.12, now + 1.15);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 1.2);

                osc.connect(gain);
                gain.connect(ctx.destination);

                osc.start(now);
                osc.stop(now + 1.25);
            } catch (e) {}
        }

        beep();
        dialToneTimer = setInterval(beep, 4000); // цикл гудков
    };

    window.stopDialTone = function() {
        if (dialToneTimer) {
            clearInterval(dialToneTimer);
            dialToneTimer = null;
        }
    };

    // Мелодичный звонок входящего вызова (красивый телефонный рингтон)
    window.playIncomingRingtone = function() {
        stopAllTones();
        const ctx = getAudioContext();
        if (!ctx) return;

        function chime() {
            try {
                if (ctx.state === 'suspended') ctx.resume();
                const now = ctx.currentTime;
                // Мелодия телефона: C5 -> E5 -> G5 -> C6
                const freqs = [523.25, 659.25, 783.99, 1046.50];
                freqs.forEach((f, idx) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();

                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(f, now + idx * 0.12);

                    gain.gain.setValueAtTime(0.001, now + idx * 0.12);
                    gain.gain.exponentialRampToValueAtTime(0.16, now + idx * 0.12 + 0.04);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.12 + 0.45);

                    osc.connect(gain);
                    gain.connect(ctx.destination);

                    osc.start(now + idx * 0.12);
                    osc.stop(now + idx * 0.12 + 0.5);
                });
            } catch (e) {}
        }

        chime();
        ringToneTimer = setInterval(chime, 2500);
    };

    window.stopIncomingRingtone = function() {
        if (ringToneTimer) {
            clearInterval(ringToneTimer);
            ringToneTimer = null;
        }
        stopTitleBlink();
    };

    // Короткие гудки отбоя / занято ("пик-пик-пик", 425 Гц, 0.35s звук / 0.35s пауза)
    window.playBusyTone = function(durationMs = 2100) {
        stopAllTones();
        const ctx = getAudioContext();
        if (!ctx) return;

        let count = 0;
        const maxBeeps = 4;
        const iv = setInterval(() => {
            if (count >= maxBeeps) {
                clearInterval(iv);
                return;
            }
            count++;
            try {
                const now = ctx.currentTime;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();

                osc.type = 'sine';
                osc.frequency.setValueAtTime(425, now);

                gain.gain.setValueAtTime(0.15, now);
                gain.gain.setValueAtTime(0.15, now + 0.3);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

                osc.connect(gain);
                gain.connect(ctx.destination);

                osc.start(now);
                osc.stop(now + 0.36);
            } catch (e) {}
        }, 650);

        setTimeout(() => clearInterval(iv), durationMs);
    };

    function stopAllTones() {
        window.stopDialTone();
        window.stopIncomingRingtone();
        stopTitleBlink();
    }

    function startTitleBlink(text) {
        stopTitleBlink();
        let flag = false;
        titleBlinkInterval = setInterval(() => {
            document.title = flag ? `🔔 ${text}` : `📞 [ЗВОНОК] ${originalDocTitle}`;
            flag = !flag;
        }, 750);
    }

    function stopTitleBlink() {
        if (titleBlinkInterval) {
            clearInterval(titleBlinkInterval);
            titleBlinkInterval = null;
        }
        document.title = originalDocTitle;
    }

    function showDesktopNotification(title, body) {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'granted') {
            new Notification(title, { body, icon: '/assets/images/logo.png', tag: 'incoming-call' });
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission();
        }
    }

    /* ─────────────────── Agora SDK Loader ─────────────────── */
    function loadAgoraSDK() {
        return new Promise((resolve, reject) => {
            if (window.AgoraRTC) return resolve(window.AgoraRTC);
            const script = document.createElement('script');
            script.src = 'https://download.agora.io/sdk/release/AgoraRTC_N-4.20.0.js';
            script.async = true;
            script.onload = () => resolve(window.AgoraRTC);
            script.onerror = () => reject(new Error('Не удалось загрузить Agora RTC SDK'));
            document.head.appendChild(script);
        });
    }

    /* ─────────────────── Media Devices (Микрофон / Камера) ─────────────────── */
    async function enumerateMediaDevices() {
        try {
            await navigator.mediaDevices.getUserMedia({ audio: true, video: true });
        } catch (e) {
            try { await navigator.mediaDevices.getUserMedia({ audio: true }); } catch (e2) {}
        }
        const devices = await navigator.mediaDevices.enumerateDevices();
        return {
            mics: devices.filter(d => d.kind === 'audioinput'),
            cams: devices.filter(d => d.kind === 'videoinput')
        };
    }

    /* ─────────────────── Global Call State ─────────────────── */
    let agoraClient = null;
    let localAudioTrack = null;
    let localVideoTrack = null;
    let remoteUsers = {}; // uid -> { audioTrack, videoTrack, name }
    let activeCallSession = null;
    let callTimerInterval = null;
    let callSeconds = 0;
    let dismissedCallIds = new Set();
    let selectedMicId = null;
    let selectedCamId = null;
    let cameraEnabled = false;

    /* ═══════════════════════════════════════════════════════════
       1. GROUP CHANNEL CALLS (Созвоны в каналах чатов)
    ═══════════════════════════════════════════════════════════ */
    window.startChatCall = async function (channelKey, channelTitle) {
        if (activeCallSession) {
            showToast('У вас уже есть активный звонок!', 'warn');
            return;
        }
        await loadAgoraSDK();
        const devices = await enumerateMediaDevices();
        showCallStartModal(channelKey, channelTitle, devices);
    };

    function showCallStartModal(channelKey, channelTitle, devices) {
        removeElement('callConfigModal');
        const micOptions = devices.mics.map((d, i) =>
            `<option value="${escapeHtml(d.deviceId)}">${escapeHtml(d.label || `Микрофон ${i + 1}`)}</option>`
        ).join('');
        const camOptions = devices.cams.map((d, i) =>
            `<option value="${escapeHtml(d.deviceId)}">${escapeHtml(d.label || `Камера ${i + 1}`)}</option>`
        ).join('');

        const modal = document.createElement('div');
        modal.id = 'callConfigModal';
        modal.style.cssText = `
            position: fixed; inset: 0; z-index: 10100;
            display: flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.75); backdrop-filter: blur(8px);
        `;
        modal.innerHTML = `
            <div style="background: linear-gradient(145deg, #0f172a, #1e293b);
                        border: 1px solid rgba(99,102,241,0.4); border-radius: 20px;
                        padding: 26px 30px; width: 420px; max-width: 95vw; color: #f8fafc;
                        box-shadow: 0 25px 60px rgba(0,0,0,0.7);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #8b5cf6);
                                display: flex; align-items: center; justify-content: center; font-size: 22px;">📞</div>
                    <div>
                        <div style="font-size: 17px; font-weight: 700;">Начать созвон</div>
                        <div style="font-size: 12px; color: #94a3b8;">#${escapeHtml(channelTitle)}</div>
                    </div>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; display: block; margin-bottom: 6px;">
                        🎙️ Микрофон
                    </label>
                    <select id="cfgMicSelect" style="width: 100%; padding: 9px 12px; background: #1e293b; border: 1px solid rgba(255,255,255,0.12);
                            border-radius: 10px; color: #f1f5f9; font-size: 13px; outline: none;">
                        ${micOptions || '<option value="">Микрофон по умолчанию</option>'}
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; display: block; margin-bottom: 6px;">
                        📷 Камера
                    </label>
                    <select id="cfgCamSelect" style="width: 100%; padding: 9px 12px; background: #1e293b; border: 1px solid rgba(255,255,255,0.12);
                            border-radius: 10px; color: #f1f5f9; font-size: 13px; outline: none;">
                        <option value="">Без камеры (только голос)</option>
                        ${camOptions}
                    </select>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button onclick="document.getElementById('callConfigModal').remove()"
                        style="flex: 1; padding: 11px; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.1);
                               border-radius: 12px; color: #94a3b8; font-size: 13px; font-weight: 600; cursor: pointer;">
                        Отмена
                    </button>
                    <button id="btnConfirmStartCall"
                        style="flex: 2; padding: 11px; background: linear-gradient(135deg, #22c55e, #16a34a); border: none;
                               border-radius: 12px; color: #fff; font-size: 14px; font-weight: 700; cursor: pointer;">
                        📞 Начать созвон
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        document.getElementById('btnConfirmStartCall').onclick = async () => {
            selectedMicId = document.getElementById('cfgMicSelect').value || null;
            const camVal = document.getElementById('cfgCamSelect').value;
            selectedCamId = camVal || null;
            cameraEnabled = !!camVal;
            removeElement('callConfigModal');

            try {
                const res = await fetch('/api/chat_call.php?action=start_call', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ channel_key: channelKey })
                });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.message || 'Ошибка запуска созвона', 'err');
                    return;
                }
                await initializeActiveCall(data, channelTitle, true, 'group');
            } catch (err) {
                if (!isAbortError(err)) {
                    showToast('Ошибка сети: ' + (err.message || err), 'err');
                }
            }
        };
    }

    // Присоединиться к созвону в канале
    window.joinChatCall = async function (callId, channelKey, channelTitle) {
        if (activeCallSession) {
            showToast('Вы уже участвуете в созвоне!', 'warn');
            return;
        }
        removeElement('incomingCallModal');
        stopAllTones();
        await loadAgoraSDK();

        try {
            const res = await fetch('/api/chat_call.php?action=join_call', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ call_id: callId })
            });
            const data = await res.json();
            if (!data.success) {
                showToast(data.message || 'Созвон уже завершён', 'err');
                return;
            }
            await initializeActiveCall(data, channelTitle || data.channel_name, false, 'group');
        } catch (err) {
            if (!isAbortError(err)) {
                showToast('Ошибка подключения: ' + (err.message || err), 'err');
            }
        }
    };

    /* ═══════════════════════════════════════════════════════════
       2. DIRECT 1-ON-1 PHONE CALLS (Личные звонки как на телефоне)
    ═══════════════════════════════════════════════════════════ */
    // Позвонить конкретному человеку
    window.startDirectCall = async function (targetUserId, targetUserName) {
        if (activeCallSession) {
            showToast('Вы уже в звонке!', 'warn');
            return;
        }
        await loadAgoraSDK();

        // 1. Показываем оверлей исходящего звонка с анимацией и гудками
        showOutgoingCallOverlay(targetUserName);

        // 2. Включаем классические телефонные гудки "Туууут..."
        window.playDialTone();

        try {
            const formData = new FormData();
            formData.append('receiver_id', targetUserId);
            const csrfToken = window.DM_CSRF || window.APP_CSRF || window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            formData.append('csrf_token', csrfToken);

            const res = await fetch('/direct_messages.php?action=start_dm_call', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (!data.success) {
                window.stopDialTone();
                removeElement('outgoingCallOverlay');
                showToast(data.error || 'Не удалось дозвониться', 'err');
                return;
            }

            // Запускаем опрос статуса звонка: если собеседник сбросит вызов — гудки сразу прекратятся
            if (directCallPollInterval) clearInterval(directCallPollInterval);
            directCallPollInterval = setInterval(async () => {
                if (!activeCallSession && !document.getElementById('outgoingCallOverlay')) {
                    if (directCallPollInterval) {
                        clearInterval(directCallPollInterval);
                        directCallPollInterval = null;
                    }
                    return;
                }
                try {
                    const chkRes = await fetch(`/direct_messages.php?action=check_dm_call_status&call_id=${data.call_id}`);
                    const chk = await chkRes.json();
                    if (chk.success && (chk.status === 'ended' || chk.status === 'rejected' || chk.status === 'missed' || !chk.status)) {
                        if (directCallPollInterval) {
                            clearInterval(directCallPollInterval);
                            directCallPollInterval = null;
                        }
                        window.stopDialTone();
                        window.stopIncomingRingtone();
                        removeElement('outgoingCallOverlay');
                        if (activeCallSession) {
                            window.endActiveCall(false);
                        } else {
                            window.playBusyTone(1500);
                        }
                        showToast('Собеседник отклонил или завершил вызов', 'warn');
                    }
                } catch (e) {}
            }, 1000);

            // Инициализируем Agora звонок в ожидании ответа собеседника
            await initializeActiveCall({
                call_id: data.call_id,
                channel_key: data.channel,
                token: data.token,
                app_id: data.app_id,
                uid: data.call_id ? (window.DM_MY_ID || 0) : 0
            }, targetUserName, true, 'direct');

        } catch (err) {
            window.stopDialTone();
            removeElement('outgoingCallOverlay');
            if (!isAbortError(err)) {
                showToast('Ошибка звонка: ' + (err.message || err), 'err');
            }
        }
    };

    function showOutgoingCallOverlay(name) {
        removeElement('outgoingCallOverlay');
        const overlay = document.createElement('div');
        overlay.id = 'outgoingCallOverlay';
        overlay.style.cssText = `
            position: fixed; inset: 0; z-index: 10200;
            background: rgba(10, 15, 30, 0.92); backdrop-filter: blur(12px);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: #ffffff; font-family: system-ui, -apple-system, sans-serif;
            animation: fadeIn 0.3s ease;
        `;
        overlay.innerHTML = `
            <div style="width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8);
                        display: flex; align-items: center; justify-content: center; font-size: 40px; margin-bottom: 24px;
                        box-shadow: 0 0 40px rgba(59,130,246,0.6); animation: telPulse 1.8s infinite;">
                📞
            </div>
            <div style="font-size: 14px; color: #93c5fd; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; margin-bottom: 6px;">
                Исходящий вызов
            </div>
            <div style="font-size: 24px; font-weight: 800; margin-bottom: 8px;">
                ${escapeHtml(name)}
            </div>
            <div style="font-size: 14px; color: #cbd5e1; margin-bottom: 32px; display: flex; align-items: center; gap: 8px;">
                <span class="pulse-dot"></span> Идут гудки...
            </div>
            <button onclick="window.endActiveCall()"
                style="padding: 14px 36px; background: #ef4444; border: none; border-radius: 30px; color: #fff;
                       font-size: 15px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 10px;
                       box-shadow: 0 10px 25px rgba(239,68,68,0.5); transition: transform 0.15s;">
                <i class="fas fa-phone-slash"></i> Отменить вызов
            </button>
            <style>
                @keyframes telPulse {
                    0%, 100% { transform: scale(1); box-shadow: 0 0 25px rgba(59,130,246,0.4); }
                    50% { transform: scale(1.08); box-shadow: 0 0 50px rgba(59,130,246,0.8); }
                }
                .pulse-dot { width: 8px; height: 8px; border-radius: 50%; background: #38bdf8; display: inline-block; animation: blink 1.2s infinite; }
            </style>
        `;
        document.body.appendChild(overlay);
    }

    // Принять входящий личный звонок
    window.answerDirectCall = async function (callId, callerName, agoraChannel) {
        removeElement('incomingDirectModal');
        stopAllTones();
        await loadAgoraSDK();

        try {
            const formData = new FormData();
            formData.append('call_id', callId);

            const res = await fetch('/direct_messages.php?action=answer_dm_call', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (!data.success) {
                showToast(data.error || 'Звонок уже завершён', 'err');
                return;
            }

            await initializeActiveCall({
                call_id: callId,
                channel_key: data.channel,
                token: data.token,
                app_id: data.app_id,
                uid: window.DM_MY_ID || 0
            }, callerName, false, 'direct');

            // Опрашиваем статус звонка со стороны отвечающего: если звонящий сбросил, завершаем
            if (directCallPollInterval) clearInterval(directCallPollInterval);
            directCallPollInterval = setInterval(async () => {
                if (!activeCallSession) {
                    if (directCallPollInterval) {
                        clearInterval(directCallPollInterval);
                        directCallPollInterval = null;
                    }
                    return;
                }
                try {
                    const chkRes = await fetch(`/direct_messages.php?action=check_dm_call_status&call_id=${callId}`);
                    const chk = await chkRes.json();
                    if (chk.success && (chk.status === 'ended' || chk.status === 'rejected' || chk.status === 'missed' || !chk.status)) {
                        if (directCallPollInterval) {
                            clearInterval(directCallPollInterval);
                            directCallPollInterval = null;
                        }
                        window.endActiveCall(false);
                        showToast('Звонок завершён собеседником', 'info');
                    }
                } catch (e) {}
            }, 1200);

        } catch (err) {
            if (!isAbortError(err)) {
                showToast('Ошибка при ответе: ' + (err.message || err), 'err');
            }
        }
    };

    // Отклонить входящий личный звонок
    window.declineDirectCall = function (callId) {
        removeElement('incomingDirectModal');
        stopAllTones();
        dismissedCallIds.add(callId);

        const formData = new FormData();
        formData.append('call_id', callId);
        fetch('/direct_messages.php?action=end_dm_call', { method: 'POST', body: formData }).catch(() => {});

        window.playBusyTone();
    };

    /* ═══════════════════════════════════════════════════════════
       3. CORE CALL SESSION INITIALIZATION (Agora WebRTC)
    ═══════════════════════════════════════════════════════════ */
    async function initializeActiveCall(callData, title, isInitiator, type = 'group') {
        activeCallSession = {
            callId: callData.call_id,
            channelKey: callData.channel_key,
            appId: callData.app_id,
            token: callData.token,
            uid: callData.uid,
            title: title,
            isInitiator: isInitiator,
            type: type, // 'group' or 'direct'
            isMuted: false,
            camOn: cameraEnabled
        };

        agoraClient = AgoraRTC.createClient({ mode: 'rtc', codec: 'vp8' });

        // Подписка на удалённые потоки собеседников
        agoraClient.on('user-published', async (user, mediaType) => {
            await agoraClient.subscribe(user, mediaType);

            // Если собеседник ответил — сразу останавливаем гудки у инициатора!
            window.stopDialTone();
            removeElement('outgoingCallOverlay');

            if (!remoteUsers[user.uid]) {
                remoteUsers[user.uid] = { name: type === 'direct' ? title : `Сотрудник #${user.uid}` };
            }

            if (mediaType === 'audio') {
                remoteUsers[user.uid].audioTrack = user.audioTrack;
                user.audioTrack.play();
            }
            if (mediaType === 'video') {
                remoteUsers[user.uid].videoTrack = user.videoTrack;
            }
            refreshParticipantsGrid();
        });

        agoraClient.on('user-unpublished', (user, mediaType) => {
            if (mediaType === 'video' && remoteUsers[user.uid]) {
                remoteUsers[user.uid].videoTrack = null;
            }
            if (mediaType === 'audio' && remoteUsers[user.uid]) {
                remoteUsers[user.uid].audioTrack = null;
            }
            refreshParticipantsGrid();
        });

        agoraClient.on('user-left', (user) => {
            delete remoteUsers[user.uid];
            if (type === 'direct') {
                // В личном звонке если собеседник вышел — завершаем вызов со звуком занято
                window.endActiveCall();
            } else {
                refreshParticipantsGrid();
            }
        });

        // Подключаемся к комнате Agora
        try {
            await agoraClient.join(callData.app_id, callData.channel_key, callData.token, callData.uid);
        } catch (joinErr) {
            if (isAbortError(joinErr)) {
                console.log('[Telephony] Agora join aborted gracefully');
                return;
            }
            throw joinErr;
        }

        // Создаем локальный аудио-трек микрофона
        const micConfig = { encoderConfig: 'high_quality_stereo', AEC: true, ANS: true };
        if (selectedMicId) micConfig.microphoneId = selectedMicId;
        localAudioTrack = await AgoraRTC.createMicrophoneAudioTrack(micConfig);

        const tracksToPublish = [localAudioTrack];

        // Камера (если включена)
        if (cameraEnabled && selectedCamId !== null) {
            try {
                const camConfig = { encoderConfig: '720p_1' };
                if (selectedCamId) camConfig.cameraId = selectedCamId;
                localVideoTrack = await AgoraRTC.createCameraVideoTrack(camConfig);
                tracksToPublish.push(localVideoTrack);
            } catch (e) {
                console.warn('[Telephony] Cam error:', e);
                cameraEnabled = false;
                activeCallSession.camOn = false;
            }
        }

        try {
            await agoraClient.publish(tracksToPublish);
        } catch (pubErr) {
            if (isAbortError(pubErr)) {
                console.log('[Telephony] Agora publish aborted gracefully');
                return;
            }
            throw pubErr;
        }

        // Для прямого звонка: если мы вызывающий, ждем ответа (пока играет dial tone).
        // Если мы принимающий — сразу открываем панель активного разговора.
        if (type !== 'direct' || !isInitiator) {
            renderActiveCallPanel(title);
            startCallTimer();
        } else {
            // Для вызывающего: когда удаленный юзер зайдет, панель откроется по user-published
            // Также ставим таймаут ожидания 40 сек
            setTimeout(() => {
                if (activeCallSession && Object.keys(remoteUsers).length === 0) {
                    window.stopDialTone();
                    removeElement('outgoingCallOverlay');
                    showToast('Собеседник не отвечает', 'warn');
                    window.endActiveCall();
                }
            }, 45000);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       4. ACTIVE CALL UI PANEL (Стильный пульт созвона)
    ═══════════════════════════════════════════════════════════ */
    function renderActiveCallPanel(title) {
        removeElement('chatActiveCallPanel');
        removeElement('outgoingCallOverlay');
        window.stopDialTone();

        const panel = document.createElement('div');
        panel.id = 'chatActiveCallPanel';
        panel.style.cssText = `
            position: fixed; bottom: 24px; right: 24px; z-index: 10000;
            width: 360px; max-width: calc(100vw - 32px);
            background: linear-gradient(145deg, #0f172a, #1e293b);
            border: 1px solid rgba(59,130,246,0.4);
            box-shadow: 0 24px 60px rgba(0,0,0,0.7), 0 0 30px rgba(59,130,246,0.15);
            border-radius: 18px; overflow: hidden;
            font-family: system-ui, -apple-system, sans-serif; color: #f8fafc;
            animation: slideUp 0.3s ease-out;
        `;

        panel.innerHTML = `
            <div style="padding: 14px 16px; background: linear-gradient(135deg, rgba(59,130,246,0.25), rgba(99,102,241,0.15));
                        border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 10px #22c55e; flex-shrink: 0;"></span>
                    <div style="min-width: 0;">
                        <div style="font-size: 14px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            ${escapeHtml(title)}
                        </div>
                        <div style="font-size: 11px; color: #94a3b8; display: flex; gap: 8px;">
                            <span id="callPanelTimer">00:00</span>
                            <span>•</span>
                            <span id="callPanelCount">В сети</span>
                        </div>
                    </div>
                </div>
                <button onclick="window.endActiveCall()"
                    style="background: rgba(239,68,68,0.2); border: 1px solid rgba(239,68,68,0.4);
                           border-radius: 10px; color: #f87171; padding: 6px 12px; font-size: 12px; font-weight: 700; cursor: pointer;">
                    <i class="fas fa-phone-slash"></i> Завершить
                </button>
            </div>

            <!-- Сетка участников -->
            <div id="callPanelGrid" style="padding: 12px; display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; max-height: 200px; overflow-y: auto;"></div>

            <!-- Нижняя панель управления -->
            <div style="padding: 10px 14px; border-top: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.2); display: flex; gap: 8px;">
                <button id="btnToggleMute" onclick="window.toggleCallMute()"
                    style="flex: 1; padding: 8px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
                           border-radius: 10px; color: #f1f5f9; font-size: 12px; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-microphone"></i> Выкл. мик
                </button>
                <button id="btnToggleCam" onclick="window.toggleCallCamera()"
                    style="flex: 1; padding: 8px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
                           border-radius: 10px; color: #f1f5f9; font-size: 12px; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-video"></i> Вкл. камеру
                </button>
            </div>
        `;
        document.body.appendChild(panel);

        refreshParticipantsGrid();
    }

    function refreshParticipantsGrid() {
        const grid = document.getElementById('callPanelGrid');
        if (!grid) {
            // Если панель еще не создана (например, для звонящего при ответе собеседника)
            if (activeCallSession && Object.keys(remoteUsers).length > 0) {
                renderActiveCallPanel(activeCallSession.title);
                startCallTimer();
            }
            return;
        }

        grid.innerHTML = '';

        // Плитка локального пользователя
        grid.appendChild(createParticipantTile('me', 'Вы', activeCallSession?.camOn, localVideoTrack, activeCallSession?.isMuted));

        // Плитки удаленных собеседников
        Object.entries(remoteUsers).forEach(([uid, u]) => {
            grid.appendChild(createParticipantTile(`u_${uid}`, u.name, !!u.videoTrack, u.videoTrack, !u.audioTrack));
        });

        const countEl = document.getElementById('callPanelCount');
        if (countEl) {
            const count = Object.keys(remoteUsers).length + 1;
            countEl.textContent = `${count} ${count === 1 ? 'участник' : (count < 5 ? 'участника' : 'участников')}`;
        }
    }

    function createParticipantTile(id, name, hasVideo, videoTrack, isMuted) {
        const tile = document.createElement('div');
        tile.id = `tile_${id}`;
        tile.style.cssText = `
            position: relative; background: rgba(15,23,42,0.9); border-radius: 12px; overflow: hidden;
            aspect-ratio: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
            border: 1px solid rgba(255,255,255,0.08); font-size: 11px; color: #94a3b8;
        `;

        if (hasVideo && videoTrack) {
            const videoContainer = document.createElement('div');
            videoContainer.style.cssText = 'position: absolute; inset: 0;';
            tile.appendChild(videoContainer);
            setTimeout(() => { try { videoTrack.play(videoContainer); } catch (e) {} }, 40);
        } else {
            const initials = (name || '?').slice(0, 2).toUpperCase();
            tile.innerHTML = `
                <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #6366f1);
                            display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">
                    ${escapeHtml(initials)}
                </div>
            `;
        }

        const label = document.createElement('div');
        label.style.cssText = `
            position: absolute; bottom: 0; left: 0; right: 0; padding: 4px 6px;
            background: linear-gradient(to top, rgba(0,0,0,0.85), transparent);
            font-size: 10px; font-weight: 600; color: #f1f5f9; display: flex; justify-content: space-between; align-items: center;
        `;
        label.innerHTML = `
            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 80%;">${escapeHtml(name)}</span>
            <span>${isMuted ? '🔇' : ''}</span>
        `;
        tile.appendChild(label);
        return tile;
    }

    window.toggleCallMute = function () {
        if (!localAudioTrack || !activeCallSession) return;
        activeCallSession.isMuted = !activeCallSession.isMuted;
        localAudioTrack.setEnabled(!activeCallSession.isMuted);

        const btn = document.getElementById('btnToggleMute');
        if (btn) {
            btn.innerHTML = activeCallSession.isMuted
                ? '<i class="fas fa-microphone-slash"></i> Вкл. мик'
                : '<i class="fas fa-microphone"></i> Выкл. мик';
            btn.style.color = activeCallSession.isMuted ? '#f87171' : '#f1f5f9';
        }
        refreshParticipantsGrid();
    };

    window.toggleCallCamera = async function () {
        if (!agoraClient || !activeCallSession) return;
        if (!activeCallSession.camOn) {
            try {
                const camConfig = { encoderConfig: '720p_1' };
                if (selectedCamId) camConfig.cameraId = selectedCamId;
                localVideoTrack = await AgoraRTC.createCameraVideoTrack(camConfig);
                await agoraClient.publish([localVideoTrack]);
                activeCallSession.camOn = true;

                const btn = document.getElementById('btnToggleCam');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-video-slash"></i> Выкл. камеру';
                    btn.style.color = '#34d399';
                }
            } catch (e) {
                showToast('Ошибка камеры: ' + e.message, 'err');
            }
        } else {
            if (localVideoTrack) {
                await agoraClient.unpublish([localVideoTrack]);
                localVideoTrack.stop();
                localVideoTrack.close();
                localVideoTrack = null;
            }
            activeCallSession.camOn = false;
            const btn = document.getElementById('btnToggleCam');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-video"></i> Вкл. камеру';
                btn.style.color = '#f1f5f9';
            }
        }
        refreshParticipantsGrid();
    };

    function startCallTimer() {
        callSeconds = 0;
        clearInterval(callTimerInterval);
        callTimerInterval = setInterval(() => {
            callSeconds++;
            const m = String(Math.floor(callSeconds / 60)).padStart(2, '0');
            const s = String(callSeconds % 60).padStart(2, '0');
            const el = document.getElementById('callPanelTimer');
            if (el) el.textContent = `${m}:${s}`;
        }, 1000);
    }

    // Завершить любой активный звонок
    window.endActiveCall = async function (notifyServer = true) {
        if (directCallPollInterval) {
            clearInterval(directCallPollInterval);
            directCallPollInterval = null;
        }
        window.stopDialTone();
        window.stopIncomingRingtone();
        removeElement('outgoingCallOverlay');
        removeElement('chatActiveCallPanel');

        if (!activeCallSession) return;

        const session = { ...activeCallSession };
        activeCallSession = null;

        clearInterval(callTimerInterval);
        callTimerInterval = null;

        // Закрываем локальные треки
        if (localAudioTrack) {
            try { localAudioTrack.stop(); localAudioTrack.close(); } catch (e) {}
            localAudioTrack = null;
        }
        if (localVideoTrack) {
            try { localVideoTrack.stop(); localVideoTrack.close(); } catch (e) {}
            localVideoTrack = null;
        }

        // Выходим из комнаты Agora
        if (agoraClient) {
            try { await agoraClient.leave(); } catch (e) {}
            agoraClient = null;
        }

        remoteUsers = {};

        // Уведомляем сервер
        if (notifyServer) {
            if (session.type === 'direct') {
                const formData = new FormData();
                formData.append('call_id', session.callId);
                fetch('/direct_messages.php?action=end_dm_call', { method: 'POST', body: formData }).catch(() => {});
            } else {
                fetch('/api/chat_call.php?action=end_call', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ call_id: session.callId })
                }).catch(() => {});
            }
        }

        // Звук отбоя трубки
        window.playBusyTone(1500);
    };

    /* ═══════════════════════════════════════════════════════════
       5. GLOBAL INCOMING CALLS DETECTOR (Поллинг для всех страниц)
    ═══════════════════════════════════════════════════════════ */
    async function checkGlobalIncomingCalls() {
        if (activeCallSession) return; // Уже разговариваем

        // 1. Проверяем входящие личные звонки (DM)
        try {
            const rDm = await fetch('/direct_messages.php?action=check_dm_incoming');
            const dDm = await rDm.json();
            if (dDm.success && dDm.call) {
                const call = dDm.call;
                if (!dismissedCallIds.has(call.id)) {
                    showIncomingDirectCallModal(call);
                    return;
                }
            } else {
                removeElement('incomingDirectModal');
            }
        } catch (e) {}

        // 2. Проверяем групповые созвоны в каналах чатов
        try {
            const rGrp = await fetch('/api/chat_call.php?action=check_incoming');
            const dGrp = await rGrp.json();
            if (dGrp.has_call && dGrp.call_id && !dismissedCallIds.has(dGrp.call_id)) {
                showIncomingGroupCallModal(dGrp);
            } else if (!dGrp.has_call) {
                removeElement('incomingGroupCallModal');
                window.stopIncomingRingtone();
            }
        } catch (e) {}
    }

    function showIncomingDirectCallModal(call) {
        if (document.getElementById('incomingDirectModal')) return;

        window.playIncomingRingtone();
        startTitleBlink(`Входящий звонок от ${call.caller_name}`);
        showDesktopNotification(`📞 Звонок от ${call.caller_name}`, `Вам звонит ${call.caller_name}`);

        const modal = document.createElement('div');
        modal.id = 'incomingDirectModal';
        modal.style.cssText = `
            position: fixed; top: 24px; right: 24px; z-index: 10200;
            background: linear-gradient(145deg, #064e3b, #065f46);
            border: 2px solid rgba(34,197,94,0.6);
            border-radius: 18px; padding: 20px 24px; width: 340px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.7), 0 0 30px rgba(34,197,94,0.3);
            color: #ffffff; font-family: system-ui, -apple-system, sans-serif;
            animation: slideDown 0.3s ease-out;
        `;

        modal.innerHTML = `
            <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 16px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #22c55e;
                            display: flex; align-items: center; justify-content: center; font-size: 24px;
                            box-shadow: 0 0 20px rgba(34,197,94,0.6); animation: telPulse 1s infinite;">
                    📲
                </div>
                <div style="overflow: hidden;">
                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #a7f3d0; font-weight: 700;">
                        Входящий звонок
                    </div>
                    <div style="font-size: 16px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${escapeHtml(call.caller_name)}
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button id="btnAcceptDirectCall"
                    style="flex: 1; padding: 10px; background: linear-gradient(135deg, #22c55e, #16a34a); border: none;
                           border-radius: 12px; color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <i class="fas fa-phone"></i> Ответить
                </button>
                <button id="btnDeclineDirectCall"
                    style="flex: 1; padding: 10px; background: rgba(239,68,68,0.2); border: 1px solid rgba(239,68,68,0.4);
                           border-radius: 12px; color: #f87171; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <i class="fas fa-phone-slash"></i> Сбросить
                </button>
            </div>
        `;
        document.body.appendChild(modal);

        document.getElementById('btnAcceptDirectCall').onclick = () => {
            window.answerDirectCall(call.id, call.caller_name, call.agora_channel);
        };
        document.getElementById('btnDeclineDirectCall').onclick = () => {
            window.declineDirectCall(call.id);
        };
    }

    function showIncomingGroupCallModal(call) {
        if (document.getElementById('incomingGroupCallModal')) return;

        window.playIncomingRingtone();
        startTitleBlink(`Созвон в #${call.channel_name}`);
        showDesktopNotification(`📞 Созвон в #${call.channel_name}`, `${call.initiator_name} начал созвон`);

        const modal = document.createElement('div');
        modal.id = 'incomingGroupCallModal';
        modal.style.cssText = `
            position: fixed; top: 24px; right: 24px; z-index: 10200;
            background: linear-gradient(145deg, #0f172a, #1e293b);
            border: 2px solid rgba(59,130,246,0.6);
            border-radius: 18px; padding: 18px 22px; width: 330px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.7);
            color: #ffffff; font-family: system-ui, -apple-system, sans-serif;
            animation: slideDown 0.3s ease-out;
        `;

        modal.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8);
                            display: flex; align-items: center; justify-content: center; font-size: 20px;">📞</div>
                <div style="overflow: hidden;">
                    <div style="font-size: 14px; font-weight: 700;">Созвон в канале</div>
                    <div style="font-size: 12px; color: #93c5fd; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        #${escapeHtml(call.channel_name)}
                    </div>
                    <div style="font-size: 11px; color: #94a3b8;">Инициатор: ${escapeHtml(call.initiator_name)}</div>
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button id="btnAcceptGroupCall"
                    style="flex: 1; padding: 9px; background: linear-gradient(135deg, #22c55e, #16a34a); border: none;
                           border-radius: 10px; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer;">
                    ✅ Принять
                </button>
                <button id="btnDeclineGroupCall"
                    style="flex: 1; padding: 9px; background: rgba(239,68,68,0.2); border: 1px solid rgba(239,68,68,0.4);
                           border-radius: 10px; color: #f87171; font-size: 13px; font-weight: 700; cursor: pointer;">
                    ❌ Отклонить
                </button>
            </div>
        `;
        document.body.appendChild(modal);

        document.getElementById('btnAcceptGroupCall').onclick = () => {
            window.joinChatCall(call.call_id, call.channel_key, call.channel_name);
        };
        document.getElementById('btnDeclineGroupCall').onclick = () => {
            dismissedCallIds.add(call.call_id);
            removeElement('incomingGroupCallModal');
            window.stopIncomingRingtone();
        };
    }

    /* ═══════════════════════════════════════════════════════════
       6. VOICE NOTE PLAYER ENGINE (Воспроизведение голосовых сообщений)
    ═══════════════════════════════════════════════════════════ */
    let currentVoiceAudio = null;
    let currentVoiceBtn = null;

    window.playVoiceNote = function (btn, audioUrl) {
        if (currentVoiceAudio && !currentVoiceAudio.paused) {
            currentVoiceAudio.pause();
            if (currentVoiceBtn) {
                currentVoiceBtn.innerHTML = '<i class="fas fa-play" style="font-size:12px; margin-left:2px;"></i>';
            }
            if (currentVoiceBtn === btn) {
                currentVoiceAudio = null;
                currentVoiceBtn = null;
                return;
            }
        }

        const audio = new Audio(audioUrl);
        currentVoiceAudio = audio;
        currentVoiceBtn = btn;
        btn.innerHTML = '<i class="fas fa-pause" style="font-size:12px;"></i>';

        audio.play().catch(e => {
            btn.innerHTML = '<i class="fas fa-play" style="font-size:12px; margin-left:2px;"></i>';
        });

        audio.onended = () => {
            btn.innerHTML = '<i class="fas fa-play" style="font-size:12px; margin-left:2px;"></i>';
            currentVoiceAudio = null;
            currentVoiceBtn = null;
        };
    };

    /* ─── Helpers ─── */
    function removeElement(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(msg, type = 'info') {
        const toast = document.createElement('div');
        const bgColors = { ok: '#10b981', warn: '#f59e0b', err: '#ef4444', info: '#3b82f6' };
        toast.style.cssText = `
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
            background: ${bgColors[type] || '#334155'}; color: #fff;
            padding: 10px 20px; border-radius: 12px; font-size: 13px; font-weight: 700;
            z-index: 10500; box-shadow: 0 10px 30px rgba(0,0,0,0.5); pointer-events: none;
        `;
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3200);
    }

    // Запуск глобального мониторинга входящих вызовов
    setInterval(checkGlobalIncomingCalls, 2800);

    // Запрос разрешения на уведомления при первом клике
    document.addEventListener('click', function reqOnce() {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
        document.removeEventListener('click', reqOnce);
    }, { once: true });

})();
