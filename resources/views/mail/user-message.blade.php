<x-mail::message>
# Hi {{ $user->name }},

{{ $body }}

<x-mail::button :url="route('dashboard')">
Open {{ config('app.name') }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
