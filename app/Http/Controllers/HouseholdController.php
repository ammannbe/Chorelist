<?php

namespace App\Http\Controllers;

use App\Households\AccountManagerHouseholds;
use App\Households\AccountManagerLabel;
use App\Models\Household;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Households page (server rendered, needs a connection): create, rename and delete households,
 * change roles, remove members and leave. Admins manage the household, members may leave it.
 * Read only with HOUSEHOLDS_DRIVER=account-manager: households are managed in the AccountManager and mirrored.
 */
class HouseholdController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('households', [
            'households' => $user->households()
                ->with(['users' => fn ($query) => $query->orderBy('name')->orderBy('email'), 'taskLists', 'invitations' => fn ($query) => $query->open()->orderBy('email')])
                ->withCount('pendingMembers')
                ->orderBy('name')
                ->get(),
            'invitations' => $user->householdInvitations()->with(['household', 'inviter'])->get(),
            'local' => Household::managedLocally(),
            'accountManagerUrl' => AccountManagerHouseholds::portalUrl(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureLocal();
        $name = $this->validateName($request);

        $household = Household::create(['name' => $name, 'created_by' => $request->user()->id]);
        $household->addUser($request->user(), Household::ADMIN);

        return $this->back(__('Household created.'));
    }

    public function update(Request $request, Household $household): RedirectResponse
    {
        $this->ensureLocal();
        $this->ensureAdmin($request->user(), $household);

        $household->update(['name' => $this->validateName($request)]);
        $household->touchLists();

        return $this->back(__('Saved.'));
    }

    public function destroy(Request $request, Household $household): RedirectResponse
    {
        $this->ensureLocal();
        $this->ensureAdmin($request->user(), $household);

        $household->deleteWithShares();

        return $this->back(__('Household deleted.'));
    }

    public function updateMember(Request $request, Household $household, User $member): RedirectResponse
    {
        $this->ensureLocal();
        $this->ensureAdmin($request->user(), $household);
        abort_unless($household->hasUser($member), 404);

        $role = $request->validate(['role' => ['required', Rule::in(Household::ROLES)]])['role'];
        if ($role !== Household::ADMIN && $household->isLastAdmin($member)) {
            return $this->back(error: __('A household needs at least one admin.'));
        }
        $household->setRole($member, $role);

        return $this->back(__('Saved.'));
    }

    /**
     * Admins remove members, members leave.
     */
    public function destroyMember(Request $request, Household $household, User $member): RedirectResponse
    {
        $this->ensureLocal();
        $self = $member->is($request->user());
        if (! $self) {
            $this->ensureAdmin($request->user(), $household);
        }
        abort_unless($household->hasUser($member), 404);

        if ($household->isLastAdmin($member)) {
            return $this->back(error: __('A household needs at least one admin. Make another member admin first, or delete the household.'));
        }
        $household->removeUser($member);

        return $this->back($self ? __('You left the household.') : __('Member removed.'));
    }

    private function validateName(Request $request): string
    {
        return trim($request->validate(['name' => ['required', 'string', 'max:100']])['name']);
    }

    private function ensureLocal(): void
    {
        abort_unless(Household::managedLocally(), 403, __('Households are managed in “:name”.', ['name' => AccountManagerLabel::get()]));
    }

    private function ensureAdmin(User $user, Household $household): void
    {
        // Not found for non-members, so nobody learns which households exist.
        abort_unless($household->hasUser($user), 404);
        abort_unless($household->isAdmin($user), 403);
    }

    private function back(?string $status = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route('households.index');

        return $error !== null ? $redirect->withErrors(['household' => $error]) : $redirect->with('status', $status);
    }
}
