<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bukti Pembayaran {{ $transaction->invoice_id }}</title>
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
                            <p style="margin:4px 0 0;font-size:13px;color:#e9e2fa;">Bukti Pembayaran — {{ $transaction->invoice_id }}</p>
                        </td>
                    </tr>

                    {{-- Status banner --}}
                    <tr>
                        <td style="padding:24px 32px 0;">
                            @if ($transaction->status === 'completed')
                                <div style="background-color:#ecfdf5;border:1px solid #34d399;border-radius:8px;padding:14px 16px;text-align:center;">
                                    <p style="margin:0;font-size:15px;color:#065f46;font-weight:bold;">✅ Pesanan Berhasil Diproses</p>
                                </div>
                                <p style="margin:16px 0 0;font-size:14px;color:#475569;line-height:1.6;">
                                    Terima kasih! Pembayaran Anda telah kami terima dan pesanan <strong>{{ $transaction->invoice_id }}</strong> telah selesai diproses. Berikut bukti transaksi Anda:
                                </p>
                            @else
                                <div style="background-color:#eff6ff;border:1px solid #60a5fa;border-radius:8px;padding:14px 16px;text-align:center;">
                                    <p style="margin:0;font-size:15px;color:#1e40af;font-weight:bold;">💳 Pembayaran Diterima</p>
                                </div>
                                <p style="margin:16px 0 0;font-size:14px;color:#475569;line-height:1.6;">
                                    Pembayaran untuk pesanan <strong>{{ $transaction->invoice_id }}</strong> telah kami terima. Pesanan Anda sedang diproses dan akan segera selesai.
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Detail transaksi --}}
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
                                @if ($transaction->supplier_sn)
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#475569;">Nomor Seri (SN)</td>
                                        <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:bold;">{{ $transaction->supplier_sn }}</td>
                                    </tr>
                                @endif
                                @if ($transaction->paid_at)
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#475569;">Waktu Pembayaran</td>
                                        <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;">{{ $transaction->paid_at->format('d M Y H:i') }} WIB</td>
                                    </tr>
                                @endif
                                @if ((float) $transaction->coupon_discount > 0)
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#059669;">Diskon ({{ $transaction->coupon_code }})</td>
                                        <td align="right" style="padding:10px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#059669;">-Rp {{ number_format((float) $transaction->coupon_discount, 0, ',', '.') }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:14px 0;font-size:15px;color:#0f172a;font-weight:bold;">Total Dibayar</td>
                                    <td align="right" style="padding:14px 0;font-size:18px;color:#D92525;font-weight:bold;">Rp {{ number_format((float) $transaction->total, 0, ',', '.') }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- CTA --}}
                    <tr>
                        <td style="padding:24px 32px;" align="center">
                            <a href="{{ url('/invoice/'.$transaction->invoice_id) }}"
                               style="display:inline-block;background-color:#D92525;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:12px 32px;border-radius:8px;">
                                Lihat Detail Pesanan
                            </a>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#f1f5f9;padding:16px 32px;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;font-size:12px;color:#64748b;line-height:1.6;">
                                Simpan email ini sebagai bukti transaksi Anda.<br>
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
