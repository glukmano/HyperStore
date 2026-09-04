{{-- Config['html'] is sanitized at save time by ContentSanitizerInterface,
     never re-interpreted here — this block type is gated behind the
     cms.page.use_html_block permission at the editor level. --}}
<section class="py-4">
    {!! $config['html'] ?? '' !!}
</section>
