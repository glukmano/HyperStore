<div class="space-y-6">

    {{-- Phase header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Platform Dashboard</h1>
            <p class="text-base-content/60 text-sm mt-1">Phase 01 — Foundation Validation Shell</p>
        </div>
        <x-ui.badge variant="success">LIVE</x-ui.badge>
    </div>

    {{-- Context Status --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-ui.card compact>
            <p class="text-xs text-base-content/50 uppercase tracking-wider mb-1">Locale</p>
            <p class="text-2xl font-bold font-mono">{{ $locale }}</p>
            <x-ui.badge variant="{{ $direction === 'rtl' ? 'accent' : 'primary' }}" class="mt-2">
                {{ strtoupper($direction) }}
            </x-ui.badge>
        </x-ui.card>

        <x-ui.card compact>
            <p class="text-xs text-base-content/50 uppercase tracking-wider mb-1">Tenant Context</p>
            <x-ui.badge variant="{{ $tenantResolved ? 'success' : 'warning' }}">
                {{ $tenantResolved ? 'Resolved' : 'Unresolved (Phase 01)' }}
            </x-ui.badge>
        </x-ui.card>

        <x-ui.card compact>
            <p class="text-xs text-base-content/50 uppercase tracking-wider mb-1">Store Context</p>
            <x-ui.badge variant="{{ $storeResolved ? 'success' : 'warning' }}">
                {{ $storeResolved ? 'Resolved' : 'Unresolved (Phase 01)' }}
            </x-ui.badge>
        </x-ui.card>

        <x-ui.card compact>
            <p class="text-xs text-base-content/50 uppercase tracking-wider mb-1">Modules</p>
            <p class="font-bold">
                <span class="text-success">{{ $enabledModules }} enabled</span>
                /
                <span class="text-error">{{ $disabledModules }} disabled</span>
            </p>
        </x-ui.card>
    </div>

    {{-- Stack versions --}}
    <x-ui.card title="Platform Stack">
        <div class="overflow-x-auto">
            <table class="table table-zebra w-full text-sm">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th>Version</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Laravel Framework</td>
                        <td class="font-mono">{{ app()->version() }}</td>
                        <td><x-ui.badge variant="success">Active</x-ui.badge></td>
                    </tr>
                    <tr>
                        <td>PHP</td>
                        <td class="font-mono">{{ PHP_VERSION }}</td>
                        <td><x-ui.badge variant="success">Active</x-ui.badge></td>
                    </tr>
                    <tr>
                        <td>Livewire</td>
                        <td class="font-mono">4.x</td>
                        <td><x-ui.badge variant="success">Active</x-ui.badge></td>
                    </tr>
                    <tr>
                        <td>Database</td>
                        <td class="font-mono">PostgreSQL</td>
                        <td><x-ui.badge variant="success">Connected</x-ui.badge></td>
                    </tr>
                    <tr>
                        <td>Cache / Session / Queue</td>
                        <td class="font-mono">Redis (predis)</td>
                        <td><x-ui.badge variant="success">Connected</x-ui.badge></td>
                    </tr>
                    <tr>
                        <td>Frontend</td>
                        <td class="font-mono">Tailwind CSS 4 + daisyUI 5</td>
                        <td><x-ui.badge variant="success">Built</x-ui.badge></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- RTL Toggle Demo --}}
    <x-ui.alert variant="{{ $direction === 'rtl' ? 'success' : 'info' }}">
        <span>
            ✅ RTL/LTR switching is active. Current direction:
            <strong>{{ strtoupper($direction) }}</strong>.
            Use the language toggle in the navbar to switch.
        </span>
    </x-ui.alert>

</div>
