<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Reviews\Models\ProductAnswer;
use Modules\Reviews\Models\ProductQuestion;
use Modules\Reviews\Services\ProductQaService;
use Tests\TestCase;

class ProductQaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'qa-tenant', 'name' => 'QA Tenant', 'status' => 'active']);
        $this->user = User::create(['name' => 'Asker', 'email' => 'asker-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'QA-SKU-1',
            translations: ['en' => ['name' => 'QA Product']],
        ));
    }

    public function test_a_question_starts_pending_and_can_be_answered(): void
    {
        $service = app(ProductQaService::class);
        $question = $service->ask($this->tenant->id, $this->user, $this->product->id, 'Does this come in blue?');

        $this->assertSame(ProductQuestion::STATUS_PENDING, $question->status);

        $vendorStaff = User::create(['name' => 'Vendor Staff', 'email' => 'vstaff-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $answer = $service->answer($question, $vendorStaff, 'Yes, blue is available.', isVendorAnswer: true);

        $this->assertTrue($answer->is_vendor_answer);
        $this->assertSame(1, $question->answers()->count());
    }

    public function test_moderating_a_question_updates_its_status(): void
    {
        $service = app(ProductQaService::class);
        $question = $service->ask($this->tenant->id, $this->user, $this->product->id, 'Spammy question?');
        $moderator = User::create(['name' => 'Mod', 'email' => 'qamod-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);

        $service->moderateQuestion($question, ProductQuestion::STATUS_REJECTED, $moderator);

        $this->assertSame(ProductQuestion::STATUS_REJECTED, $question->fresh()->status);
    }

    public function test_moderating_an_answer_updates_its_status(): void
    {
        $service = app(ProductQaService::class);
        $question = $service->ask($this->tenant->id, $this->user, $this->product->id, 'Question?');
        $answer = $service->answer($question, $this->user, 'An answer', isVendorAnswer: false);
        $moderator = User::create(['name' => 'Mod', 'email' => 'amod-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);

        $service->moderateAnswer($answer, ProductAnswer::STATUS_APPROVED, $moderator);

        $this->assertSame(ProductAnswer::STATUS_APPROVED, $answer->fresh()->status);
    }
}
