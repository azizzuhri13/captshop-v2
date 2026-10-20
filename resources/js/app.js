import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Component: carousel banner promo (autoplay + swipe manual).
Alpine.data('carousel', (slideCount, interval = 5000) => ({
    current: 0,
    slideCount,
    timer: null,

    init() {
        this.start();
    },

    start() {
        this.timer = setInterval(() => this.next(), interval);
    },

    stop() {
        clearInterval(this.timer);
    },

    next() {
        this.current = (this.current + 1) % this.slideCount;
    },

    prev() {
        this.current = (this.current - 1 + this.slideCount) % this.slideCount;
    },

    goTo(index) {
        this.current = index;
    },
}));

// Component: form order single-page checkout (step 1-4 + ringkasan + kupon).
Alpine.data('orderForm', (config) => ({
    items: config.items || [],
    fields: config.fields || [],
    channels: config.channels || [],
    // Nilai dari submit sebelumnya (old()) dipulihkan agar user tidak mengisi ulang.
    itemId: config.itemId != null ? Number(config.itemId) : null,
    paymentChannel: config.paymentChannel || null,
    customerData: config.customerData || {},
    form: {
        customer_phone: config.customer_phone || '',
        customer_email: config.customer_email || '',
    },
    couponCheckUrl: config.couponCheckUrl || '',
    couponCode: config.couponCode || '',
    coupon: null,
    couponChecking: false,
    couponError: '',
    processing: false,
    isGameAccount: Boolean(config.isGameAccount),
    gameSlug: config.gameSlug || '',
    checkNicknameUrl: config.checkNicknameUrl || '',
    nickname: '',
    nicknameChecking: false,
    nicknameError: '',
    nicknameDebounceTimer: null,

    init() {
        // Saat nominal diganti, kupon harus divalidasi ulang (diskon dan
        // syarat min purchase bisa berubah mengikuti harga item).
        this.$watch('itemId', () => {
            if (this.coupon) {
                this.applyCoupon();
            }
        });

        if (config.isGameAccount && config.checkNicknameUrl) {
            this.$watch('customerData', () => this.debouncedCheckNickname(), { deep: true });
        }

        // Kupon dari submit sebelumnya divalidasi ulang ke server: harga/diskon
        // yang tampil harus sama dengan yang nanti ditagih.
        if (this.couponCode && this.selectedItem) {
            this.applyCoupon();
        }
    },

    get selectedItem() {
        return this.items.find((item) => item.id === this.itemId) || null;
    },

    /**
     * Item dikelompokkan jadi sub-bagian (mis. "Special Items"). Item tanpa
     * grup masuk ke bagian pertama tanpa judul. Bila tak ada satu pun item
     * bergrup, hasilnya satu grup tanpa judul — sama seperti daftar datar.
     */
    get itemGroups() {
        const groups = [];
        const index = new Map();

        for (const item of this.items) {
            const name = (item.group || '').trim();

            if (! index.has(name)) {
                index.set(name, groups.length);
                groups.push({ name, items: [] });
            }

            groups[index.get(name)].items.push(item);
        }

        // Bagian tanpa judul ke paling atas, sisanya ikut urutan item.
        const ungrouped = groups.findIndex((g) => g.name === '');

        if (ungrouped > 0) {
            groups.unshift(groups.splice(ungrouped, 1)[0]);
        }

        return groups;
    },

    get selectedChannel() {
        return this.channels.find((channel) => channel.code === this.paymentChannel) || null;
    },

    get price() {
        return this.selectedItem ? Number(this.selectedItem.price) : 0;
    },

    get discount() {
        return this.coupon ? Number(this.coupon.discount) : 0;
    },

    get fee() {
        if (! this.selectedChannel || ! this.selectedItem) {
            return 0;
        }

        const base = this.price - this.discount;

        return Math.round(
            Number(this.selectedChannel.flat_fee) +
            (base * Number(this.selectedChannel.percent_fee)) / 100
        );
    },

    get total() {
        return this.price - this.discount + this.fee;
    },

    get canSubmit() {
        if (this.selectedItem && this.selectedItem.out_of_stock) {
            return false;
        }
        return Boolean(this.itemId && this.paymentChannel && this.form.customer_phone);
    },

    /**
     * Daftar isian yang belum lengkap — dipakai sebagai petunjuk di atas tombol beli.
     */
    get missingHint() {
        if (this.selectedItem && this.selectedItem.out_of_stock) {
            return 'nominal yang masih tersedia (stok paket ini habis)';
        }

        const missing = [];

        if (! this.itemId) {
            missing.push('nominal');
        }

        if (! this.paymentChannel) {
            missing.push('metode pembayaran');
        }

        if (! this.form.customer_phone) {
            missing.push('No. WhatsApp');
        }

        return missing.join(' / ');
    },

    async applyCoupon() {
        if (! this.couponCode || ! this.selectedItem || this.couponChecking) {
            return;
        }

        this.couponChecking = true;
        this.couponError = '';

        try {
            const response = await fetch(this.couponCheckUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ code: this.couponCode, amount: this.price }),
            });

            const data = await response.json();

            if (data.valid) {
                this.coupon = data;
                this.couponCode = data.code;
            } else {
                this.coupon = null;
                this.couponError = data.message || 'Kode kupon tidak valid.';
            }
        } catch {
            this.couponError = 'Gagal memeriksa kupon. Silakan coba lagi.';
        } finally {
            this.couponChecking = false;
        }
    },

    removeCoupon() {
        this.coupon = null;
        this.couponError = '';
    },

    formatRupiah(value) {
        return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
    },

    debouncedCheckNickname() {
        const uid = (this.customerData.user_id || '').trim();
        const zid = (this.customerData.zone_id || '').trim();
        const needsZone = (config.gameSlug || '').includes('legend') || (config.gameSlug || '') === 'mlbb' || (config.gameSlug || '').includes('chess') || (config.gameSlug || '') === 'mcgg';

        if (! uid || (needsZone && ! zid)) {
            this.nickname = '';
            this.nicknameError = '';
            return;
        }

        clearTimeout(this.nicknameDebounceTimer);
        this.nicknameDebounceTimer = setTimeout(() => this.checkNickname(uid, zid), 500);
    },

    async checkNickname(userId, zoneId) {
        this.nicknameChecking = true;
        this.nicknameError = '';

        try {
            const res = await fetch(config.checkNicknameUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    game_code: config.gameSlug,
                    user_id: userId,
                    zone_id: zoneId || null,
                }),
            });

            const data = await res.json();
            if (data.valid && data.nickname) {
                this.nickname = data.nickname;
                this.nicknameError = '';
            } else {
                this.nickname = '';
                this.nicknameError = data.message || 'ID Akun tidak ditemukan.';
            }
        } catch {
            this.nickname = '';
            this.nicknameError = 'Gagal memverifikasi ID akun.';
        } finally {
            this.nicknameChecking = false;
        }
    },
}));

Alpine.start();
