<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Messaging\Exceptions\InvalidAttachmentException;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Services\ConversationService;
use Modules\Messaging\Services\MessageAttachmentService;
use Modules\Messaging\Services\MessagingService;
use Tests\TestCase;

/**
 * Proves MIME validation is real content inspection (never client-declared
 * type alone) and files are stored on the private disk, never public.
 */
class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['slug' => 'attach-tenant', 'name' => 'Attach Tenant', 'status' => 'active']);
        $plan = VendorPlan::create(['tenant_id' => $tenant->id, 'name' => 'Basic', 'code' => 'basic']);
        $vendor = Vendor::create([
            'tenant_id' => $tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Attach Vendor',
            'platform_slug' => 'attach-vendor-'.uniqid(), 'legal_name' => 'Attach Vendor Corp', 'email' => 'attachvendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);
        $buyer = User::create(['name' => 'Buyer', 'email' => 'attachbuyer-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $conversation = app(ConversationService::class)->startBuyerVendorConversation($tenant->id, null, $buyer, $vendor->id);
        $this->message = app(MessagingService::class)->send($conversation, $buyer, 'See attached');
    }

    public function test_a_valid_image_attachment_is_accepted_and_stored_on_the_local_private_disk(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = app(MessageAttachmentService::class)->attach($this->message, $file);

        $this->assertSame('local', $media->disk);
        $this->assertSame('image/jpeg', $media->mime_type);
    }

    public function test_a_file_disguised_with_an_image_extension_but_executable_content_is_rejected(): void
    {
        // Real content inspection: a PHP script renamed to .jpg is caught
        // by getMimeType() (finfo-based), not by trusting the extension.
        $path = tempnam(sys_get_temp_dir(), 'evil').'.jpg';
        file_put_contents($path, "<?php echo 'pwned'; ?>");
        $file = new UploadedFile($path, 'evil.jpg', 'image/jpeg', null, true);

        $this->expectException(InvalidAttachmentException::class);
        app(MessageAttachmentService::class)->attach($this->message, $file);

        @unlink($path);
    }

    public function test_an_oversized_attachment_is_rejected(): void
    {
        $file = UploadedFile::fake()->image('big.jpg')->size(11 * 1024);

        $this->expectException(InvalidAttachmentException::class);
        app(MessageAttachmentService::class)->attach($this->message, $file);
    }

    public function test_stored_filenames_are_server_generated_never_the_client_supplied_name(): void
    {
        $file = UploadedFile::fake()->image('my-secret-plan.jpg');

        $media = app(MessageAttachmentService::class)->attach($this->message, $file);

        $this->assertStringNotContainsString('my-secret-plan', $media->file_name);
    }
}
