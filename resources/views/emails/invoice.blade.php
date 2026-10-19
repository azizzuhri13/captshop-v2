<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $transaction->invoice_id }}</title>
</head>
<body style="margin:0;padding:0;background-color:#0f172a;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0f172a;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;">

                    {{-- Header --}}
                    <tr>
                        <td style="background-color:#D92525;padding:24px 32px;">
                            <p style="margin:0;font-size:20px;font-weight:bold;color:#ffffff;">{{ $siteName }}</p>
                            <p style="margin:4px 0 0;font-size:13px;color:#e9e2fa;">Invoice {{ $transaction->invoice_id }}</p>
                        </td>
                    </tr>

                    {{-- Status --}}
                    <tr>
                        <td style="padding:24px 32px 0;">
                            <p style="margin:0;font-size:16px;color:#0f172a;">Halo,</p>
                            <p style="margin:8px 0 0;font-size:14px;color:#475569;line-height:1.6;">
                                Pesanan Anda telah dibuat dan sedang <strong>menunggu pembayaran</strong>. Selesaikan pembayaran agar pesanan segera kami proses.
                            </p>
                        </td>
                    </tr>

                    {{-- Detail pesanan --}}
                    <tr>
                        <td style="padding:24px 32px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#475569;">Invoice</td>
                                    <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:bold;">{{ $transaction->invoice_id }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#475569;">Produk</td>
                                    <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;">{{ $transaction->productItem?->product?->name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#475569;">Item</td>
                                    <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;">{{ $transaction->productItem?->name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#475569;">Harga</td>
                                    <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;">Rp {{ number_format((float) $transaction->amount, 0, ',', '.') }}</td>
                                </tr>
                                @if ((float) $transaction->coupon_discount > 0)
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#059669;">Diskon ({{ $transaction->coupon_code }})</td>
                                        <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#059669;">-Rp {{ number_format((float) $transaction->coupon_discount, 0, ',', '.') }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#475569;">Biaya layanan ({{ $transaction->payment_method ?? '-' }})</td>
                                    <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;">Rp {{ number_format((float) $transaction->fee, 0, ',', '.') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 0;font-size:15px;color:#0f172a;font-weight:bold;">Total</td>
                                    <td align="right" style="padding:14px 0;font-size:18px;color:#D92525;font-weight:bold;">Rp {{ number_format((float) $transaction->total, 0, ',', '.') }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Batas waktu --}}
                    @if ($transaction->expired_at)
                        <tr>
                            <td style="padding:0 32px;">
                                <div style="background-color:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;">
                                    <p style="margin:0;font-size:13px;color:#92400e;">
                                        ⏰ Batas pembayaran: <strong>{{ $transaction->expired_at->format('d M Y H:i') }} WIB</strong>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endif

                    {{-- CTA --}}
                    <tr>
                        <td style="padding:24px 32px;" align="center">
                            <a href="{{ $transaction->payment_url ?: url('/invoice/'.$transaction->invoice_id) }}"
                               style="display:inline-block;background-color:#D92525;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:12px 32px;border-radius:8px;">
                                {{ $transaction->payment_url ? 'Bayar Sekarang' : 'Lihat Instruksi Pembayaran' }}
                            </a>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#f1f5f9;padding:16px 32px;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;font-size:12px;color:#64748b;line-height:1.6;">
                                Butuh bantuan? Cek status pesanan di <a href="{{ url('/cek-transaksi') }}" style="color:#D92525;">Halaman Cek Transaksi</a>.<br>
                                Email ini otomatis dikirim oleh sistem {{ $siteName }}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
