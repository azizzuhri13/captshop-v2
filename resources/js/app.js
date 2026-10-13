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
    itemId: null,
    paymentChannel: null,
    customerData: {},
    form: {
        customer_phone: '',
        customer_email: '',
    },
    fields: config.fields || [],
    items: config.items || [],
    channels: config.channels || [],
    couponCheckUrl: config.couponCheckUrl || '',
    couponCode: '',
    coupon: null,
    couponChecking: false,
    couponError: '',
    processing: false,

    // Game validation state
    gameSlug: config.gameSlug || '',
    checkNicknameUrl: config.checkNicknameUrl || '',
    isGameAccount: Boolean(config.isGameAccount),
    nickname: null,
    nicknameChecking: false,
    nicknameError: '',
    nicknameValidated: false,
    _debounceTimer: null,

    init() {
        // Saat nominal diganti, kupon harus divalidasi ulang (diskon dan
        // syarat min purchase bisa berubah mengikuti harga item).
        this.$watch('itemId', () => {
            if (this.coupon) {
                this.applyCoupon();
            }
        });

        // Watch customerData untuk cek nickname otomatis
        if (this.isGameAccount) {
            this.$watch('customerData', () => {
                clearTimeout(this._debounceTimer);
                this._debounceTimer = setTimeout(() => {
                    this.validateNickname();
                }, 400);
            }, { deep: true });
        }
    },

    async validateNickname() {
        const userId = (this.customerData['user_id'] || '').trim();
        const zoneId = (this.customerData['zone_id'] || '').trim();

        if (! userId) {
            this.nickname = null;
            this.nicknameError = '';
            this.nicknameValidated = false;
            return;
        }

        if (this.gameSlug.includes('mobile-legend') && ! zoneId) {
            this.nickname = null;
            this.nicknameError = '';
            this.nicknameValidated = false;
            return;
        }

        this.nicknameChecking = true;
        this.nicknameError = '';

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const response = await fetch(this.checkNicknameUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    game_code: this.gameSlug,
                    user_id: userId,
                    zone_id: zoneId || null,
                }),
            });

            const data = await response.json();

            if (data.valid && data.nickname) {
                this.nickname = data.nickname;
                this.nicknameError = '';
                this.nicknameValidated = true;
            } else {
                this.nickname = null;
                this.nicknameError = data.message || 'ID tidak ditemukan. Periksa kembali User ID & Zone ID.';
                this.nicknameValidated = false;
            }
        } catch {
            this.nickname = null;
            this.nicknameError = 'Gagal memverifikasi ID. Silakan coba lagi.';
            this.nicknameValidated = false;
        } finally {
            this.nicknameChecking = false;
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
        if (this.isGameAccount && ! this.nicknameValidated) {
            return false;
        }

        return Boolean(this.itemId && this.paymentChannel && this.form.customer_phone);
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
}));

Alpine.start();
