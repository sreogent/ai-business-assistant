/**
 * AI Business Assistant - Atmospheric Theme Particles System
 * Ultra-smooth, lightweight seasonal effects (Snow, Sakura, Autumn Leaves, New Year Sparkles, Summer Sun)
 */
(function() {
    'use strict';

    let canvas = null;
    let ctx = null;
    let animId = null;
    let particles = [];
    let width = window.innerWidth;
    let height = window.innerHeight;
    let currentTheme = null;
    let isEnabled = true;

    function init() {
        const docEl = document.documentElement;
        currentTheme = docEl.getAttribute('data-theme') || 'dark';
        const particlesAttr = docEl.getAttribute('data-theme-particles');
        isEnabled = particlesAttr !== '0' && particlesAttr !== 'false';

        // Destroy if theme doesn't need particles or if disabled
        if (!isEnabled || currentTheme === 'dark' || currentTheme === 'light') {
            destroy();
            return;
        }

        if (!canvas) {
            canvas = document.createElement('canvas');
            canvas.id = 'theme-particles-canvas';
            document.body.appendChild(canvas);
            ctx = canvas.getContext('2d');
            window.addEventListener('resize', onResize);
            document.addEventListener('visibilitychange', onVisibilityChange);
        }

        onResize();
        createParticles();
        startLoop();
    }

    function onResize() {
        if (!canvas) return;
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
    }

    function onVisibilityChange() {
        if (document.hidden) {
            stopLoop();
        } else if (isEnabled && currentTheme !== 'dark' && currentTheme !== 'light') {
            startLoop();
        }
    }

    function destroy() {
        stopLoop();
        if (canvas && canvas.parentNode) {
            canvas.parentNode.removeChild(canvas);
        }
        canvas = null;
        ctx = null;
        particles = [];
        window.removeEventListener('resize', onResize);
        document.removeEventListener('visibilitychange', onVisibilityChange);
    }

    function startLoop() {
        if (animId) cancelAnimationFrame(animId);
        animId = requestAnimationFrame(render);
    }

    function stopLoop() {
        if (animId) {
            cancelAnimationFrame(animId);
            animId = null;
        }
    }

    function createParticles() {
        particles = [];
        let count = 45; // lightweight count for silky 60fps
        if (width < 768) count = 25;

        for (let i = 0; i < count; i++) {
            particles.push(spawnParticle(true));
        }
    }

    function spawnParticle(initial = false) {
        const x = Math.random() * width;
        const y = initial ? Math.random() * height : -20;
        const speed = 0.6 + Math.random() * 1.4;
        const wind = (Math.random() - 0.5) * 0.8;
        const size = 3 + Math.random() * 6;
        const rotation = Math.random() * Math.PI * 2;
        const rotSpeed = (Math.random() - 0.5) * 0.04;
        const opacity = 0.35 + Math.random() * 0.55;
        const wave = Math.random() * Math.PI * 2;
        const waveSpeed = 0.02 + Math.random() * 0.03;

        return {
            x, y, speed, wind, size, rotation, rotSpeed, opacity, wave, waveSpeed,
            colorHue: Math.random(),
            scaleY: 1
        };
    }

    function render() {
        if (!ctx || !canvas) return;
        ctx.clearRect(0, 0, width, height);

        for (let i = 0; i < particles.length; i++) {
            const p = particles[i];

            p.wave += p.waveSpeed;
            p.x += p.wind + Math.sin(p.wave) * 0.7;
            p.y += p.speed;
            p.rotation += p.rotSpeed;

            ctx.save();
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rotation);
            ctx.globalAlpha = p.opacity;

            switch (currentTheme) {
                case 'winter':
                    drawSnowflake(ctx, p.size);
                    break;
                case 'new_year':
                    drawHolidaySparkle(ctx, p.size, p.colorHue);
                    break;
                case 'spring':
                    drawSpringBud(ctx, p.size);
                    break;
                case 'women_day':
                    drawSakuraPetal(ctx, p.size);
                    break;
                case 'summer':
                    drawSunSparkle(ctx, p.size, p.colorHue);
                    break;
                case 'autumn':
                    drawAutumnLeaf(ctx, p.size, p.colorHue);
                    break;
            }

            ctx.restore();

            // Respawn when out of screen bounds
            if (p.y > height + 25 || p.x < -30 || p.x > width + 30) {
                particles[i] = spawnParticle(false);
            }
        }

        animId = requestAnimationFrame(render);
    }

    // 1. Зима: Снежинки
    function drawSnowflake(ctx, size) {
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(0, 0, size * 0.55, 0, Math.PI * 2);
        ctx.fill();

        // Нежный морозный ореол
        ctx.strokeStyle = 'rgba(186, 230, 253, 0.6)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        for (let a = 0; a < 4; a++) {
            ctx.moveTo(0, -size);
            ctx.lineTo(0, size);
            ctx.rotate(Math.PI / 4);
        }
        ctx.stroke();
    }

    // 2. Новый Год: Золотые и рубиновые праздничные искры
    function drawHolidaySparkle(ctx, size, hue) {
        const isGold = hue > 0.4;
        ctx.fillStyle = isGold ? '#fbbf24' : '#ef4444';
        ctx.shadowColor = isGold ? '#f59e0b' : '#dc2626';
        ctx.shadowBlur = 6;

        ctx.beginPath();
        for (let i = 0; i < 4; i++) {
            ctx.lineTo(Math.cos((i * Math.PI) / 2) * size * 1.2, Math.sin((i * Math.PI) / 2) * size * 1.2);
            ctx.lineTo(Math.cos((i * Math.PI) / 2 + Math.PI / 4) * size * 0.35, Math.sin((i * Math.PI) / 2 + Math.PI / 4) * size * 0.35);
        }
        ctx.closePath();
        ctx.fill();
    }

    // 3. Весна: Нежные зеленые листочки
    function drawSpringBud(ctx, size) {
        ctx.fillStyle = '#34d399';
        ctx.beginPath();
        ctx.moveTo(0, -size);
        ctx.quadraticCurveTo(size * 0.7, 0, 0, size);
        ctx.quadraticCurveTo(-size * 0.7, 0, 0, -size);
        ctx.fill();

        ctx.strokeStyle = '#10b981';
        ctx.lineWidth = 0.8;
        ctx.beginPath();
        ctx.moveTo(0, -size);
        ctx.lineTo(0, size);
        ctx.stroke();
    }

    // 4. 8 Марта / Женский день: Лепестки сакуры
    function drawSakuraPetal(ctx, size) {
        const gradient = ctx.createRadialGradient(0, 0, 0, 0, 0, size);
        gradient.addColorStop(0, '#fbcfe8');
        gradient.addColorStop(0.7, '#f472b6');
        gradient.addColorStop(1, '#e11d48');

        ctx.fillStyle = gradient;
        ctx.beginPath();
        ctx.moveTo(0, -size * 1.1);
        ctx.bezierCurveTo(size * 0.8, -size * 0.6, size * 0.9, size * 0.6, 0, size * 1.1);
        ctx.bezierCurveTo(-size * 0.9, size * 0.6, -size * 0.8, -size * 0.6, 0, -size * 1.1);
        ctx.fill();
    }

    // 5. Лето: Солнечные блики и морские искры
    function drawSunSparkle(ctx, size, hue) {
        const isSun = hue > 0.35;
        ctx.fillStyle = isSun ? 'rgba(251, 191, 36, 0.8)' : 'rgba(56, 189, 248, 0.8)';
        ctx.shadowColor = isSun ? '#f59e0b' : '#0284c7';
        ctx.shadowBlur = 8;

        ctx.beginPath();
        ctx.arc(0, 0, size * 0.6, 0, Math.PI * 2);
        ctx.fill();
    }

    // 6. Осень: Багряные и золотые кленовые листья
    function drawAutumnLeaf(ctx, size, hue) {
        // Выбираем красивый осенний оттенок: золотой, янтарный или багряный
        let color = '#ea580c';
        if (hue < 0.35) color = '#d97706';      // янтарно-золотой
        else if (hue > 0.7) color = '#b91c1c';  // багряный клен

        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.moveTo(0, -size * 1.2);
        ctx.lineTo(size * 0.5, -size * 0.5);
        ctx.lineTo(size * 1.1, -size * 0.2);
        ctx.lineTo(size * 0.6, size * 0.4);
        ctx.lineTo(size * 0.8, size * 1.1);
        ctx.lineTo(0, size * 0.7);
        ctx.lineTo(-size * 0.8, size * 1.1);
        ctx.lineTo(-size * 0.6, size * 0.4);
        ctx.lineTo(-size * 1.1, -size * 0.2);
        ctx.lineTo(-size * 0.5, -size * 0.5);
        ctx.closePath();
        ctx.fill();

        // Прожилка листа
        ctx.strokeStyle = 'rgba(0,0,0,0.2)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, -size * 1.2);
        ctx.lineTo(0, size * 0.9);
        ctx.stroke();
    }

    // Run on DOM ready or immediate if already ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
