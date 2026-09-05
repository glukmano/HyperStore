<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\Localization\LocaleManager;
use App\Core\Localization\ValueObjects\LocaleCode;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Language;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Owner Delta §1/§6: Languages/Locales are dynamically manageable from
 * Control Center — the platform's supported-locale list is this table,
 * not config('app.supported_locales') (that stays a seeder-only bootstrap
 * default). Owner Delta §9: deactivate-only, no destructive delete.
 */
class LanguageManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $native_name = '';

    public string $direction = 'ltr';

    public bool $is_active = true;

    public function createLanguage(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:35'],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'direction' => ['required', 'in:ltr,rtl'],
            'is_active' => ['boolean'],
        ]);

        if (! LocaleCode::isValid($validated['code'])) {
            $this->addError('code', 'Not a valid locale code (e.g. ar, ar-SY, de-CH, zh-Hans-CN).');

            return;
        }

        $normalized = LocaleCode::normalize($validated['code']);

        Language::create([
            'code' => $normalized,
            'language_code' => LocaleCode::languageSubtag($normalized),
            'name' => $validated['name'],
            'native_name' => $validated['native_name'],
            'direction' => $validated['direction'],
            'is_active' => (bool) $validated['is_active'],
            'is_default' => false,
        ]);

        Cache::forget(LocaleManager::ACTIVE_LOCALES_CACHE_KEY);

        $this->reset(['code', 'name', 'native_name']);
        session()->flash('success', 'Locale created successfully.');
    }

    /**
     * Owner Delta §9/§39: never leaves a Market/Store with an invalid
     * default — refuses to deactivate a Locale currently in use.
     */
    public function deactivateLanguage(int $languageId): void
    {
        $this->authorizeManage();

        $language = Language::find($languageId);
        if ($language === null) {
            return;
        }

        if ($language->is_default) {
            session()->flash('error', 'Cannot deactivate the platform default Locale.');

            return;
        }

        $blockingMarket = Market::query()->where('default_locale_code', $language->code)->first();
        if ($blockingMarket !== null) {
            session()->flash('error', "Cannot deactivate: Market [{$blockingMarket->code}] uses this Locale as its default.");

            return;
        }

        $language->update(['is_active' => false]);
        Cache::forget(LocaleManager::ACTIVE_LOCALES_CACHE_KEY);
        session()->flash('success', 'Locale deactivated.');
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('locales.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    public function render(): View
    {
        $languages = Language::query()->orderBy('sort_order')->orderBy('code')->get();

        return view('livewire.control-center.language-manager', [
            'languages' => $languages,
        ])->layout('layouts.control-center', ['title' => 'Languages']);
    }
}
