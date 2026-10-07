<?php

namespace App\Http\Controllers;

use App\Jobs\SayHello;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SayHelloController extends Controller
{
    /**
     * Run the SayHello job in different ways, to see how the queue works.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['queue', 'sync', 'many', 'delay'])],
            'search' => ['nullable', 'string'],
        ]);

        $name = $request->user()->name;

        switch ($validated['mode']) {
            case 'queue':
                // Put the job in Redis. The queue worker runs it, the page returns at once.
                SayHello::dispatch($name);
                $status = __('1 job queued. Watch the queue log.');
                break;

            case 'sync':
                // Run the job now, inside this request. The page waits 3 seconds.
                SayHello::dispatchSync($name);
                $status = __('Job finished in this request (you waited 3 seconds).');
                break;

            case 'many':
                // One job for each of the first 4 users in the table. The worker runs them one by one.
                $names = User::query()->search($validated['search'] ?? null)->latest()->limit(4)->pluck('name');
                $names->each(fn (string $name) => SayHello::dispatch($name));
                $status = __(':count jobs queued. The worker runs them one by one.', ['count' => $names->count()]);
                break;

            default: // delay
                // Put the job in Redis now, but the worker only runs it after 20 seconds.
                SayHello::dispatch($name)->delay(now()->addSeconds(20));
                $status = __('1 job queued. It will run in 20 seconds.');
        }

        return to_route('users.index', ['search' => $validated['search'] ?? null])
            ->with('status', $status);
    }
}
