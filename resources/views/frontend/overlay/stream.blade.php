<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1920, height=1080">
    <title>CAPTSHOP — Livestreaming Overlay (OBS / TikTok Live)</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:600,700,800,900|inter:400,600,700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            user-select: none;
        }

        :root {
            --primary: #D92525;
            --primary-glow: rgba(217, 37, 37, 0.7);
            --primary-light: #FF4D4D;
            --accent-gold: #FBBF24;
            --accent-cyan: #06B6D4;
            --dark-glass: rgba(10, 15, 29, 0.82);
            --border-glass: rgba(217, 37, 37, 0.4);
        }

        body {
            width: 1920px;
            height: 1080px;
            overflow: hidden;
            background: transparent;
            font-family: 'Poppins', sans-serif;
            color: #FFFFFF;
            position: relative;
        }

        /* ================= MODALITAS / PRESET MODE ================= */
        /* Mode MLBB / MOBA: Kosongkan Kiri Atas (Minimap) & Tengah Atas (Skor) */
        body.mode-mlbb .top-brand {
            top: auto;
            bottom: 68px;
            left: 428px;
        }

        body.mode-mlbb .squad-cam-container {
            display: none;
        }

        body.mode-mlbb .callout-badge {
            left: auto;
            right: 32px;
            bottom: 68px;
        }

        body.mode-mlbb .bottom-ticker-bar {
            left: 428px;
            right: 32px;
        }

        /* ================= 1. TOP HEADER / BRANDING BAR ================= */
        .top-brand {
            position: absolute;
            top: 24px;
            left: 32px;
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--dark-glass);
            border: 1px solid var(--border-glass);
            padding: 10px 22px 10px 14px;
            border-radius: 16px;
            backdrop-filter: blur(12px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6), 0 0 20px rgba(217, 37, 37, 0.25);
            z-index: 20;
            animation: fadeInDown 0.8s ease-out;
        }

        .brand-logo {
            width: 52px;
            height: 52px;
            object-fit: contain;
            filter: drop-shadow(0 0 12px var(--primary-glow));
            animation: pulseGlow 3s infinite alternate;
        }

        .brand-text h1 {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: 1.5px;
            line-height: 1.1;
            background: linear-gradient(135deg, #FFFFFF 0%, #FCA5A5 50%, #EF4444 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-tagline {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 700;
            color: #94A3B8;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        .tagline-badge {
            background: rgba(217, 37, 37, 0.2);
            color: #F87171;
            padding: 2px 7px;
            border-radius: 6px;
            border: 1px solid rgba(217, 37, 37, 0.4);
            font-size: 9px;
            font-weight: 800;
        }

        /* ================= 2. TOP RIGHT PROMO & CLOCK ================= */
        .top-right-bar {
            position: absolute;
            top: 24px;
            right: 32px;
            display: flex;
            align-items: center;
            gap: 14px;
            z-index: 20;
            animation: fadeInDown 0.8s ease-out;
        }

        .promo-box {
            background: var(--dark-glass);
            border: 1px solid rgba(245, 158, 11, 0.5);
            border-radius: 14px;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5), 0 0 15px rgba(245, 158, 11, 0.2);
        }

        .promo-icon {
            font-size: 20px;
            animation: bounceIcon 2s infinite;
        }

        .promo-code-title {
            font-size: 9px;
            font-weight: 700;
            color: #FBBF24;
            letter-spacing: 0.5px;
        }

        .promo-code-val {
            font-size: 15px;
            font-weight: 900;
            color: #FFFFFF;
            letter-spacing: 1px;
        }

        .status-pill {
            background: var(--dark-glass);
            border: 1px solid var(--border-glass);
            border-radius: 14px;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 800;
            color: #F1F5F9;
        }

        .live-dot {
            width: 10px;
            height: 10px;
            background: #22C55E;
            border-radius: 50%;
            box-shadow: 0 0 12px #22C55E;
            animation: blinkDot 1.5s infinite;
        }

        /* ================= 3. TOP MULTI-CAM / SQUAD WEBCAMS ================= */
        .squad-cam-container {
            position: absolute;
            top: 24px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 16px;
            z-index: 15;
            animation: fadeInDown 1s ease-out;
        }

        .squad-cam-slot {
            width: 250px;
            height: 140px;
            position: relative;
            background: rgba(15, 23, 42, 0.25);
            border: 2px solid rgba(217, 37, 37, 0.5);
            border-radius: 12px;
            overflow: visible;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.7), inset 0 0 15px rgba(0,0,0,0.5);
        }

        /* Corner brackets tech aesthetic */
        .squad-cam-slot::before {
            content: '';
            position: absolute;
            top: -3px; left: -3px;
            width: 14px; height: 14px;
            border-top: 3px solid var(--primary-light);
            border-left: 3px solid var(--primary-light);
            border-radius: 3px 0 0 0;
            filter: drop-shadow(0 0 4px var(--primary));
        }

        .squad-cam-slot::after {
            content: '';
            position: absolute;
            bottom: -3px; right: -3px;
            width: 14px; height: 14px;
            border-bottom: 3px solid var(--primary-light);
            border-right: 3px solid var(--primary-light);
            border-radius: 0 0 3px 0;
            filter: drop-shadow(0 0 4px var(--primary));
        }

        .squad-nameplate {
            position: absolute;
            bottom: -13px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(180deg, #1E293B 0%, #0F172A 100%);
            border: 1px solid var(--border-glass);
            border-radius: 6px;
            padding: 3px 14px;
            font-size: 10px;
            font-weight: 800;
            color: #E2E8F0;
            letter-spacing: 1px;
            text-transform: uppercase;
            white-space: nowrap;
            box-shadow: 0 4px 10px rgba(0,0,0,0.6);
            z-index: 5;
        }

        /* ================= 4. BOTTOM-LEFT MAIN STREAMER CAM ================= */
        .main-cam-container {
            position: absolute;
            bottom: 68px;
            left: 32px;
            width: 380px;
            height: 214px;
            background: rgba(15, 23, 42, 0.15);
            border: 3px solid var(--primary);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8), 0 0 25px rgba(217, 37, 37, 0.35);
            z-index: 15;
            animation: fadeInLeft 1s ease-out;
        }

        /* Corner glowing tabs */
        .main-cam-container::before {
            content: '';
            position: absolute;
            top: -4px; right: -4px;
            width: 20px; height: 20px;
            border-top: 4px solid var(--primary-light);
            border-right: 4px solid var(--primary-light);
            border-radius: 0 6px 0 0;
            filter: drop-shadow(0 0 8px var(--primary-light));
        }

        .main-cam-container::after {
            content: '';
            position: absolute;
            bottom: -4px; left: -4px;
            width: 20px; height: 20px;
            border-bottom: 4px solid var(--primary-light);
            border-left: 4px solid var(--primary-light);
            border-radius: 0 0 0 6px;
            filter: drop-shadow(0 0 8px var(--primary-light));
        }

        /* Official badge pinned to main cam */
        .main-cam-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid var(--primary);
            border-radius: 8px;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 800;
            color: #FFFFFF;
            backdrop-filter: blur(8px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.5);
            z-index: 5;
        }

        .badge-logo-mini {
            width: 14px;
            height: 14px;
            object-fit: contain;
        }

        .main-cam-nameplate {
            position: absolute;
            bottom: -16px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #B91C1C 0%, #D92525 50%, #991B1B 100%);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 5px 24px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 6px 18px rgba(217, 37, 37, 0.4), 0 4px 12px rgba(0,0,0,0.8);
            white-space: nowrap;
            z-index: 5;
        }

        .streamer-name {
            font-size: 13px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #FFFFFF;
            text-transform: uppercase;
        }

        .streamer-handle {
            font-size: 11px;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.8);
            border-left: 1px solid rgba(255, 255, 255, 0.3);
            padding-left: 8px;
        }

        /* ================= 5. POP-UP BADGE (INSPIRASI: HANYA 1 DETIK?) ================= */
        .callout-badge {
            position: absolute;
            bottom: 78px;
            left: 428px;
            background: linear-gradient(135deg, #1E293B 0%, #0F172A 100%);
            border: 2px solid var(--accent-gold);
            border-radius: 16px;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.7), 0 0 20px rgba(245, 158, 11, 0.3);
            animation: floatBadge 3s ease-in-out infinite alternate;
            z-index: 15;
        }

        .callout-title {
            font-size: 11px;
            font-weight: 700;
            color: #94A3B8;
            text-transform: uppercase;
        }

        .callout-highlight {
            font-size: 18px;
            font-weight: 900;
            color: var(--accent-gold);
            letter-spacing: 0.5px;
            text-shadow: 0 0 10px rgba(245, 158, 11, 0.5);
        }

        /* ================= 6. BOTTOM MARQUEE TICKER (OURASTORE / BANGJEFF STYLE) ================= */
        .bottom-ticker-bar {
            position: absolute;
            bottom: 14px;
            left: 32px;
            right: 32px;
            height: 40px;
            background: var(--dark-glass);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            display: flex;
            align-items: center;
            overflow: hidden;
            backdrop-filter: blur(12px);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.7);
            z-index: 20;
        }

        .ticker-label {
            background: linear-gradient(135deg, #D92525 0%, #991B1B 100%);
            height: 100%;
            padding: 0 18px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #FFFFFF;
            flex-shrink: 0;
            box-shadow: 4px 0 12px rgba(0,0,0,0.4);
            z-index: 2;
        }

        .ticker-marquee {
            flex: 1;
            overflow: hidden;
            white-space: nowrap;
            position: relative;
        }

        .ticker-content {
            display: inline-block;
            white-space: nowrap;
            animation: marqueeScroll 35s linear infinite;
            font-size: 13px;
            font-weight: 700;
            color: #E2E8F0;
            padding-left: 20px;
        }

        .ticker-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-right: 32px;
        }

        .game-tag {
            color: #F87171;
            font-weight: 800;
        }

        .price-tag {
            color: #38BDF8;
            font-weight: 900;
        }

        .dot-sep {
            color: #64748B;
            margin: 0 8px;
        }

        /* ================= KEYFRAME ANIMATIONS ================= */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInLeft {
            from { opacity: 0; transform: translateX(-40px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes pulseGlow {
            from { filter: drop-shadow(0 0 6px var(--primary)); }
            to { filter: drop-shadow(0 0 16px var(--primary-light)); }
        }

        @keyframes blinkDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        @keyframes bounceIcon {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        @keyframes floatBadge {
            from { transform: translateY(0); }
            to { transform: translateY(-8px); }
        }

        @keyframes marqueeScroll {
            0% { transform: translateX(0%); }
            100% { transform: translateX(-50%); }
        }
    </style>
</head>
<body id="overlay-body">

    {{-- 1. TOP BRANDING BANNER --}}
    <div class="top-brand">
        <img src="/images/captshop-logo.png" alt="CAPTSHOP" class="brand-logo">
        <div class="brand-text">
            <h1>CAPTSHOP.ID</h1>
            <div class="brand-tagline">
                <span>Top Up Game Termurah</span>
                <span class="tagline-badge">24 JAM INSTAN</span>
            </div>
        </div>
    </div>

    {{-- 2. TOP RIGHT PROMO & LIVE CLOCK --}}
    <div class="top-right-bar">
        <div class="promo-box">
            <span class="promo-icon">🎁</span>
            <div>
                <p class="promo-code-title">KODE PROMO SPESIAL</p>
                <p class="promo-code-val">CAPTSHOP</p>
            </div>
        </div>
        <div class="status-pill">
            <span class="live-dot"></span>
            <span id="live-clock">--:--:-- WIB</span>
        </div>
    </div>

    {{-- 3. TOP MULTI-CAM SQUAD (3 GUEST WEBCAMS) --}}
    <div class="squad-cam-container" id="squad-cams">
        <div class="squad-cam-slot">
            <div class="squad-nameplate" id="name-guest1">SQUAD 1</div>
        </div>
        <div class="squad-cam-slot">
            <div class="squad-nameplate" id="name-guest2">SQUAD 2</div>
        </div>
        <div class="squad-cam-slot">
            <div class="squad-nameplate" id="name-guest3">SQUAD 3</div>
        </div>
    </div>

    {{-- 4. BOTTOM-LEFT MAIN STREAMER WEBCAM FRAME --}}
    <div class="main-cam-container" id="main-cam">
        <div class="main-cam-badge">
            <img src="/images/captshop-logo.png" alt="C" class="badge-logo-mini">
            <span>OFFICIAL STORE</span>
        </div>
        <div class="main-cam-nameplate">
            <span class="streamer-name" id="streamer-name">STREAMER</span>
            <span class="streamer-handle">@captshop.id</span>
        </div>
    </div>

    {{-- 5. FLOATING CALLOUT (INSPIRASI VIDEO: HANYA 1 DETIK?) --}}
    <div class="callout-badge">
        <span style="font-size: 24px;">⚡</span>
        <div>
            <p class="callout-title">TOP UP GAME CEPAT?</p>
            <p class="callout-highlight">HANYA 1 DETIK!</p>
        </div>
    </div>

    {{-- 6. BOTTOM MARQUEE RUNNING TICKER --}}
    <div class="bottom-ticker-bar">
        <div class="ticker-label">
            <span>🔥 HARGA SPESIAL</span>
        </div>
        <div class="ticker-marquee">
            <div class="ticker-content" id="ticker-text">
                {{-- Loop 1 --}}
                <span class="ticker-item"><span class="game-tag">MOBILE LEGENDS:</span> Weekly Pass <span class="price-tag">Rp 32.150</span></span>
                <span class="ticker-item"><span class="game-tag">MLBB:</span> 86 Diamonds <span class="price-tag">Rp 15.000</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">FREE FIRE:</span> 140 Diamonds <span class="price-tag">Rp 18.250</span></span>
                <span class="ticker-item"><span class="game-tag">FF:</span> Booyah Pass <span class="price-tag">Rp 41.000</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">MAGIC CHESS GO GO:</span> 100 DM <span class="price-tag">Rp 14.500</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">PUBG MOBILE:</span> 60 UC <span class="price-tag">Rp 16.000</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">VALORANT:</span> 125 VP <span class="price-tag">Rp 16.000</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">HONOR OF KINGS:</span> 80 Token <span class="price-tag">Rp 11.500</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item" style="color: #FBBF24;">⚡ SISTEM OTOMATIS 24 JAM TANPA ANTRI • WEB RESMI: WWW.CAPTSHOP.ID • PEMBAYARAN: QRIS, DANA, GOPAY, SHOPEEPAY</span>
                <span class="dot-sep">•</span>

                {{-- Loop 2 (Deduplikasi agar scroll seamless) --}}
                <span class="ticker-item"><span class="game-tag">MOBILE LEGENDS:</span> Weekly Pass <span class="price-tag">Rp 32.150</span></span>
                <span class="ticker-item"><span class="game-tag">MLBB:</span> 86 Diamonds <span class="price-tag">Rp 15.000</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">FREE FIRE:</span> 140 Diamonds <span class="price-tag">Rp 18.250</span></span>
                <span class="ticker-item"><span class="game-tag">FF:</span> Booyah Pass <span class="price-tag">Rp 41.000</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">MAGIC CHESS GO GO:</span> 100 DM <span class="price-tag">Rp 14.500</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">PUBG MOBILE:</span> 60 UC <span class="price-tag">Rp 16.000</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">VALORANT:</span> 125 VP <span class="price-tag">Rp 16.000</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item"><span class="game-tag">HONOR OF KINGS:</span> 80 Token <span class="price-tag">Rp 11.500</span></span>
                <span class="dot-sep">•</span>
                <span class="ticker-item" style="color: #FBBF24;">⚡ SISTEM OTOMATIS 24 JAM TANPA ANTRI • WEB RESMI: WWW.CAPTSHOP.ID • PEMBAYARAN: QRIS, DANA, GOPAY, SHOPEEPAY</span>
                <span class="dot-sep">•</span>
            </div>
        </div>
    </div>

    <script>
        // Update Live Clock WIB
        function updateClock() {
            const now = new Date();
            // Offset to UTC+7 (WIB)
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const wib = new Date(utc + (3600000 * 7));
            const hours = String(wib.getHours()).padStart(2, '0');
            const minutes = String(wib.getMinutes()).padStart(2, '0');
            const seconds = String(wib.getSeconds()).padStart(2, '0');
            document.getElementById('live-clock').innerText = `${hours}:${minutes}:${seconds} WIB`;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Parameter Kustomisasi URL (?name=ZIO&squad=0&bg=1)
        const params = new URLSearchParams(window.location.search);
        if (params.get('name')) {
            document.getElementById('streamer-name').innerText = params.get('name');
        }
        if (params.get('guest1')) document.getElementById('name-guest1').innerText = params.get('guest1');
        if (params.get('guest2')) document.getElementById('name-guest2').innerText = params.get('guest2');
        if (params.get('guest3')) document.getElementById('name-guest3').innerText = params.get('guest3');
        if (params.get('squad') === '0') {
            document.getElementById('squad-cams').style.display = 'none';
        }
        if (params.get('ticker') === '0') {
            document.querySelector('.bottom-ticker-bar').style.display = 'none';
        }
        if (params.get('mode') === 'mlbb' || params.get('moba') === '1') {
            document.body.classList.add('mode-mlbb');
        }
        if (params.get('bg') === '1' || params.get('preview') === '1') {
            document.body.classList.add('preview-bg');
        }
    </script>
</body>
</html>
