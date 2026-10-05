<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sedang Pemeliharaan — {{ \App\Models\SiteSetting::get('site_name') ?? 'CaptShop' }}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background-color: #0f172a;
            color: #f1f5f9;
            font-family: 'Segoe UI', Arial, sans-serif;
            text-align: center;
            padding: 24px;
        }
        .icon { font-size: 56px; }
        h1 { font-size: 24px; margin: 16px 0 8px; }
        p { color: #94a3b8; line-height: 1.6; margin: 0; }
        .status {
            margin-top: 24px;
            font-size: 13px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <main>
        <div class="icon">🛠️</div>
        <h1>Situs Sedang Pemeliharaan</h1>
        <p>Kami sedang melakukan pemeliharaan untuk meningkatkan layanan.<br>Silakan kembali beberapa saat lagi.</p>
        <p class="status">Status kode: 503 — Maintenance Mode</p>
    </main>
</body>
</html>
