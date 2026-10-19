<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAPTSHOP — MLBB TikTok Live Bottom Dock Stream Overlay</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:700,800,900|inter:500,600,700,800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            user-select: none;
        }

        :root {
            --primary: #D92525;
            --primary-glow: rgba(217, 37, 37, 0.75);
            --primary-light: #FF4D4D;
            --accent-gold: #FBBF24;
            --accent-cyan: #38BDF8;
            --dark-dock: linear-gradient(180deg, rgba(15, 23, 42, 0.94) 0%, rgba(9, 13, 24, 0.98) 100%);
            --border-red: rgba(217, 37, 37, 0.6);
        }

        body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background: transparent;
            font-family: 'Poppins', sans-serif;
            color: #FFFFFF;
            position: relative;
        }

        body.preview-bg {
            background: radial-gradient(circle at center, #1E293B 0%, #0F172A 100%);
        }

        /* ================= BOTTOM DOCK CONTAINER ================= */
        .bottom-dock {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 200px;
            background: var(--dark-dock);
            border-top: 3px solid var(--primary);
            box-shadow: 0 -8px 30px rgba(0, 0, 0, 0.8), 0 -2px 18px var(--primary-glow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            z-index: 100;
            backdrop-filter: blur(12px);
        }

        /* Top highlight glow line */
        .bottom-dock::before {
            content: '';
            position: absolute;
            top: 0;
            left: 15%;
            right: 15%;
            height: 2px;
            background: linear-gradient(90deg, transparent, #FF4D4D, #FBBF24, #FF4D4D, transparent);
            filter: drop-shadow(0 0 6px #FF4D4D);
        }

        /* ================= ZONE 1: FACECAM (LEFT) ================= */
        .zone-facecam {
            width: 250px;
            height: 175px;
            position: relative;
            background: rgba(15, 23, 42, 0.35);
            border: 2px solid var(--primary);
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.7), 0 0 16px rgba(217, 37, 37, 0.4);
            flex-shrink: 0;
        }

        /* Cyberpunk corners */
        .zone-facecam::before {
            content: '';
            position: absolute;
            top: -4px; left: -4px;
            width: 16px; height: 16px;
            border-top: 3px solid var(--primary-light);
            border-left: 3px solid var(--primary-light);
            border-radius: 4px 0 0 0;
        }

        .zone-facecam::after {
            content: '';
            position: absolute;
            bottom: -4px; right: -4px;
            width: 16px; height: 16px;
            border-bottom: 3px solid var(--primary-light);
            border-right: 3px solid var(--primary-light);
            border-radius: 0 0 4px 0;
        }

        .facecam-badge {
            position: absolute;
            top: -10px;
            left: 12px;
            background: #D92525;
            color: #FFFFFF;
            font-size: 9px;
            font-weight: 900;
            letter-spacing: 1px;
            padding: 2px 8px;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .facecam-live-dot {
            width: 6px;
            height: 6px;
            background: #FFFFFF;
            border-radius: 50%;
            animation: pulseDot 1s infinite alternate;
        }

        .facecam-nameplate {
            position: absolute;
            bottom: 6px;
            left: 8px;
            right: 8px;
            background: rgba(10, 15, 29, 0.85);
            border: 1px solid var(--border-red);
            border-radius: 6px;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 800;
            backdrop-filter: blur(4px);
        }

        .streamer-ign {
            color: #FFFFFF;
            letter-spacing: 0.5px;
        }

        .streamer-sub {
            color: #F87171;
            font-size: 9px;
        }

        /* ================= ZONE 2: DONATION & ALERTS (CENTER-LEFT) ================= */
        .zone-donation {
            width: 220px;
            height: 175px;
            display: flex;
            flex-col: column;
            flex-direction: column;
            justify-content: space-between;
            padding: 4px 0;
            flex-shrink: 0;
        }

        .goal-card {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(245, 158, 11, 0.4);
            border-radius: 10px;
            padding: 8px 12px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.4);
        }

        .goal-header {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            font-weight: 800;
            color: var(--accent-gold);
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .goal-bar-bg {
            width: 100%;
            height: 10px;
            background: #1E293B;
            border-radius: 5px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .goal-bar-fill {
            height: 100%;
            width: 35%; /* Progress example */
            background: linear-gradient(90deg, #F59E0B, #EF4444);
            border-radius: 5px;
            box-shadow: 0 0 8px rgba(245, 158, 11, 0.6);
        }

        .goal-amount {
            font-size: 10px;
            font-weight: 700;
            color: #CBD5E1;
            margin-top: 4px;
            text-align: right;
        }

        .alert-ticker-box {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-radius: 10px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 10px;
            color: #E2E8F0;
            overflow: hidden;
        }

        .alert-bell {
            font-size: 14px;
            color: #38BDF8;
            animation: ringBell 2s infinite;
        }

        .alert-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 600;
        }

        /* ================= ZONE 3: MENU JASA & SQUAD (CENTER) ================= */
        .zone-services {
            width: 180px;
            height: 175px;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-size: 10px;
            flex-shrink: 0;
        }

        .services-title {
            font-size: 10px;
            font-weight: 900;
            color: #F87171;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 4px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .service-row {
            display: flex;
            justify-content: space-between;
            color: #CBD5E1;
            font-weight: 600;
            font-size: 9.5px;
            line-height: 1.4;
        }

        .service-price {
            color: var(--accent-gold);
            font-weight: 800;
        }

        .squad-roles {
            border-top: 1px dashed rgba(255, 255, 255, 0.15);
            padding-top: 4px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2px 6px;
            font-size: 8.5px;
            color: #94A3B8;
        }

        .squad-role-item strong {
            color: #38BDF8;
        }

        /* ================= ZONE 4: SPONSOR TOKO UTAMA CAPTSHOP (CENTER-RIGHT) ================= */
        .zone-sponsor {
            flex: 1;
            max-width: 480px;
            height: 175px;
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.85) 100%);
            border: 2px solid var(--border-red);
            border-radius: 16px;
            padding: 12px 18px;
            margin: 0 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.6), inset 0 0 15px rgba(217, 37, 37, 0.15);
        }

        .sponsor-top-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sponsor-logo {
            width: 48px;
            height: 48px;
            object-fit: contain;
            filter: drop-shadow(0 0 10px var(--primary-glow));
            animation: pulseGlow 3s infinite alternate;
        }

        .sponsor-title-box h2 {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 1px;
            line-height: 1.1;
            background: linear-gradient(135deg, #FFFFFF 0%, #FECACA 50%, #EF4444 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sponsor-slogan {
            font-size: 11px;
            font-weight: 800;
            color: var(--accent-gold);
            letter-spacing: 1px;
            text-transform: uppercase;
            text-shadow: 0 0 8px rgba(245, 158, 11, 0.4);
        }

        .sponsor-pills {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
        }

        .store-pill {
            background: #FFFFFF;
            color: #0F172A;
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 10px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
            text-decoration: none;
        }

        .store-pill.red-pill {
            background: linear-gradient(135deg, #D92525 0%, #B91C1C 100%);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .sponsor-bottom-ticker {
            font-size: 10px;
            font-weight: 700;
            color: #94A3B8;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sponsor-bottom-ticker span.highlight {
            color: #22C55E;
            font-weight: 800;
        }

        /* ================= ZONE 5: PROMO / QRIS / MASCOT (RIGHT) ================= */
        .zone-promo-action {
            width: 220px;
            height: 175px;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 14px;
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: center;
            flex-shrink: 0;
            box-shadow: 0 6px 18px rgba(0,0,0,0.5);
        }

        .promo-tag-top {
            font-size: 10px;
            font-weight: 900;
            color: #F87171;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .promo-big-code {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: 1.5px;
            color: var(--accent-gold);
            text-shadow: 0 0 10px rgba(245, 158, 11, 0.5);
            background: rgba(0, 0, 0, 0.4);
            border: 1px dashed var(--accent-gold);
            border-radius: 8px;
            padding: 4px 0;
            margin: 4px 0;
        }

        .payment-icons-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 9px;
            font-weight: 800;
            color: #94A3B8;
        }

        .pay-badge {
            background: rgba(255, 255, 255, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            color: #E2E8F0;
        }

        .live-timer-row {
            font-size: 10px;
            font-weight: 700;
            color: #64748B;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 4px;
        }

        /* ================= KEYFRAME ANIMATIONS ================= */
        @keyframes pulseGlow {
            from { filter: drop-shadow(0 0 6px var(--primary)); }
            to { filter: drop-shadow(0 0 16px var(--primary-light)); }
        }

        @keyframes pulseDot {
            from { opacity: 0.5; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1.1); }
        }

        @keyframes ringBell {
            0%, 100% { transform: rotate(0); }
            10%, 30% { transform: rotate(14deg); }
            20%, 40% { transform: rotate(-14deg); }
            50% { transform: rotate(0); }
        }
    </style>
</head>
<body id="dock-body">

    {{-- BOTTOM STREAM DOCK (TIKTOK LIVE / OBS STYLE) --}}
    <div class="bottom-dock">

        {{-- 1. ZONE FACECAM (LEFT) --}}
        <div class="zone-facecam">
            <div class="facecam-badge">
                <span class="facecam-live-dot"></span>
                <span>LIVE</span>
            </div>
            <div class="facecam-nameplate">
                <span class="streamer-ign" id="streamer-ign">★ STREAMER</span>
                <span class="streamer-sub">MLBB CREATOR</span>
            </div>
        </div>

        {{-- 2. ZONE DONATION & ALERTS (CENTER-LEFT) --}}
        <div class="zone-donation">
            <div class="goal-card">
                <div class="goal-header">
                    <span id="goal-title">TARGET BULAN INI</span>
                    <span style="color:#FFF;">35%</span>
                </div>
                <div class="goal-bar-bg">
                    <div class="goal-bar-fill"></div>
                </div>
                <p class="goal-amount">Rp 350.000 / Rp 1.000.000</p>
            </div>

            <div class="alert-ticker-box">
                <span class="alert-bell">🔔</span>
                <span class="alert-text" id="alert-text">SultanML baru saja mawar x10</span>
            </div>
        </div>

        {{-- 3. ZONE MENU JASA & SQUAD (CENTER) --}}
        <div class="zone-services">
            <div class="services-title">
                <span>📋</span>
                <span>MENU STREAM</span>
            </div>
            <div class="service-row">
                <span>KOMEN ALBUM:</span>
                <span class="service-price">10K</span>
            </div>
            <div class="service-row">
                <span>MABAR 1 MATCH:</span>
                <span class="service-price">20K</span>
            </div>
            <div class="service-row">
                <span>GIFT SKIN:</span>
                <span class="service-price">TOPUP</span>
            </div>
            <div class="squad-roles">
                <div class="squad-role-item"><strong>R:</strong> TIGREAL</div>
                <div class="squad-role-item"><strong>M:</strong> PHARSA</div>
                <div class="squad-role-item"><strong>G:</strong> MOSKOV</div>
                <div class="squad-role-item"><strong>E:</strong> BENEDETTA</div>
            </div>
        </div>

        {{-- 4. ZONE SPONSOR TOKO TOP-UP CAPTSHOP (CENTER-RIGHT) --}}
        <div class="zone-sponsor">
            <div class="sponsor-top-row">
                <img src="/images/captshop-logo.png" alt="CAPTSHOP" class="sponsor-logo">
                <div class="sponsor-title-box">
                    <h2>CAPTSHOP.ID</h2>
                    <p class="sponsor-slogan">PUSAT TOP UP GAME TERMURAH</p>
                </div>
            </div>

            <div class="sponsor-pills">
                <a href="https://captshop.id" target="_blank" class="store-pill">
                    <span>🌐</span>
                    <span>captshop.id</span>
                </a>
                <span class="store-pill red-pill">
                    <span>⚡</span>
                    <span>PROSES 1 DETIK</span>
                </span>
                <span class="store-pill">
                    <span>💬</span>
                    <span>CS 24 JAM</span>
                </span>
            </div>

            <div class="sponsor-bottom-ticker">
                <span>MLBB Weekly Pass <strong style="color:#FFF;">Rp 32.150</strong></span>
                <span>•</span>
                <span>86 DM <strong style="color:#FFF;">Rp 15.000</strong></span>
                <span>•</span>
                <span class="highlight">100% AMAN & RESMI</span>
            </div>
        </div>

        {{-- 5. ZONE PROMO / QRIS / CALL TO ACTION (RIGHT) --}}
        <div class="zone-promo-action">
            <p class="promo-tag-top">KODE PROMO SPESIAL</p>
            <div class="promo-big-code">CAPTSHOP</div>
            <div class="payment-icons-row">
                <span class="pay-badge">QRIS</span>
                <span class="pay-badge">DANA</span>
                <span class="pay-badge">GOPAY</span>
                <span class="pay-badge">SHOPEE</span>
            </div>
            <p class="live-timer-row" id="clock-display">--:--:-- WIB</p>
        </div>

    </div>

    <script>
        // Update live clock
        function updateClock() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const wib = new Date(utc + (3600000 * 7));
            const hours = String(wib.getHours()).padStart(2, '0');
            const minutes = String(wib.getMinutes()).padStart(2, '0');
            const seconds = String(wib.getSeconds()).padStart(2, '0');
            document.getElementById('clock-display').innerText = `${hours}:${minutes}:${seconds} WIB`;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Parameter Kustomisasi URL (?name=TAKAAA&goal=DONASI&alert=...)
        const params = new URLSearchParams(window.location.search);
        if (params.get('name')) document.getElementById('streamer-ign').innerText = params.get('name');
        if (params.get('goal')) document.getElementById('goal-title').innerText = params.get('goal');
        if (params.get('bg') === '1' || params.get('preview') === '1') document.body.classList.add('preview-bg');
    </script>
</body>
</html>
