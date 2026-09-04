<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Models\User;
use Modules\Reviews\Models\ProductAnswer;
use Modules\Reviews\Models\ProductQuestion;

final class ProductQaService
{
    public function __construct(
        private readonly AuditManagerInterface $auditManager,
    ) {}

    public function ask(int $tenantId, User $user, int $productId, string $body): ProductQuestion
    {
        return ProductQuestion::query()->create([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'user_id' => $user->id,
            'body' => $body,
            'status' => ProductQuestion::STATUS_PENDING,
        ]);
    }

    public function answer(ProductQuestion $question, User $user, string $body, bool $isVendorAnswer): ProductAnswer
    {
        return $question->answers()->create([
            'user_id' => $user->id,
            'is_vendor_answer' => $isVendorAnswer,
            'body' => $body,
            'status' => ProductAnswer::STATUS_PENDING,
        ]);
    }

    public function moderateQuestion(ProductQuestion $question, string $status, User $moderator): ProductQuestion
    {
        $question->status = $status;
        $question->save();

        $this->auditManager->log(event: 'qa.question.moderated', properties: ['status' => $status], subject: $question, causer: $moderator);

        return $question;
    }

    public function moderateAnswer(ProductAnswer $answer, string $status, User $moderator): ProductAnswer
    {
        $answer->status = $status;
        $answer->save();

        $this->auditManager->log(event: 'qa.answer.moderated', properties: ['status' => $status], subject: $answer, causer: $moderator);

        return $answer;
    }
}
