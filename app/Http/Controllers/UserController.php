<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * List all users, with search and pagination.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();

        $users = User::query()
            ->with('roles')
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    /**
     * Show the "Edit role" form.
     */
    public function edit(Request $request, User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    /**
     * Save the new role.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
        ]);

        $user->syncRoles([$validated['role']]);

        return to_route('users.index')->with('status', __('Role updated.'));
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user): RedirectResponse
    {
        $user->delete();

        return to_route('users.index')->with('status', __('User deleted.'));
    }

    /**
     * Roles the current user is allowed to give.
     * Only a super-admin can make someone else a super-admin.
     *
     * @return array<int, string>
     */
    private function assignableRoles(User $user): array
    {
        return Role::query()
            ->when(! $user->hasRole('super-admin'), fn ($query) => $query->where('name', '!=', 'super-admin'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
