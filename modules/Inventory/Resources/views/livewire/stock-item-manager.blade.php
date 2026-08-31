<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Stock Item Manager</h1>
    </div>
    @if (session()->has('success'))
        <div class="alert alert-success shadow-sm">
            <span>{ session('success') }</span>
        </div>
    @endif
    <div class="card bg-base-100 shadow-sm border border-base-200 p-6">
        <p class="text-base-content/70">Management interface for stock item manager.</p>
    </div>
</div>
