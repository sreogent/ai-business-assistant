<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Экран режима технического обслуживания
 * Отображается для всех пользователей, которым заблокирован доступ во время ТО.
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';

// Если режим ТО отключен, перенаправляем на главную
if (!Auth::isMaintenanceMode()) {
    header("Location: /index.php");
    exit;
}

// Если авторизован специалист с правом доступа, перенаправляем в его кабинет
$user = Auth::user();
if ($user) {
    $role = $user['role_name'] ?? 'user';
    if (Auth::isSupremeOwner($user) || in_array($role, ['vrio_owner', 'admin'], true)) {
        header("Location: /owner/dashboard.php");
        exit;
    }
    if ($role === 'developer') {
        header("Location: /developer/dashboard.php");
        exit;
    }
    if ($role === 'tester') {
        header("Location: /tester/dashboard.php");
        exit;
    }
    if ($role === 'support_agent') {
        header("Location: /support/dashboard.php");
        exit;
    }
}

// Отправляем правильный HTTP 503 код
http_response_code(503);
header('Retry-After: 300');

$startTime = date('H:i', time() - rand(300, 1800));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Технические работы — AI Business Assistant</title>
    <meta http-equiv="refresh" content="30">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --amber: #f59e0b;
            --amber-dim: rgba(245,158,11,0.12);
            --amber-glow: rgba(245,158,11,0.35);
            --indigo: #6366f1;
            --indigo-dim: rgba(99,102,241,0.12);
            --cyan: #22d3ee;
            --green: #10b981;
            --red: #ef4444;
            --bg: #060912;
            --card: rgba(10,16,30,0.92);
            --border: rgba(255,255,255,0.07);
            --text: #f1f5f9;
            --muted: #64748b;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow: hidden;
        }

        /* ─── Анимированная сетка на фоне ─── */
        .grid-bg {
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(99,102,241,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99,102,241,0.04) 1px, transparent 1px);
            background-size: 60px 60px;
            mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 40%, transparent 100%);
            pointer-events: none;
            z-index: 0;
        }

        /* ─── Размытые свечения ─── */
        .glow {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
        }
        .glow-1 {
            width: 600px; height: 600px;
            top: -100px; left: -100px;
            background: radial-gradient(circle, rgba(245,158,11,0.12), transparent 70%);
            animation: driftA 18s ease-in-out infinite alternate;
        }
        .glow-2 {
            width: 700px; height: 700px;
            bottom: -150px; right: -100px;
            background: radial-gradient(circle, rgba(99,102,241,0.14), transparent 70%);
            animation: driftB 22s ease-in-out infinite alternate;
        }
        .glow-3 {
            width: 400px; height: 400px;
            top: 50%; left: 55%;
            background: radial-gradient(circle, rgba(34,211,238,0.07), transparent 70%);
            animation: driftC 14s ease-in-out infinite alternate;
        }

        @keyframes driftA { 0%{transform:translate(0,0)} 100%{transform:translate(80px,60px)} }
        @keyframes driftB { 0%{transform:translate(0,0)} 100%{transform:translate(-60px,-80px)} }
        @keyframes driftC { 0%{transform:translate(0,0)} 100%{transform:translate(40px,-50px)} }

        /* ─── Летающие частицы ─── */
        .particles {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0;
            animation: floatUp linear infinite;
        }
        @keyframes floatUp {
            0%   { transform: translateY(100vh) scale(0); opacity: 0; }
            10%  { opacity: 1; }
            90%  { opacity: 0.6; }
            100% { transform: translateY(-10vh) scale(1); opacity: 0; }
        }

        /* ─── Центрирующая обёртка ─── */
        .page {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        /* ─── Главная карточка ─── */
        .card {
            background: var(--card);
            border: 1px solid rgba(245,158,11,0.2);
            border-radius: 28px;
            padding: 48px 44px 40px;
            max-width: 620px;
            width: 100%;
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            box-shadow:
                0 0 0 1px rgba(245,158,11,0.05),
                0 40px 100px rgba(0,0,0,0.9),
                0 0 60px rgba(245,158,11,0.08),
                inset 0 1px 0 rgba(255,255,255,0.06);
            animation: cardIn 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes cardIn {
            from { opacity:0; transform: translateY(32px) scale(0.97); }
            to   { opacity:1; transform: translateY(0) scale(1); }
        }

        /* ─── Шапка карточки ─── */
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 36px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: -0.3px;
        }
        .brand-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--amber);
            box-shadow: 0 0 8px var(--amber);
            animation: blink 1.4s ease-in-out infinite;
        }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            background: rgba(245,158,11,0.1);
            border: 1px solid rgba(245,158,11,0.25);
            font-size: 11px;
            font-weight: 700;
            color: var(--amber);
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .status-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--amber);
            animation: blink 1.2s ease-in-out infinite;
        }

        /* ─── Центральный блок с иконкой ─── */
        .hero {
            text-align: center;
            margin-bottom: 36px;
        }

        .icon-ring-wrapper {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 24px;
        }
        .icon-ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2px solid rgba(245,158,11,0.2);
            animation: ringPulse 3s ease-in-out infinite;
        }
        .icon-ring:nth-child(2) {
            inset: -12px;
            border-color: rgba(245,158,11,0.1);
            animation-delay: 0.5s;
        }
        .icon-ring:nth-child(3) {
            inset: -24px;
            border-color: rgba(245,158,11,0.05);
            animation-delay: 1s;
        }
        @keyframes ringPulse {
            0%,100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.5; }
        }

        .icon-core {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(245,158,11,0.2), rgba(217,119,6,0.08));
            border: 1.5px solid rgba(245,158,11,0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            box-shadow: 0 0 40px rgba(245,158,11,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
        }
        .icon-core i {
            animation: gearSpin 8s linear infinite;
            transform-origin: center;
        }
        @keyframes gearSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .hero h1 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.8px;
            line-height: 1.2;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #f8fafc, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
            max-width: 440px;
            margin: 0 auto;
        }

        /* ─── Прогресс-бар ─── */
        .progress-section {
            margin-bottom: 28px;
        }
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .progress-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .progress-pct {
            font-size: 12px;
            font-weight: 700;
            color: var(--amber);
        }
        .progress-track {
            height: 6px;
            background: rgba(255,255,255,0.06);
            border-radius: 99px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #f59e0b, #fbbf24, #f59e0b);
            background-size: 200% 100%;
            border-radius: 99px;
            width: 68%;
            animation: progressShimmer 2s linear infinite;
        }
        @keyframes progressShimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        /* ─── Статус компонентов ─── */
        .components {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 28px;
        }
        .comp-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: 12px;
            background: rgba(255,255,255,0.025);
            border: 1px solid rgba(255,255,255,0.05);
            font-size: 12px;
            transition: border-color 0.3s;
        }
        .comp-icon {
            width: 28px; height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        .comp-name { font-weight: 600; color: #cbd5e1; display: block; line-height: 1.2; }
        .comp-state { font-size: 10px; font-weight: 600; margin-top: 1px; display: block; }

        .state-updating { color: var(--amber); }
        .state-ok       { color: var(--green); }
        .state-pending  { color: var(--cyan); }

        .comp-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            margin-left: auto;
            flex-shrink: 0;
        }
        .dot-amber  { background: var(--amber); box-shadow: 0 0 6px var(--amber); animation: blink 1.3s ease-in-out infinite; }
        .dot-green  { background: var(--green);  box-shadow: 0 0 6px var(--green); }
        .dot-cyan   { background: var(--cyan);   box-shadow: 0 0 6px var(--cyan);   animation: blink 2s ease-in-out infinite; }

        /* ─── Инфо-строчка ─── */
        .info-row {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(34,211,238,0.05);
            border: 1px solid rgba(34,211,238,0.12);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 28px;
        }
        .info-row i { color: var(--cyan); font-size: 14px; flex-shrink: 0; }
        .info-row strong { color: #e2e8f0; }

        /* ─── Кнопки ─── */
        .actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 22px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            flex: 1;
        }
        .btn-amber {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #000;
            box-shadow: 0 4px 20px rgba(245,158,11,0.3);
        }
        .btn-amber:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(245,158,11,0.45);
        }
        .btn-ghost {
            background: rgba(255,255,255,0.04);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.12);
            transform: translateY(-1px);
        }

        /* ─── Подвал карточки ─── */
        .card-footer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
            font-size: 11px;
            color: var(--muted);
        }
        .card-footer i { animation: spin 4s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* ─── Таймер ─── */
        #refresh-timer { font-weight: 700; color: var(--amber); font-variant-numeric: tabular-nums; }

        /* ─── Адаптив ─── */
        @media (max-width: 500px) {
            .card { padding: 32px 20px 28px; }
            .hero h1 { font-size: 22px; }
            .components { grid-template-columns: 1fr; }
            .actions { flex-direction: column; }
            .card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        }
    </style>
</head>
<body>

    <!-- Фон -->
    <div class="grid-bg"></div>
    <div class="glow glow-1"></div>
    <div class="glow glow-2"></div>
    <div class="glow glow-3"></div>
    <div class="particles" id="particles"></div>

    <div class="page">
        <div class="card">

            <!-- Шапка -->
            <div class="card-header">
                <div class="brand">
                    <div class="brand-dot"></div>
                    AI Business Assistant
                </div>
                <div class="status-chip">
                    <div class="status-dot"></div>
                    Тех. работы
                </div>
            </div>

            <!-- Герой -->
            <div class="hero">
                <div class="icon-ring-wrapper">
                    <div class="icon-ring"></div>
                    <div class="icon-ring"></div>
                    <div class="icon-ring"></div>
                    <div class="icon-core">
                        <i class="fas fa-gear" style="color:#f59e0b;"></i>
                    </div>
                </div>
                <h1>Идут технические работы</h1>
                <p>Мы обновляем систему, оптимизируем производительность и внедряем улучшения. Скоро всё будет готово.</p>
            </div>

            <!-- Прогресс -->
            <div class="progress-section">
                <div class="progress-header">
                    <span class="progress-label">Прогресс обновления</span>
                    <span class="progress-pct" id="progress-pct">68%</span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" id="progress-fill"></div>
                </div>
            </div>

            <!-- Статусы компонентов -->
            <div class="components">
                <div class="comp-item">
                    <div class="comp-icon" style="background:rgba(245,158,11,0.1);">
                        <i class="fas fa-database" style="color:#f59e0b;"></i>
                    </div>
                    <div>
                        <span class="comp-name">База данных</span>
                        <span class="comp-state state-updating">Обновление...</span>
                    </div>
                    <div class="comp-dot dot-amber"></div>
                </div>

                <div class="comp-item">
                    <div class="comp-icon" style="background:rgba(16,185,129,0.1);">
                        <i class="fas fa-server" style="color:#10b981;"></i>
                    </div>
                    <div>
                        <span class="comp-name">Серверы</span>
                        <span class="comp-state state-ok">Работают</span>
                    </div>
                    <div class="comp-dot dot-green"></div>
                </div>

                <div class="comp-item">
                    <div class="comp-icon" style="background:rgba(34,211,238,0.08);">
                        <i class="fas fa-robot" style="color:#22d3ee;"></i>
                    </div>
                    <div>
                        <span class="comp-name">AI-модули</span>
                        <span class="comp-state state-pending">Синхронизация</span>
                    </div>
                    <div class="comp-dot dot-cyan"></div>
                </div>

                <div class="comp-item">
                    <div class="comp-icon" style="background:rgba(16,185,129,0.1);">
                        <i class="fas fa-shield-halved" style="color:#10b981;"></i>
                    </div>
                    <div>
                        <span class="comp-name">Безопасность</span>
                        <span class="comp-state state-ok">Активна</span>
                    </div>
                    <div class="comp-dot dot-green"></div>
                </div>
            </div>

            <!-- Инфо-строка -->
            <div class="info-row">
                <i class="fas fa-circle-info"></i>
                <div>
                    Работы начались в <strong><?= $startTime ?></strong>.
                    Доступ открыт только для <strong>инженерной команды</strong> и <strong>агентов поддержки</strong>.
                </div>
            </div>

            <!-- Кнопки -->
            <div class="actions">
                <a href="/login.php" class="btn btn-amber">
                    <i class="fas fa-key"></i> Войти как сотрудник
                </a>
                <button onclick="window.location.reload();" class="btn btn-ghost">
                    <i class="fas fa-arrow-rotate-right"></i> Обновить
                </button>
            </div>

            <!-- Подвал -->
            <div class="card-footer">
                <i class="fas fa-spinner"></i>
                Автообновление через <span id="refresh-timer">30</span> сек.
            </div>

        </div>
    </div>

    <script>
    // ─── Частицы ───
    (function() {
        const container = document.getElementById('particles');
        const colors = ['#f59e0b', '#6366f1', '#22d3ee', '#10b981'];
        for (let i = 0; i < 28; i++) {
            const p = document.createElement('div');
            p.className = 'particle';
            const size = Math.random() * 4 + 2;
            const color = colors[Math.floor(Math.random() * colors.length)];
            p.style.cssText = `
                width: ${size}px;
                height: ${size}px;
                background: ${color};
                left: ${Math.random() * 100}%;
                animation-duration: ${Math.random() * 12 + 10}s;
                animation-delay: ${Math.random() * 10}s;
                box-shadow: 0 0 ${size * 2}px ${color};
            `;
            container.appendChild(p);
        }
    })();

    // ─── Обратный отсчёт ───
    let seconds = 30;
    const timerEl = document.getElementById('refresh-timer');
    setInterval(() => {
        seconds--;
        if (seconds <= 0) {
            timerEl.textContent = '...';
            window.location.reload();
        } else {
            timerEl.textContent = seconds;
        }
    }, 1000);

    // ─── Анимация прогресс-бара (медленно ползёт вверх для визуала) ───
    let pct = 68;
    const fillEl = document.getElementById('progress-fill');
    const pctEl  = document.getElementById('progress-pct');
    fillEl.style.width = pct + '%';

    setInterval(() => {
        if (pct < 94) {
            pct += Math.random() * 0.3;
            fillEl.style.width = pct.toFixed(1) + '%';
            pctEl.textContent  = Math.floor(pct) + '%';
        }
    }, 3000);
    </script>

</body>
</html>
