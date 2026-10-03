<x-layouts::app :title="__('Send email')">
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Send email') }}</flux:heading>

        @if ($user)
            <flux:subheading class="mb-6">{{ __('To') }}: {{ $user->name }} ({{ $user->email }})</flux:subheading>
        @else
            <flux:subheading class="mb-6">
                {{ __('To :count users', ['count' => $count]) }}
                @if ($search)
                    ({{ __('matching ":search"', ['search' => $search]) }})
                @endif
            </flux:subheading>
        @endif

        <form method="POST"
              action="{{ $user ? route('users.email.store', $user) : route('users.email-bulk.store') }}"
              class="space-y-6">
            @csrf

            @unless ($user)
                <input type="hidden" name="search" value="{{ $search }}">
            @endunless

            <flux:input name="subject" :label="__('Subject')" :value="old('subject')" required />

            <flux:textarea name="body" :label="__('Message')" rows="6" required>{{ old('body') }}</flux:textarea>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
                <flux:button :href="route('users.index', ['search' => $search ?: null])" variant="ghost">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
