<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1080, height=1920">
    <title>CAPTSHOP — TikTok Live Vertical Stream Overlay (9:16)</title>
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
            --dark-bg: rgba(10, 15, 29, 0.95);
            --border-red: rgba(217, 37, 37, 0.5);
        }

        body {
            width: 1080px;
            height: 1920px;
            overflow: hidden;
            background: transparent;
            font-family: 'Poppins', sans-serif;
            color: #FFFFFF;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        body.preview-bg {
            background: radial-gradient(circle at center, #1E293B 0%, #090D18 100%);
        }

        /* ================= 1. TOP SECTION (DI ATAS GAMEPLAY) ================= */
        .top-section {
            width: 100%;
            height: 640px;
            padding: 90px 40px 10px 40px; /* Margin atas 90px agar aman dari tombol profil/close TikTok */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 20;
        }

        .header-sponsor {
            background: var(--dark-bg);
            border: 2px solid var(--border-red);
            border-radius: 20px;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.7), 0 0 20px rgba(217, 37, 37, 0.3);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-logo {
            width: 54px;
            height: 54px;
            object-fit: contain;
            filter: drop-shadow(0 0 10px var(--primary-glow));
        }

        .header-title h1 {
            font-size: 26px;
            font-weight: 900;
            line-height: 1.1;
            background: linear-gradient(135deg, #FFFFFF 0%, #FECACA 50%, #EF4444 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-title p {
            font-size: 12px;
            font-weight: 800;
            color: var(--accent-gold);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .header-pill {
            background: linear-gradient(135deg, #D92525 0%, #B91C1C 100%);
            color: #FFFFFF;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 14px rgba(217, 37, 37, 0.4);
        }

        /* Row Facecam & Target Donasi */
        .top-middle-row {
            display: flex;
            gap: 20px;
            align-items: center;
            height: 480px;
        }

        .vertical-facecam {
            width: 480px;
            height: 440px;
            background: rgba(15, 23, 42, 0.3);
            border: 3px solid var(--primary);
            border-radius: 20px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8), 0 0 25px rgba(217, 37, 37, 0.35);
        }

        .vertical-facecam::before {
            content: '';
            position: absolute;
            top: -4px; left: -4px;
            width: 24px; height: 24px;
            border-top: 4px solid var(--primary-light);
            border-left: 4px solid var(--primary-light);
            border-radius: 6px 0 0 0;
        }

        .vertical-facecam::after {
            content: '';
            position: absolute;
            bottom: -4px; right: -4px;
            width: 24px; height: 24px;
            border-bottom: 4px solid var(--primary-light);
            border-right: 4px solid var(--primary-light);
            border-radius: 0 0 6px 0;
        }

        .vcam-badge {
            position: absolute;
            top: 12px;
            left: 14px;
            background: #D92525;
            color: #FFFFFF;
            font-size: 11px;
            font-weight: 900;
            padding: 4px 12px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.5);
        }

        .vcam-name {
            position: absolute;
            bottom: 12px;
            left: 14px;
            right: 14px;
            background: rgba(10, 15, 29, 0.9);
            border: 1px solid var(--border-red);
            border-radius: 8px;
            padding: 8px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 800;
        }

        .top-side-panel {
            flex: 1;
            height: 440px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .v-goal-card {
            background: var(--dark-bg);
            border: 1.5px solid rgba(245, 158, 11, 0.5);
            border-radius: 16px;
            padding: 16px 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.5);
        }

        .v-goal-title {
            font-size: 13px;
            font-weight: 800;
            color: var(--accent-gold);
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .v-goal-bar {
            width: 100%;
            height: 14px;
            background: #1E293B;
            border-radius: 7px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .v-goal-fill {
            height: 100%;
            width: 45%;
            background: linear-gradient(90deg, #F59E0B, #EF4444);
            border-radius: 7px;
        }

        .v-goal-val {
            font-size: 12px;
            font-weight: 700;
            color: #CBD5E1;
            margin-top: 6px;
            text-align: right;
        }

        .v-menu-jasa {
            background: var(--dark-bg);
            border: 1.5px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.5);
        }

        .v-menu-title {
            font-size: 13px;
            font-weight: 900;
            color: #F87171;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 6px;
        }

        .v-menu-item {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 700;
            color: #E2E8F0;
        }

        .v-menu-item strong {
            color: var(--accent-gold);
        }

        /* ================= 2. CENTER SECTION (AREA GAMEPLAY MLBB) ================= */
        .center-gameplay-slot {
            width: 1080px;
            height: 608px; /* Standar 16:9 pas di lebar 1080 */
            position: relative;
            background: transparent;
            border-top: 2px solid var(--border-red);
            border-bottom: 2px solid var(--border-red);
        }

        /* ================= 3. BOTTOM SECTION (DI BAWAH GAMEPLAY) ================= */
        .bottom-section {
            width: 100%;
            height: 672px;
            padding: 20px 40px 0 40px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            z-index: 20;
        }

        .tiktok-chat-safe-zone {
            flex: 1;
            min-height: 380px;
            background: transparent;
            /* Area transparan khusus tempat chat penonton & floating gift TikTok berputar */
        }

        .bottom-sponsor-card {
            background: var(--dark-bg);
            border: 2px solid var(--primary);
            border-radius: 20px;
            padding: 20px 24px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.7), 0 0 20px rgba(217, 37, 37, 0.3);
        }

        .card-row-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .pricelist-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 14px 0;
        }

        .price-box {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 10px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .price-box span {
            font-size: 13px;
            font-weight: 700;
            color: #94A3B8;
        }

        .price-box strong {
            font-size: 15px;
            font-weight: 900;
            color: #38BDF8;
        }

        .bottom-promo-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 14px;
            font-size: 13px;
            font-weight: 800;
        }

        .promo-pill-code {
            background: rgba(245, 158, 11, 0.15);
            border: 1px dashed var(--accent-gold);
            color: var(--accent-gold);
            padding: 6px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .payment-pill-row {
            display: flex;
            gap: 8px;
        }

        .pay-tag {
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            color: #E2E8F0;
        }
    </style>
</head>
<body id="v-body">

    {{-- TOP SECTION (DI ATAS GAMEPLAY) --}}
    <div class="top-section">
        {{-- Header Sponsor --}}
        <div class="header-sponsor">
            <div class="header-left">
                <img src="/images/captshop-logo.png" alt="CAPTSHOP" class="header-logo">
                <div class="header-title">
                    <h1>CAPTSHOP.ID</h1>
                    <p>PUSAT TOP UP GAME TERMURAH</p>
                </div>
            </div>
            <div class="header-pill">⚡ PROSES 1 DETIK</div>
        </div>

        {{-- Row Facecam & Target Donasi --}}
        <div class="top-middle-row">
            <div class="vertical-facecam">
                <div class="vcam-badge">● LIVE</div>
                <div class="vcam-name">
                    <span>★ STREAMER</span>
                    <span style="color:#F87171;">@captshop.id</span>
                </div>
            </div>

            <div class="top-side-panel">
                <div class="v-goal-card">
                    <div class="v-goal-title">
                        <span>TARGET BULAN INI</span>
                        <span style="color:#FFF;">45%</span>
                    </div>
                    <div class="v-goal-bar">
                        <div class="v-goal-fill"></div>
                    </div>
                    <p class="v-goal-val">Rp 450.000 / Rp 1.000.000</p>
                </div>

                <div class="v-menu-jasa">
                    <div class="v-menu-title">📋 MENU STREAM</div>
                    <div class="v-menu-item"><span>KOMEN ALBUM:</span><strong>10K</strong></div>
                    <div class="v-menu-item"><span>MABAR 1 MATCH:</span><strong>20K</strong></div>
                    <div class="v-menu-item"><span>GIFT SKIN:</span><strong>TOPUP</strong></div>
                </div>
            </div>
        </div>
    </div>

    {{-- CENTER SECTION (SLOT TRANSPARAN UNTUK GAMEPLAY MLBB 16:9) --}}
    <div class="center-gameplay-slot"></div>

    {{-- BOTTOM SECTION (DI BAWAH GAMEPLAY) --}}
    <div class="bottom-section">
        <div class="bottom-sponsor-card">
            <div class="card-row-top">
                <span style="font-size: 15px; font-weight: 900; color: #FFFFFF;">🔥 HARGA SPESIAL MLBB</span>
                <span style="font-size: 12px; font-weight: 800; color: #22C55E;">● 100% AMAN & RESMI</span>
            </div>

            <div class="pricelist-grid">
                <div class="price-box">
                    <span>Weekly Pass:</span>
                    <strong>Rp 32.150</strong>
                </div>
                <div class="price-box">
                    <span>86 Diamonds:</span>
                    <strong>Rp 15.000</strong>
                </div>
                <div class="price-box">
                    <span>Starlight Member:</span>
                    <strong>Rp 129.000</strong>
                </div>
                <div class="price-box">
                    <span>172 Diamonds:</span>
                    <strong>Rp 30.000</strong>
                </div>
            </div>

            <div class="bottom-promo-strip">
                <div>
                    <span style="font-size: 11px; color:#94A3B8; display:block;">KODE PROMO:</span>
                    <span class="promo-pill-code">CAPTSHOP</span>
                </div>
                <div class="payment-pill-row">
                    <span class="pay-tag">QRIS</span>
                    <span class="pay-tag">DANA</span>
                    <span class="pay-tag">GOPAY</span>
                    <span class="pay-tag">SHOPEE</span>
                </div>
            </div>
        </div>

        {{-- SAFE ZONE TRANSPARAN KHUSUS LIVE CHAT & GIFT TIKTOK --}}
        <div class="tiktok-chat-safe-zone"></div>
    </div>

    <script>
        const params = new URLSearchParams(window.location.search);
        if (params.get('bg') === '1' || params.get('preview') === '1') {
            document.body.classList.add('preview-bg');
        }
    </script>
</body>
</html>
