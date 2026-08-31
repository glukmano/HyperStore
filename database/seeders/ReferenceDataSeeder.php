<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\ReferenceData\Models\Country;
use App\Core\ReferenceData\Models\Currency;
use App\Core\ReferenceData\Models\Language;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true],
            ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'is_default' => false, 'is_active' => true],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true],
            ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true],
            ['code' => 'zh', 'name' => 'Chinese', 'native_name' => '中文', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true],
        ];

        foreach ($languages as $lang) {
            Language::firstOrCreate(['code' => $lang['code']], $lang);
        }

        $currencies = [
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_default' => true, 'is_active' => true],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'ر.س', 'decimals' => 2, 'is_default' => false, 'is_active' => true],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_default' => false, 'is_active' => true],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'is_default' => false, 'is_active' => true],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'decimals' => 2, 'is_default' => false, 'is_active' => true],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'decimals' => 3, 'is_default' => false, 'is_active' => true],
        ];

        foreach ($currencies as $curr) {
            Currency::firstOrCreate(['code' => $curr['code']], $curr);
        }

        $countries = [
            ['iso2' => 'SA', 'iso3' => 'SAU', 'name' => 'Saudi Arabia', 'native_name' => 'المملكة العربية السعودية', 'phone_code' => '+966', 'default_currency_code' => 'SAR', 'default_locale_code' => 'ar', 'is_active' => true],
            ['iso2' => 'US', 'iso3' => 'USA', 'name' => 'United States', 'native_name' => 'United States', 'phone_code' => '+1', 'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'is_active' => true],
            ['iso2' => 'AE', 'iso3' => 'ARE', 'name' => 'United Arab Emirates', 'native_name' => 'الإمارات العربية المتحدة', 'phone_code' => '+971', 'default_currency_code' => 'AED', 'default_locale_code' => 'ar', 'is_active' => true],
            ['iso2' => 'DE', 'iso3' => 'DEU', 'name' => 'Germany', 'native_name' => 'Deutschland', 'phone_code' => '+49', 'default_currency_code' => 'EUR', 'default_locale_code' => 'de', 'is_active' => true],
            ['iso2' => 'GB', 'iso3' => 'GBR', 'name' => 'United Kingdom', 'native_name' => 'United Kingdom', 'phone_code' => '+44', 'default_currency_code' => 'GBP', 'default_locale_code' => 'en', 'is_active' => true],
        ];

        foreach ($countries as $country) {
            Country::firstOrCreate(['iso2' => $country['iso2']], $country);
        }
    }
}
