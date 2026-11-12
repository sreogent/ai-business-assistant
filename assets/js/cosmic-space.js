/**
 * AI Business Assistant — Cosmic Space Engine & Animated Rocket
 * Features: Deep twinkling starfield, nebulae glow, and an animated flying little rocket
 * that patrols the cosmos back and forth across the screen with thruster particle trail.
 */
(function() {
    'use strict';

    const canvas = document.getElementById('space-canvas');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    let width = canvas.width = window.innerWidth;
    let height = canvas.height = window.innerHeight;

    let animId = null;
    let mouse = { x: -1000, y: -1000, active: false };

    // Stars generation
    const STAR_COUNT = Math.min(140, Math.floor((width * height) / 9000));
    const stars = [];

    for (let i = 0; i < STAR_COUNT; i++) {
        stars.push({
            x: Math.random() * width,
            y: Math.random() * height,
            radius: Math.random() * 1.6 + 0.4,
            baseAlpha: Math.random() * 0.7 + 0.2,
            alpha: Math.random(),
            twinkleSpeed: Math.random() * 0.02 + 0.005,
            color: (Math.random() > 0.8) ? '#a5b4fc' : ((Math.random() > 0.6) ? '#38bdf8' : '#ffffff'),
            isCross: Math.random() > 0.92 // occasional 4-point star
        });
    }

    // Rocket State
    const rocket = {
        x: -60,
        y: height * 0.25,
        speed: 2.2,
        direction: 1, // 1: flying right, -1: flying left
        targetDirection: 1,
        angle: 0,
        targetAngle: 0,
        turning: false,
        turnProgress: 0,
        waveTime: 0,
        waveAmplitude: Math.min(120, height * 0.18),
        waveFrequency: 0.003,
        baseY: height * 0.28,
        boost: 1,
        flameFlicker: 1,
        particles: []
    };

    function onResize() {
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
        rocket.baseY = Math.min(height * 0.35, Math.max(160, height * 0.25));
        rocket.waveAmplitude = Math.min(120, height * 0.16);
    }
    window.addEventListener('resize', onResize);

    window.addEventListener('mousemove', (e) => {
        mouse.x = e.clientX;
        mouse.y = e.clientY;
        mouse.active = true;
    });
    window.addEventListener('mouseleave', () => {
        mouse.active = false;
    });

    // Particle class for rocket engine exhaust
    function createExhaustParticle(rx, ry, rAngle, rSpeed, boost) {
        // Offset to engine nozzle (back of rocket)
        const nozzleDist = -24;
        const px = rx + Math.cos(rAngle) * nozzleDist + (Math.random() - 0.5) * 4;
        const py = ry + Math.sin(rAngle) * nozzleDist + (Math.random() - 0.5) * 4;

        // Exhaust speed backwards
        const spread = (Math.random() - 0.5) * 0.6;
        const pSpeed = (2.5 + Math.random() * 3) * boost;
        const pAngle = rAngle + Math.PI + spread;

        const colors = ['#f59e0b', '#fbbf24', '#38bdf8', '#818cf8', '#ec4899', '#ffffff'];
        const color = colors[Math.floor(Math.random() * colors.length)];

        return {
            x: px,
            y: py,
            vx: Math.cos(pAngle) * pSpeed,
            vy: Math.sin(pAngle) * pSpeed,
            size: Math.random() * 3.5 + 1.5,
            alpha: 0.9,
            color: color,
            life: 1.0,
            decay: Math.random() * 0.035 + 0.025
        };
    }

    // Draw Rocket
    function drawRocket(x, y, angle, boost) {
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(angle);

        // Rocket scale (sleek, compact)
        const s = 1.0;
        ctx.scale(s, s);

        // 1. Engine Thrust Glow / Flare
        const flameLength = (16 + Math.random() * 12) * boost;
        const flameWidth = (7 + Math.random() * 4) * boost;

        // Outer Flame (Cyan/Blue halo)
        const outerGrad = ctx.createRadialGradient(-20, 0, 2, -20 - flameLength, 0, flameWidth * 2);
        outerGrad.addColorStop(0, 'rgba(56, 189, 248, 0.8)');
        outerGrad.addColorStop(0.5, 'rgba(99, 102, 241, 0.4)');
        outerGrad.addColorStop(1, 'rgba(99, 102, 241, 0)');
        ctx.fillStyle = outerGrad;
        ctx.beginPath();
        ctx.arc(-22, 0, flameLength + 10, 0, Math.PI * 2);
        ctx.fill();

        // Inner Core Flame (Orange/Yellow plasma cone)
        ctx.beginPath();
        ctx.moveTo(-18, -flameWidth * 0.8);
        ctx.quadraticCurveTo(-22, 0, -18 - flameLength, 0);
        ctx.quadraticCurveTo(-22, 0, -18, flameWidth * 0.8);
        ctx.closePath();
        const coreGrad = ctx.createLinearGradient(-18, 0, -18 - flameLength, 0);
        coreGrad.addColorStop(0, '#ffffff');
        coreGrad.addColorStop(0.3, '#fde047');
        coreGrad.addColorStop(0.7, '#f97316');
        coreGrad.addColorStop(1, 'rgba(239, 68, 68, 0)');
        ctx.fillStyle = coreGrad;
        ctx.shadowColor = '#f59e0b';
        ctx.shadowBlur = 12;
        ctx.fill();
        ctx.shadowBlur = 0;

        // 2. Rocket Wings (Fins)
        // Top Fin
        ctx.fillStyle = '#6366f1';
        ctx.beginPath();
        ctx.moveTo(-10, -6);
        ctx.lineTo(-20, -18);
        ctx.lineTo(-6, -8);
        ctx.closePath();
        ctx.fill();

        // Bottom Fin
        ctx.beginPath();
        ctx.moveTo(-10, 6);
        ctx.lineTo(-20, 18);
        ctx.lineTo(-6, 8);
        ctx.closePath();
        ctx.fill();

        // Top Fin highlight
        ctx.fillStyle = '#a5b4fc';
        ctx.beginPath();
        ctx.moveTo(-10, -6);
        ctx.lineTo(-19, -16);
        ctx.lineTo(-13, -7);
        ctx.closePath();
        ctx.fill();

        // 3. Rocket Body (Fuselage)
        ctx.beginPath();
        ctx.moveTo(26, 0); // Nose tip
        ctx.bezierCurveTo(18, -10, -12, -10, -18, -7);
        ctx.lineTo(-18, 7);
        ctx.bezierCurveTo(-12, 10, 18, 10, 26, 0);
        ctx.closePath();

        const bodyGrad = ctx.createLinearGradient(-18, -10, 26, 10);
        bodyGrad.addColorStop(0, '#1e293b');
        bodyGrad.addColorStop(0.3, '#e2e8f0');
        bodyGrad.addColorStop(0.7, '#ffffff');
        bodyGrad.addColorStop(1, '#f1f5f9');
        ctx.fillStyle = bodyGrad;
        ctx.shadowColor = 'rgba(99, 102, 241, 0.4)';
        ctx.shadowBlur = 10;
        ctx.fill();
        ctx.shadowBlur = 0;

        // Body outline
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.6)';
        ctx.lineWidth = 1;
        ctx.stroke();

        // Red / Accent Nose Cone
        ctx.beginPath();
        ctx.moveTo(26, 0);
        ctx.bezierCurveTo(22, -4, 16, -6, 14, -6.5);
        ctx.lineTo(14, 6.5);
        ctx.bezierCurveTo(16, 6, 22, 4, 26, 0);
        ctx.closePath();
        const noseGrad = ctx.createLinearGradient(14, 0, 26, 0);
        noseGrad.addColorStop(0, '#ef4444');
        noseGrad.addColorStop(1, '#f43f5e');
        ctx.fillStyle = noseGrad;
        ctx.fill();

        // 4. Cockpit Porthole (Glowing cyan glass window)
        ctx.beginPath();
        ctx.arc(3, 0, 5, 0, Math.PI * 2);
        const glassGrad = ctx.createRadialGradient(2, -1, 1, 3, 0, 5);
        glassGrad.addColorStop(0, '#cffafe');
        glassGrad.addColorStop(0.6, '#06b6d4');
        glassGrad.addColorStop(1, '#0e7490');
        ctx.fillStyle = glassGrad;
        ctx.shadowColor = '#22d3ee';
        ctx.shadowBlur = 10;
        ctx.fill();
        ctx.shadowBlur = 0;

        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 1.2;
        ctx.stroke();

        // Window specular reflection
        ctx.beginPath();
        ctx.arc(1.5, -2, 1.5, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
        ctx.fill();

        // 5. Engine Nozzle ring
        ctx.fillStyle = '#475569';
        ctx.fillRect(-20, -4.5, 3, 9);

        ctx.restore();
    }

    function update() {
        // 1. Update Rocket Flight Path
        rocket.waveTime += rocket.waveFrequency;

        // Mouse proximity boost
        const dx = rocket.x - mouse.x;
        const dy = rocket.y - mouse.y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (mouse.active && dist < 140) {
            rocket.boost = Math.min(1.8, rocket.boost + 0.08);
        } else {
            rocket.boost = Math.max(1.0, rocket.boost - 0.04);
        }

        const currentSpeed = rocket.speed * rocket.boost;

        // Turnaround boundary logic
        const margin = 100;
        if (rocket.direction === 1 && rocket.x > width + margin) {
            // Initiate U-Turn on right edge
            rocket.direction = -1;
            rocket.baseY = Math.max(120, Math.min(height * 0.65, height * 0.45 + (Math.random() - 0.5) * 160));
        } else if (rocket.direction === -1 && rocket.x < -margin) {
            // Initiate U-Turn on left edge
            rocket.direction = 1;
            rocket.baseY = Math.max(120, Math.min(height * 0.65, height * 0.28 + (Math.random() - 0.5) * 160));
        }

        // Advance X
        rocket.x += rocket.direction * currentSpeed;

        // Calculate Y on smooth wave
        const waveOffset = Math.sin(rocket.waveTime * 2.5) * rocket.waveAmplitude;
        rocket.y = rocket.baseY + waveOffset;

        // Target angle based on derivative of flight path
        const slope = Math.cos(rocket.waveTime * 2.5) * (rocket.waveAmplitude * 0.02);
        let desiredAngle = Math.atan2(slope, rocket.direction);
        if (rocket.direction === -1) {
            desiredAngle = Math.PI - Math.atan2(slope, 1);
        }

        // Smooth angle interpolation
        let diff = desiredAngle - rocket.angle;
        while (diff < -Math.PI) diff += Math.PI * 2;
        while (diff > Math.PI) diff -= Math.PI * 2;
        rocket.angle += diff * 0.08;

        // Spawn exhaust particles
        const spawnCount = Math.floor(2 * rocket.boost);
        for (let s = 0; s < spawnCount; s++) {
            rocket.particles.push(createExhaustParticle(rocket.x, rocket.y, rocket.angle, currentSpeed, rocket.boost));
        }

        // Update exhaust particles
        for (let i = rocket.particles.length - 1; i >= 0; i--) {
            const p = rocket.particles[i];
            p.x += p.vx;
            p.y += p.vy;
            p.life -= p.decay;
            p.alpha = Math.max(0, p.life);
            p.size *= 0.98;
            if (p.life <= 0) {
                rocket.particles.splice(i, 1);
            }
        }
    }

    function render() {
        ctx.clearRect(0, 0, width, height);

        // 1. Draw Twinkling Stars
        for (let i = 0; i < stars.length; i++) {
            const st = stars[i];
            st.alpha += st.twinkleSpeed;
            const currentAlpha = st.baseAlpha + Math.sin(st.alpha) * 0.35;
            const finalAlpha = Math.max(0.1, Math.min(1.0, currentAlpha));

            ctx.fillStyle = st.color;
            ctx.globalAlpha = finalAlpha;

            if (st.isCross && finalAlpha > 0.6) {
                // Subtle 4-point twinkle sparkle
                ctx.beginPath();
                ctx.arc(st.x, st.y, st.radius, 0, Math.PI * 2);
                ctx.fill();

                ctx.strokeStyle = st.color;
                ctx.lineWidth = 0.8;
                ctx.beginPath();
                ctx.moveTo(st.x - st.radius * 3, st.y);
                ctx.lineTo(st.x + st.radius * 3, st.y);
                ctx.moveTo(st.x, st.y - st.radius * 3);
                ctx.lineTo(st.x, st.y + st.radius * 3);
                ctx.stroke();
            } else {
                ctx.beginPath();
                ctx.arc(st.x, st.y, st.radius, 0, Math.PI * 2);
                ctx.fill();
            }
        }
        ctx.globalAlpha = 1.0;

        // 2. Draw Exhaust Particles (stardust trail)
        for (let i = 0; i < rocket.particles.length; i++) {
            const p = rocket.particles[i];
            ctx.save();
            ctx.globalAlpha = p.alpha;
            ctx.fillStyle = p.color;
            ctx.shadowColor = p.color;
            ctx.shadowBlur = 6;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        }

        // 3. Draw Little Rocket
        drawRocket(rocket.x, rocket.y, rocket.angle, rocket.boost);
    }

    function loop() {
        update();
        render();
        animId = requestAnimationFrame(loop);
    }

    // Visibility toggle to save CPU/battery
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            cancelAnimationFrame(animId);
        } else {
            animId = requestAnimationFrame(loop);
        }
    });

    animId = requestAnimationFrame(loop);
})();
