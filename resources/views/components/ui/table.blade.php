@props(['headers' => [], 'empty' => false, 'emptyMessage' => 'No records found.', 'zebra' => true])

<div class="overflow-x-auto w-full">
    <table {{ $attributes->merge(['class' => 'table w-full' . ($zebra ? ' table-zebra' : '')]) }}>
        @if(count($headers) > 0)
            <thead>
                <tr>
                    @foreach($headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>
            @if($empty)
                <tr>
                    <td colspan="{{ max(count($headers), 1) }}">
                        <x-ui.empty-state :message="$emptyMessage" />
                    </td>
                </tr>
            @else
                {{ $slot }}
            @endif
        </tbody>
    </table>
</div>
