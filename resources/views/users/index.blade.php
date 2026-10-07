<x-layouts::app :title="__('Users')">
    <div class="mb-6 flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:subheading>{{ __('All registered users') }}</flux:subheading>
        </div>

        <div class="flex w-full max-w-2xl items-center justify-end gap-2">
            <form method="GET" action="{{ route('users.index') }}" class="flex w-full gap-2">
                <flux:input name="search" :value="$search" icon="magnifying-glass" :placeholder="__('Search name or email')" />
                <flux:button type="submit">{{ __('Search') }}</flux:button>
            </form>

            @can('emailAny', App\Models\User::class)
                <flux:button variant="primary" icon="envelope" :href="route('users.email-bulk.create', ['search' => $search ?: null])">
                    {{ __('Email all') }}
                </flux:button>
            @endcan

            <flux:dropdown position="bottom" align="end">
                <flux:button icon="queue-list" icon:trailing="chevron-down">{{ __('Say hello') }}</flux:button>

                <flux:menu>
                    @foreach ([
                        'queue' => __('Queue 1 job'),
                        'sync' => __('Run now (sync, wait 3s)'),
                        'many' => __('Queue 4 jobs'),
                        'delay' => __('Queue with 20s delay'),
                    ] as $mode => $label)
                        <form method="POST" action="{{ route('users.say-hello') }}" class="w-full">
                            @csrf
                            <input type="hidden" name="mode" value="{{ $mode }}">
                            <input type="hidden" name="search" value="{{ $search }}">

                            <flux:menu.item as="button" type="submit" class="w-full cursor-pointer">
                                {{ $label }}
                            </flux:menu.item>
                        </form>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('status')" class="mb-6" />
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Projects') }}</flux:table.column>
            <flux:table.column>{{ __('Roles') }}</flux:table.column>
            <flux:table.column>{{ __('Verified') }}</flux:table.column>
            <flux:table.column>{{ __('Joined') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($users as $user)
                <flux:table.row>
                    <flux:table.cell class="flex items-center gap-3">
                        <flux:avatar size="xs" :name="$user->name" :initials="$user->initials()" />
                        {{ $user->name }}
                    </flux:table.cell>

                    <flux:table.cell>{{ $user->email }}</flux:table.cell>

                    <flux:table.cell>{{ $user->department?->code ?? '-' }}</flux:table.cell>

                    <flux:table.cell>{{ $user->projects_count }}</flux:table.cell>

                    <flux:table.cell>
                        @forelse ($user->roles as $role)
                            <flux:badge size="sm" color="blue" inset="top bottom">{{ $role->name }}</flux:badge>
                        @empty
                            <flux:text>-</flux:text>
                        @endforelse
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($user->email_verified_at)
                            <flux:badge size="sm" color="green" inset="top bottom">{{ __('Yes') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('No') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>{{ $user->created_at->format('d M Y') }}</flux:table.cell>

                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @can('email', $user)
                                <flux:button size="sm" variant="ghost" icon="envelope" :href="route('users.email.create', $user)">
                                    {{ __('Email') }}
                                </flux:button>
                            @endcan

                            @can('update', $user)
                                <flux:button size="sm" variant="ghost" icon="shield-check" :href="route('users.edit', $user)">
                                    {{ __('Edit role') }}
                                </flux:button>
                            @endcan

                            @can('delete', $user)
                                <form method="POST" action="{{ route('users.destroy', $user) }}"
                                      onsubmit="return confirm(@js(__('Delete :name?', ['name' => $user->name])))">
                                    @csrf
                                    @method('DELETE')

                                    <flux:button size="sm" variant="ghost" icon="trash" type="submit">
                                        {{ __('Delete') }}
                                    </flux:button>
                                </form>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center">{{ __('No users found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</x-layouts::app>
