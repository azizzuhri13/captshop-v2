<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1920, height=1080">
    <title>CAPTSHOP — Esports HUD Stream Banner (Bangjeff Style)</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:800,900|rajdhani:700,800,900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            width: 1920px;
            height: 1080px;
            overflow: hidden;
            background: transparent;
            font-family: 'Rajdhani', 'Poppins', sans-serif;
            position: relative;
        }
        body.preview-bg {
            background: #0B0F19;
        }

        /* Container Strip Mecha HUD persis ala video referensi Bangjeff */
        .hud-banner {
            position: absolute;
            bottom: 24px;
            right: 32px;
            width: 560px;
            height: 108px;
            background: linear-gradient(90deg, rgba(17, 24, 39, 0.98) 0%, rgba(15, 23, 42, 0.96) 50%, rgba(26, 12, 12, 0.98) 100%);
            border-top: 3px solid #FF3B3B;
            border-bottom: 3px solid #FF3B3B;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            box-shadow: 0 0 28px rgba(255, 59, 59, 0.6), inset 0 0 30px rgba(0, 0, 0, 0.85);
            clip-path: polygon(14px 0, 100% 0, 100% calc(100% - 14px), calc(100% - 14px) 100%, 0 100%, 0 14px);
        }

        /* Rel neon sci-fi menyala di atas dan bawah */
        .hud-banner::before {
            content: '';
            position: absolute;
            top: 0; left: 40px; right: 40px;
            height: 3px;
            background: #FFFFFF;
            box-shadow: 0 0 14px #FF4D4D, 0 0 28px #FF3B3B;
            animation: pulseRail 2s infinite alternate;
        }

        /* 1. SISI KIRI: LOGO CAPTSHOP + BADGE MEREK */
        .brand-zone {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 110px;
            flex-shrink: 0;
        }
        .brand-logo-wrap {
            position: relative;
            animation: floatLogo 2.5s ease-in-out infinite alternate;
        }
        .brand-logo {
            width: 54px;
            height: 54px;
            object-fit: contain;
            filter: drop-shadow(0 0 10px rgba(255, 59, 59, 0.85));
        }
        .brand-name-tag {
            background: linear-gradient(135deg, #B91C1C 0%, #D92525 100%);
            color: #FFFFFF;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 2px;
            padding: 3px 12px;
            clip-path: polygon(6px 0, 100% 0, calc(100% - 6px) 100%, 0 100%);
            margin-top: -3px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.6);
            border: 1px solid rgba(255, 255, 255, 0.25);
        }

        /* 2. SISI TENGAH: ROTATING HUD MESSAGES (PERSIS BANGJEFF) */
        .center-hud-zone {
            flex: 1;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 0 12px;
            position: relative;
            overflow: hidden;
        }
        .hud-border-box {
            position: relative;
            width: 100%;
            padding: 6px 10px;
            background: rgba(0, 0, 0, 0.45);
            border: 1px dashed rgba(255, 59, 59, 0.4);
            border-radius: 6px;
        }
        .hud-border-box::before, .hud-border-box::after {
            content: '';
            position: absolute;
            width: 6px; height: 6px;
            border-color: #FF4D4D;
        }
        .hud-border-box::before { top: -2px; left: -2px; border-top: 2px solid; border-left: 2px solid; }
        .hud-border-box::after { bottom: -2px; right: -2px; border-bottom: 2px solid; border-right: 2px solid; }

        .slide-container {
            transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .slide-container.swap-out {
            opacity: 0;
            transform: scale(0.92) translateY(-4px);
        }
        .slide-container.swap-in {
            opacity: 1;
            transform: scale(1) translateY(0);
        }

        .line-top {
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 2.2px;
            color: #FBBF24;
            text-shadow: 0 0 12px rgba(245, 158, 11, 0.7);
            line-height: 1.1;
            text-transform: uppercase;
        }
        .line-bottom {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 1.8px;
            color: #FFFFFF;
            text-shadow: 0 0 14px rgba(255, 255, 255, 0.8), 0 0 22px rgba(217, 37, 37, 0.85);
            line-height: 1.1;
            text-transform: uppercase;
        }

        /* 3. SISI KANAN: AKSEN DIAMOND 3D & MECHA BARS */
        .right-ornament {
            width: 70px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            flex-shrink: 0;
        }
        .diamond-svg-wrap {
            width: 34px;
            height: 34px;
            animation: spinDiamond 3.5s ease-in-out infinite alternate;
            filter: drop-shadow(0 0 12px #00F2FE) drop-shadow(0 0 20px rgba(0, 242, 254, 0.5));
        }
        .mecha-bars {
            display: flex;
            gap: 3px;
        }
        .mbar {
            width: 4px;
            height: 16px;
            background: #D92525;
            transform: skewX(-20deg);
        }
        .mbar:nth-child(2) { height: 22px; background: #FF4D4D; }
        .mbar:nth-child(3) { height: 12px; background: #FBBF24; }

        /* ================= ANIMATIONS ================= */
        @keyframes floatLogo {
            0% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-4px) rotate(-2deg); }
            100% { transform: translateY(1px) rotate(2deg); }
        }
        @keyframes pulseRail {
            0% { opacity: 0.6; }
            100% { opacity: 1; }
        }
        @keyframes spinDiamond {
            0% { transform: scale(0.95) rotate(-5deg); filter: drop-shadow(0 0 6px #38BDF8); }
            100% { transform: scale(1.1) rotate(5deg); filter: drop-shadow(0 0 16px #38BDF8); }
        }
    </style>
</head>
<body>

    {{-- MECHA HUD BANNER STRIP ALA BANGJEFF --}}
    <div class="hud-banner">

        {{-- SISI KIRI: LOGO CAPTSHOP --}}
        <div class="brand-zone">
            <div class="brand-logo-wrap">
                <img src="/images/captshop-logo.png" alt="CAPTSHOP" class="brand-logo">
            </div>
            <div class="brand-name-tag">CAPTSHOP</div>
        </div>

        {{-- SISI TENGAH: ROTATING TEXT (PERSIS SEPERTI VIDEO REFERENSI) --}}
        <div class="center-hud-zone">
            <div class="hud-border-box">
                <div id="slide-wrap" class="slide-container swap-in">
                    <div id="txt-top" class="line-top">AVAILABLE 24/7</div>
                    <div id="txt-bottom" class="line-bottom">DI CAPTSHOP</div>
                </div>
            </div>
        </div>

        {{-- SISI KANAN: AKSEN DIAMOND 3D & MECHA --}}
        <div class="right-ornament">
            <div class="diamond-svg-wrap">
                <svg viewBox="0 0 100 100" width="100%" height="100%">
                    <defs>
                        <linearGradient id="gemGrad1" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#FFFFFF"/>
                            <stop offset="50%" stop-color="#00F2FE"/>
                            <stop offset="100%" stop-color="#0072FF"/>
                        </linearGradient>
                        <linearGradient id="gemGrad2" x1="100%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#38BDF8"/>
                            <stop offset="100%" stop-color="#0284C7"/>
                        </linearGradient>
                    </defs>
                    <!-- Facet Atas Tengah -->
                    <polygon points="30,25 70,25 50,50" fill="url(#gemGrad1)"/>
                    <!-- Facet Atas Kiri -->
                    <polygon points="12,42 30,25 50,50" fill="#38BDF8"/>
                    <!-- Facet Atas Kanan -->
                    <polygon points="88,42 70,25 50,50" fill="#0284C7"/>
                    <!-- Facet Bawah Tengah -->
                    <polygon points="50,50 50,88 12,42" fill="url(#gemGrad2)"/>
                    <!-- Facet Bawah Kanan -->
                    <polygon points="50,50 50,88 88,42" fill="#0369A1"/>
                    <!-- Kilau Cahaya Tengah -->
                    <polygon points="45,30 55,30 50,45" fill="#FFFFFF" opacity="0.8"/>
                </svg>
            </div>
            <div class="mecha-bars">
                <div class="mbar"></div>
                <div class="mbar"></div>
                <div class="mbar"></div>
            </div>
        </div>

    </div>

    <script>
        // Slides berganti persis seperti di video referensi Bangjeff
        const slides = [
            { top: "AVAILABLE 24/7", bottom: "DI CAPTSHOP" },
            { top: "GAME FAVORITMU ADA", bottom: "DI CAPTSHOP" },
            { top: "🎵 TIKTOK RESMI", bottom: "@CAPTSHOP.ID" },
            { top: "TOP UP GAME CEPAT", bottom: "PROSES 1 DETIK" },
            { top: "KODE PROMO SPESIAL", bottom: "CAPTSHOP" }
        ];

        let idx = 0;
        const wrap = document.getElementById('slide-wrap');
        const topEl = document.getElementById('txt-top');
        const botEl = document.getElementById('txt-bottom');

        setInterval(() => {
            wrap.classList.remove('swap-in');
            wrap.classList.add('swap-out');

            setTimeout(() => {
                idx = (idx + 1) % slides.length;
                topEl.innerText = slides[idx].top;
                botEl.innerText = slides[idx].bottom;
                wrap.classList.remove('swap-out');
                wrap.classList.add('swap-in');
            }, 300);
        }, 3200);

        if (new URLSearchParams(window.location.search).get('bg') === '1') {
            document.body.classList.add('preview-bg');
        }
    </script>
</body>
</html>
