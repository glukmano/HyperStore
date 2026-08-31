<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Add Category">
            <form wire:submit.prevent="createCategory" class="space-y-4">
                <x-ui.input label="Code" wire:model="code" placeholder="e.g. electronics" required />
                <x-ui.input label="Name" wire:model="name" placeholder="e.g. Electronics" required />
                <x-ui.input label="Slug" wire:model="slug" placeholder="e.g. electronics" required />

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Parent Category</span></label>
                    <select wire:model="parentId" class="select select-bordered">
                        <option value="">None (Top Level)</option>
                        @foreach ($allCategories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->translation()?->name ?? $cat->code }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="btn btn-primary w-full">Create Category</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Category Tree">
            <ul class="menu bg-base-200 w-full rounded-box p-2">
                @forelse ($categories as $cat)
                    <li>
                        <details open>
                            <summary class="font-medium">{{ $cat->translation()?->name ?? $cat->code }} <span class="text-xs opacity-60">({{ $cat->code }})</span></summary>
                            @if ($cat->children->isNotEmpty())
                                <ul>
                                    @foreach ($cat->children as $child)
                                        <li><a>{{ $child->translation()?->name ?? $child->code }}</a></li>
                                    @endforeach
                                </ul>
                            @endif
                        </details>
                    </li>
                @empty
                    <li class="p-4 text-center text-base-content/50">No categories found.</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</div>
