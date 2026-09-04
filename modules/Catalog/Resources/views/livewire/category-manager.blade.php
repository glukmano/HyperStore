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
            @if (session('success'))
                <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
            @endif
            @if (session('error'))
                <x-ui.alert variant="error" class="mb-4">{{ session('error') }}</x-ui.alert>
            @endif

            <ul class="menu bg-base-200 w-full rounded-box p-2">
                @forelse ($categories as $cat)
                    <li>
                        <details open>
                            <summary class="font-medium">
                                <span class="flex-1">{{ $cat->translation()?->name ?? $cat->code }} <span class="text-xs opacity-60">({{ $cat->code }})</span></span>
                                <span class="flex gap-2 ms-auto">
                                    <button type="button" class="btn btn-xs btn-ghost" wire:click.stop="editCategory({{ $cat->id }})">Edit</button>
                                    <button type="button" class="btn btn-xs btn-ghost text-error" wire:click.stop="openArchiveConfirm({{ $cat->id }})">Archive</button>
                                </span>
                            </summary>
                            @if ($cat->children->isNotEmpty())
                                <ul>
                                    @foreach ($cat->children as $child)
                                        <li>
                                            <a class="flex items-center justify-between">
                                                <span>{{ $child->translation()?->name ?? $child->code }}</span>
                                                <span class="flex gap-2">
                                                    <button type="button" class="btn btn-xs btn-ghost" wire:click.stop="editCategory({{ $child->id }})">Edit</button>
                                                    <button type="button" class="btn btn-xs btn-ghost text-error" wire:click.stop="openArchiveConfirm({{ $child->id }})">Archive</button>
                                                </span>
                                            </a>
                                        </li>
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

    <x-ui.modal :show="$editingId !== null" title="Edit Category" wireClose="cancelEdit">
        <form wire:submit.prevent="updateCategory" class="space-y-4">
            <x-ui.input label="Code" wire:model="editCode" required />
            <x-ui.input label="Name" wire:model="editName" required />
            <x-ui.input label="Slug" wire:model="editSlug" required />

            <div class="form-control">
                <label class="label"><span class="label-text font-medium">Parent Category</span></label>
                <select wire:model="editParentId" class="select select-bordered">
                    <option value="">None (Top Level)</option>
                    @foreach ($allCategories as $cat)
                        @if ($cat->id !== $editingId)
                            <option value="{{ $cat->id }}">{{ $cat->translation()?->name ?? $cat->code }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="cancelEdit">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.confirm-dialog
        :show="$confirmArchiveId !== null"
        title="Archive Category"
        message="This will archive the category. It will no longer appear as active in the storefront or category selectors."
        confirmAction="archiveCategory"
        cancelAction="cancelArchiveConfirm"
        confirmLabel="Archive"
        variant="danger"
    />
</div>
