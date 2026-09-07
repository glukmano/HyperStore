<?php

declare(strict_types=1);

namespace App\Core\DeveloperCenter\Livewire;

use App\Core\DeveloperCenter\Exceptions\DeveloperDocumentNotFoundException;
use App\Core\DeveloperCenter\Services\DeveloperDocumentationRepository;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class DocumentationViewer extends Component
{
    public ?string $section = null;

    public ?string $slug = null;

    public ?string $errorMessage = null;

    public function mount(?string $section = null, ?string $slug = null): void
    {
        $this->assertCan();
        $this->section = $section;
        $this->slug = $slug;
    }

    private function assertCan(): void
    {
        if (! auth()->user()?->can('developer.docs.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    public function render(DeveloperDocumentationRepository $repository): View
    {
        $document = null;
        $this->errorMessage = null;

        if ($this->section !== null && $this->slug !== null) {
            try {
                $document = $repository->resolve($this->section, $this->slug);
            } catch (DeveloperDocumentNotFoundException $e) {
                $this->errorMessage = $e->getMessage();
            }
        }

        return view('developer-center.livewire.documentation-viewer', [
            'sections' => $repository->listSections(),
            'document' => $document,
        ])->layout('layouts.control-center', ['title' => 'Developer Center']);
    }
}
