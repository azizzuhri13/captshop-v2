@props(['channels', 'selected' => null])

@php
    $groupIcons = [
        'Virtual Account' => '💳',
        'E-Wallet' => '📱',
        'Retail' => '🏪',
        'QRIS' => '💰',
    ];
@endphp

<div class="space-y-4">
    @foreach ($channels as $group => $groupChannels)
        <fieldset>
            <legend class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-300">
                <span>{{ $groupIcons[$group] ?? '💳' }}</span> {{ $group }}
            </legend>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($groupChannels as $channel)
                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="payment_channel"
                            value="{{ $channel->code }}"
                            x-model="paymentChannel"
                            class="peer sr-only"
                            @change="$root.dispatchEvent(new CustomEvent('channel-selected', { detail: $event.target.value }))"
                        >
                        <span class="flex items-center justify-between gap-2 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-slate-200 transition peer-checked:border-primary peer-checked:bg-primary/15 peer-checked:text-white hover:border-slate-600">
                            <span class="truncate">{{ $channel->name }}</span>
                            <span class="grid h-4 w-4 shrink-0 place-items-center rounded-full border border-slate-600 peer-checked:border-primary" aria-hidden="true"></span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endforeach
</div>
