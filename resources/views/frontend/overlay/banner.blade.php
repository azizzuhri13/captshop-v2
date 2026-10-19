<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1920, height=1080">
    <title>CAPTSHOP — Full Width Mecha Stream Banner</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:800,900|rajdhani:700,800,900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            width: 1920px;
            height: 1080px;
            overflow: hidden;
            background: transparent;
            font-family: 'Poppins', sans-serif;
            position: relative;
        }
        body.preview-bg {
            background: #0B0F19;
        }

        /* Banner Container: Membentang penuh di bawah layar tanpa ruang kosong */
        .banner-wrapper {
            position: absolute;
            bottom: 20px;
            left: 30px;
            right: 30px;
            height: 150px;
            display: flex;
            align-items: center;
        }

        /* 1. SISI KIRI: PERISAI LOGO HEKSAGONAL 3D MECHA */
        .shield-badge {
            width: 160px;
            height: 150px;
            background: linear-gradient(145deg, #182030 0%, #0d121c 100%);
            border: 4px solid #FF2A2A;
            clip-path: polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 35px rgba(255, 42, 42, 0.75), inset 0 0 25px rgba(0, 0, 0, 0.9);
            flex-shrink: 0;
            z-index: 10;
            position: relative;
            margin-right: -20px;
        }

        .shield-logo {
            width: 74px;
            height: 74px;
            object-fit: contain;
            filter: drop-shadow(0 0 14px rgba(255, 42, 42, 0.95));
            animation: pulseGlow 2.5s infinite alternate;
        }

        .shield-label {
            font-size: 13px;
            font-weight: 900;
            letter-spacing: 2.5px;
            color: #FFFFFF;
            text-transform: uppercase;
            margin-top: 4px;
            text-shadow: 0 0 10px rgba(255, 42, 42, 0.85);
        }

        /* 2. BILAH UTAMA (MECHA BAR): MENGISI PANJANG PENUH KIRI KE KANAN */
        .mecha-bar {
            flex: 1;
            height: 124px;
            background: linear-gradient(90deg, #141b27 0%, #0f1622 50%, #171115 100%);
            border-top: 4px solid #FF2A2A;
            border-bottom: 4px solid #FF2A2A;
            box-shadow: 0 0 40px rgba(255, 42, 42, 0.6), inset 0 0 40px rgba(0, 0, 0, 0.85);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px 0 35px;
            position: relative;
            clip-path: polygon(0 0, calc(100% - 35px) 0, 100% 50%, calc(100% - 35px) 100%, 0 100%);
        }

        /* Rel neon sci-fi atas dan bawah */
        .mecha-bar::before {
            content: '';
            position: absolute;
            top: -2.5px; left: 20px; right: 50px;
            height: 4px;
            background: #FFFFFF;
            box-shadow: 0 0 18px #FF4D4D, 0 0 35px #FF2A2A;
            animation: railLight 2s infinite alternate;
        }

        /* Celah ventilasi miring (micro-vents) */
        .vents-top, .vents-bottom {
            position: absolute;
            display: flex;
            gap: 8px;
        }
        .vents-top { top: 7px; left: 50%; transform: translateX(-50%); }
        .vents-bottom { bottom: 7px; left: 50%; transform: translateX(-50%); }
        .vent-slit {
            width: 16px;
            height: 3.5px;
            background: #FF3B3B;
            transform: skewX(-35deg);
            box-shadow: 0 0 8px #FF3B3B;
        }

        /* 3. DUA BLOK TEKS: FONT BESAR MASIF MEMENUHI SELURUH KOMPARTEMEN */
        .text-zone-left {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
        }

        .text-zone-right {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
        }

        .banner-text-item {
            font-size: 64px;
            font-weight: 900;
            font-style: italic;
            letter-spacing: 4px;
            color: #FFFFFF;
            text-transform: lowercase;
            text-shadow: 0 0 16px rgba(255, 255, 255, 0.8), 0 0 30px rgba(255, 42, 42, 0.65);
            white-space: nowrap;
            transition: opacity 0.35s ease, transform 0.35s ease;
        }

        /* 4. PEMISAH DIAGONAL MERAH MENYALA DI TENGAH (TIGA GARIS ///) */
        .center-slash-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-shrink: 0;
            padding: 0 15px;
        }

        .slash-bar {
            width: 7px;
            height: 82px;
            background: linear-gradient(180deg, #FFFFFF 0%, #FF2A2A 50%, #FF0000 100%);
            transform: skewX(-30deg);
            box-shadow: 0 0 18px #FF2A2A, 0 0 35px #FF0000;
            border-radius: 2px;
            animation: pulseSlash 1.8s infinite alternate;
        }
        .slash-bar:nth-child(2) { opacity: 0.85; height: 74px; }
        .slash-bar:nth-child(3) { opacity: 0.7; height: 66px; }

        /* 5. UJUNG KANAN: CHEVRON ARROW TRIPLE >>> */
        .chevron-wing {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 10px;
            flex-shrink: 0;
        }
        .chevron-arrow {
            width: 20px;
            height: 20px;
            border-top: 5px solid #FF2A2A;
            border-right: 5px solid #FF2A2A;
            transform: rotate(45deg);
            filter: drop-shadow(0 0 10px #FF2A2A);
        }
        .chevron-arrow:nth-child(2) {
            margin-left: -8px;
            opacity: 0.85;
        }
        .chevron-arrow:nth-child(3) {
            margin-left: -8px;
            opacity: 0.65;
        }

        /* Swap animation */
        .swap-out {
            opacity: 0;
            transform: scale(0.94) translateY(-6px);
        }
        .swap-in {
            opacity: 1;
            transform: scale(1) translateY(0);
        }

        @keyframes pulseGlow {
            0% { filter: drop-shadow(0 0 8px rgba(255, 42, 42, 0.7)); }
            100% { filter: drop-shadow(0 0 20px rgba(255, 77, 77, 1)); }
        }
        @keyframes railLight {
            0% { opacity: 0.65; }
            100% { opacity: 1; }
        }
        @keyframes pulseSlash {
            0% { filter: drop-shadow(0 0 10px #FF2A2A); }
            100% { filter: drop-shadow(0 0 25px #FF4D4D); }
        }
    </style>
</head>
<body>

    {{-- FULL WIDTH MECHA STREAM BANNER --}}
    <div class="banner-wrapper">

        {{-- 1. PERISAI LOGO HEKSAGONAL 3D --}}
        <div class="shield-badge">
            <img src="/images/captshop-logo.png" alt="CAPTSHOP" class="shield-logo">
            <span class="shield-label">CAPTSHOP</span>
        </div>

        {{-- 2. BILAH UTAMA MECHA --}}
        <div class="mecha-bar">
            {{-- Micro Vents --}}
            <div class="vents-top">
                <div class="vent-slit"></div>
                <div class="vent-slit"></div>
                <div class="vent-slit"></div>
                <div class="vent-slit"></div>
            </div>
            <div class="vents-bottom">
                <div class="vent-slit"></div>
                <div class="vent-slit"></div>
                <div class="vent-slit"></div>
                <div class="vent-slit"></div>
            </div>

            {{-- 3. TEKS KIRI (MEMBENTANG PENUH) --}}
            <div class="text-zone-left">
                <div id="txt-left" class="banner-text-item swap-in">captshop</div>
            </div>

            {{-- 4. GARIS PEMISAH DIAGONAL MERAH MENYALA (TIGA ///) --}}
            <div class="center-slash-divider">
                <div class="slash-bar"></div>
                <div class="slash-bar"></div>
                <div class="slash-bar"></div>
            </div>

            {{-- 5. TEKS KANAN (MEMBENTANG PENUH) --}}
            <div class="text-zone-right">
                <div id="txt-right" class="banner-text-item swap-in">captshop.id</div>
            </div>

            {{-- 6. UJUNG CHEVRON MECHA KANAN (TRIPLE >>>) --}}
            <div class="chevron-wing">
                <div class="chevron-arrow"></div>
                <div class="chevron-arrow"></div>
                <div class="chevron-arrow"></div>
            </div>
        </div>

    </div>

    <script>
        // Daftar kalimat berpasangan yang mengisi penuh kiri & kanan
        const pairs = [
            { left: "captshop", right: "captshop.id" },
            { left: "available 24/7", right: "proses 1 detik" },
            { left: "game favoritmu", right: "ada di captshop" },
            { left: "mlbb & free fire", right: "top up termurah" },
            { left: "tiktok resmi", right: "@captshop.id" }
        ];

        let idx = 0;
        const leftEl = document.getElementById('txt-left');
        const rightEl = document.getElementById('txt-right');

        setInterval(() => {
            leftEl.classList.remove('swap-in');
            rightEl.classList.remove('swap-in');
            leftEl.classList.add('swap-out');
            rightEl.classList.add('swap-out');

            setTimeout(() => {
                idx = (idx + 1) % pairs.length;
                leftEl.innerText = pairs[idx].left;
                rightEl.innerText = pairs[idx].right;

                leftEl.classList.remove('swap-out');
                rightEl.classList.remove('swap-out');
                leftEl.classList.add('swap-in');
                rightEl.classList.add('swap-in');
            }, 350);
        }, 3400);

        if (new URLSearchParams(window.location.search).get('bg') === '1') {
            document.body.classList.add('preview-bg');
        }
    </script>
</body>
</html>
