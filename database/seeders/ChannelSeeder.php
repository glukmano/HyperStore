<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Channels\Models\Channel;
use Illuminate\Database\Seeder;

class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['type' => 'website', 'name' => 'Online Store', 'handle' => 'website', 'is_active' => true],
            ['type' => 'mobile_app', 'name' => 'Mobile Application', 'handle' => 'mobile-app', 'is_active' => true],
            ['type' => 'pos', 'name' => 'POS Terminal', 'handle' => 'pos', 'is_active' => true],
            ['type' => 'b2b', 'name' => 'B2B Portal', 'handle' => 'b2b-portal', 'is_active' => true],
            ['type' => 'marketplace', 'name' => 'Marketplace Channel', 'handle' => 'marketplace', 'is_active' => true],
        ];

        foreach ($channels as $channel) {
            Channel::firstOrCreate(['handle' => $channel['handle']], $channel);
        }
    }
}
