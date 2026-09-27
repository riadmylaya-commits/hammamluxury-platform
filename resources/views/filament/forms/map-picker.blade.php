<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$getStatePath()}')") }},
            map: null,
            marker: null,
            fallback: [{{ \App\Filament\Forms\Components\MapPicker::DEFAULT_LAT }}, {{ \App\Filament\Forms\Components\MapPicker::DEFAULT_LNG }}],
            has() { return this.state && this.state.lat !== null && this.state.lat !== undefined && this.state.lng !== null && this.state.lng !== undefined && this.state.lat !== '' },
            place(lat, lng) { this.state = { lat: Math.round(lat * 1e7) / 1e7, lng: Math.round(lng * 1e7) / 1e7 } },
            load() {
                if (window.L) return Promise.resolve();
                if (! window.__hlLeaflet) {
                    window.__hlLeaflet = new Promise((resolve) => {
                        const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(css);
                        const js = document.createElement('script'); js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; js.onload = resolve; document.head.appendChild(js);
                    });
                }
                return window.__hlLeaflet;
            },
            sync() {
                if (! this.map) return;
                if (this.has()) {
                    const ll = [parseFloat(this.state.lat), parseFloat(this.state.lng)];
                    if (! this.marker) {
                        this.marker = L.marker(ll, { draggable: true }).addTo(this.map);
                        this.marker.on('dragend', () => { const p = this.marker.getLatLng(); this.place(p.lat, p.lng) });
                    } else {
                        this.marker.setLatLng(ll);
                    }
                    this.map.setView(ll, Math.max(this.map.getZoom(), 15));
                } else if (this.marker) {
                    this.map.removeLayer(this.marker); this.marker = null;
                }
            },
            init() {
                this.load().then(() => {
                    this.map = L.map(this.$refs.map, { scrollWheelZoom: false }).setView(this.has() ? [parseFloat(this.state.lat), parseFloat(this.state.lng)] : this.fallback, this.has() ? 15 : 5);
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(this.map);
                    this.map.on('click', (e) => this.place(e.latlng.lat, e.latlng.lng));
                    this.sync();
                    this.$watch('state', () => this.sync());
                    setTimeout(() => this.map.invalidateSize(), 300);
                });
            },
        }"
        wire:ignore
        class="space-y-2"
    >
        <div x-ref="map" style="height: 260px; border-radius: 12px; z-index: 0" class="w-full overflow-hidden ring-1 ring-gray-950/10 dark:ring-white/20"></div>
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span x-show="has()" x-text="'{{ __('partner.map_position') }} ' + (has() ? parseFloat(state.lat).toFixed(5) + ', ' + parseFloat(state.lng).toFixed(5) : '')"></span>
            <span x-show="! has()">{{ __('partner.map_empty') }}</span>
            <button type="button" x-show="has()" x-on:click="state = { lat: null, lng: null }" class="underline">{{ __('partner.map_clear') }}</button>
        </div>
    </div>
</x-dynamic-component>
