@extends('layouts.app')

@section('title', 'Kalkulator Winrate MLBB & Magic Chess — Hitung Target WR | CAPTSHOP')
@section('meta_description', 'Hitung berapa banyak match menang beruntun (win streak) yang dibutuhkan untuk mencapai target winrate Mobile Legends & Magic Chess secara akurat dan gratis di CAPTSHOP.')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
    {{-- Header --}}
    <div class="mb-8 text-center">
        <span class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary-light">
            🎮 Tools Gratis Gamer
        </span>
        <h1 class="mt-3 font-heading text-2xl font-black text-slate-100 sm:text-3xl">
            Kalkulator Winrate MLBB & Magic Chess
        </h1>
        <p class="mt-2 text-sm text-slate-400">
            Ketahui jumlah kemenangan beruntun (win streak) yang kamu butuhkan untuk mencapai persentase winrate impianmu.
        </p>
    </div>

    {{-- Calculator Card (Pure Alpine.js) --}}
    <div x-data="{
        totalMatch: 450,
        currentWr: 53.5,
        targetWr: 65,
        get totalWins() {
            return Math.round((this.totalMatch * this.currentWr) / 100);
        },
        get neededWins() {
            if (this.targetWr <= this.currentWr || this.targetWr >= 100) return 0;
            return Math.ceil(((this.targetWr / 100) * this.totalMatch - this.totalWins) / (1 - (this.targetWr / 100)));
        }
    }" class="rounded-3xl border border-slate-800 bg-slate-900/60 p-6 shadow-2xl backdrop-blur sm:p-8">
        
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
            {{-- Input 1: Total Match --}}
            <div>
                <label class="block text-xs font-semibold text-slate-300">
                    Total Match Saat Ini
                </label>
                <div class="mt-2">
                    <input type="number" min="1" x-model.number="totalMatch"
                           class="w-full rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-3 text-sm font-semibold text-white focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                           placeholder="Contoh: 500">
                </div>
                <p class="mt-1 text-[11px] text-slate-500">Total match di profil game</p>
            </div>

            {{-- Input 2: Current WR --}}
            <div>
                <label class="block text-xs font-semibold text-slate-300">
                    Total Winrate (%)
                </label>
                <div class="mt-2">
                    <input type="number" step="0.1" min="0" max="100" x-model.number="currentWr"
                           class="w-full rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-3 text-sm font-semibold text-white focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                           placeholder="Contoh: 52.5">
                </div>
                <p class="mt-1 text-[11px] text-slate-500">Winrate akun saat ini</p>
            </div>

            {{-- Input 3: Target WR --}}
            <div>
                <label class="block text-xs font-semibold text-slate-300">
                    Target Winrate (%)
                </label>
                <div class="mt-2">
                    <input type="number" step="0.1" min="1" max="99.9" x-model.number="targetWr"
                           class="w-full rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-3 text-sm font-semibold text-white focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                           placeholder="Contoh: 65">
                </div>
                <p class="mt-1 text-[11px] text-slate-500">Persentase yang ditargetkan</p>
            </div>
        </div>

        {{-- Result Banner --}}
        <div class="mt-8 rounded-2xl border border-primary/40 bg-gradient-to-br from-primary/20 via-slate-900 to-slate-900 p-6 text-center">
            <template x-if="targetWr > currentWr && targetWr < 100">
                <div>
                    <p class="text-xs font-medium text-slate-300">
                        Kamu membutuhkan kemenangan beruntun tanpa kalah sebanyak:
                    </p>
                    <div class="my-3 font-heading text-4xl font-black text-primary-light sm:text-5xl">
                        <span x-text="neededWins"></span> <span class="text-2xl sm:text-3xl font-bold text-white">Match Win</span>
                    </div>
                    <p class="text-xs text-slate-400">
                        Total kemenangan akan menjadi <span class="font-bold text-slate-200" x-text="totalWins + neededWins"></span> dari <span class="font-bold text-slate-200" x-text="totalMatch + neededWins"></span> pertandingan.
                    </p>
                </div>
            </template>

            <template x-if="targetWr <= currentWr">
                <div class="py-2">
                    <div class="text-3xl font-bold text-emerald-400">🎉 Target Sudah Tercapai!</div>
                    <p class="mt-2 text-xs text-slate-300">Target winrate kamu sudah sama atau lebih rendah dari winrate saat ini. Pertahankan performamu!</p>
                </div>
            </template>

            <template x-if="targetWr >= 100">
                <div class="py-2">
                    <div class="text-2xl font-bold text-amber-400">Target 100% Tidak Memungkinkan</div>
                    <p class="mt-2 text-xs text-slate-300">Jika sudah pernah kalah setidaknya 1 match, winrate secara matematis tidak dapat mencapai 100% mutlak.</p>
                </div>
            </template>
        </div>

        {{-- CTA Topup --}}
        <div class="mt-8 flex flex-col items-center justify-between gap-4 rounded-2xl border border-slate-800 bg-slate-800/40 p-4 sm:flex-row">
            <div>
                <p class="text-sm font-bold text-slate-100">Mau main makin pede dengan skin favorit?</p>
                <p class="text-xs text-slate-400">Top up Diamond Mobile Legends & MCGG proses instan 24 jam di CAPTSHOP.</p>
            </div>
            <div class="flex gap-2">
                <a href="/game/mobile-legends" class="btn-primary !py-2 !px-4 text-xs font-semibold">
                    Top Up MLBB ➔
                </a>
                <a href="/topup/magic-chess-go-go" class="rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-xs font-semibold text-slate-200 hover:border-slate-500 hover:text-white">
                    MCGG ➔
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
