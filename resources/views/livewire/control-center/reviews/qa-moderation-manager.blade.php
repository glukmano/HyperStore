<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold">{{ __('Q&A Moderation') }}</h1>
        <p class="text-sm text-base-content/60">{{ __('Approve or reject product questions and answers.') }}</p>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Q&A Moderation' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.tabs :tabs="['pending' => __('Pending'), 'approved' => __('Approved'), 'rejected' => __('Rejected')]" :active="$statusFilter" switchAction="setStatusFilter" />

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('Questions') }}</h2>
        <x-ui.table :headers="[__('Product'), __('Asked by'), __('Question'), '']" :empty="$questions->isEmpty()" emptyMessage="{{ __('No questions in this status.') }}">
            @foreach ($questions as $question)
                <tr wire:key="question-{{ $question->id }}">
                    <td>{{ $question->product->name }}</td>
                    <td>{{ $question->user->name }}</td>
                    <td class="max-w-sm truncate">{{ $question->body }}</td>
                    <td class="text-end space-x-2">
                        <x-ui.button size="sm" variant="success" wire:click="approveQuestion({{ $question->id }})">{{ __('Approve') }}</x-ui.button>
                        <x-ui.button size="sm" variant="danger" wire:click="rejectQuestion({{ $question->id }})">{{ __('Reject') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$questions" />
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('Pending Answers') }}</h2>
        <x-ui.table :headers="[__('Question'), __('Answered by'), __('Answer'), '']" :empty="$pendingAnswers->isEmpty()" emptyMessage="{{ __('No answers pending review.') }}">
            @foreach ($pendingAnswers as $answer)
                <tr wire:key="answer-{{ $answer->id }}">
                    <td class="max-w-xs truncate">{{ $answer->question->body }}</td>
                    <td>{{ $answer->is_vendor_answer ? __('Vendor') : $answer->user->name }}</td>
                    <td class="max-w-sm truncate">{{ $answer->body }}</td>
                    <td class="text-end space-x-2">
                        <x-ui.button size="sm" variant="success" wire:click="approveAnswer({{ $answer->id }})">{{ __('Approve') }}</x-ui.button>
                        <x-ui.button size="sm" variant="danger" wire:click="rejectAnswer({{ $answer->id }})">{{ __('Reject') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
