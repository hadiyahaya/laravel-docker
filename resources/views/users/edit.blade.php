<x-layouts::app :title="__('Edit role')">
    <div class="max-w-md">
        <flux:heading size="xl" level="1">{{ __('Edit role') }}</flux:heading>
        <flux:subheading class="mb-6">{{ $user->name }} ({{ $user->email }})</flux:subheading>

        <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <flux:select name="role" :label="__('Role')">
                @foreach ($roles as $role)
                    <flux:select.option :value="$role" :selected="old('role', $user->roles->first()?->name) === $role">
                        {{ $role }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button :href="route('users.index')" variant="ghost">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
