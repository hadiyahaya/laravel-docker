<?php

namespace App\Http\Controllers;

use App\Jobs\SendUserMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserEmailController extends Controller
{
    /**
     * Show the "Send email" form for one user.
     */
    public function create(User $user): View
    {
        return view('users.email', [
            'user' => $user,
            'search' => null,
            'count' => 1,
        ]);
    }

    /**
     * Queue an email to one user.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validateMessage($request);

        SendUserMessage::dispatch($user, $validated['subject'], $validated['body']);

        return to_route('users.index')->with('status', __('Email queued for :name.', ['name' => $user->name]));
    }

    /**
     * Show the "Send email" form for all users (matching the search).
     */
    public function createBulk(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();

        return view('users.email', [
            'user' => null,
            'search' => $search,
            'count' => $this->recipients($request, $search)->count(),
        ]);
    }

    /**
     * Queue one email per user (matching the search).
     */
    public function storeBulk(Request $request): RedirectResponse
    {
        $validated = $this->validateMessage($request);
        $search = $request->string('search')->trim()->toString();

        $count = 0;

        $this->recipients($request, $search)->each(function (User $user) use ($validated, &$count) {
            SendUserMessage::dispatch($user, $validated['subject'], $validated['body']);
            $count++;
        });

        return to_route('users.index', ['search' => $search ?: null])
            ->with('status', __(':count emails queued.', ['count' => $count]));
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function validateMessage(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
    }

    /**
     * Users to email: everyone matching the search, except the sender.
     *
     * @return Builder<User>
     */
    private function recipients(Request $request, string $search): Builder
    {
        return User::query()
            ->whereKeyNot($request->user()->getKey())
            ->search($search);
    }
}
