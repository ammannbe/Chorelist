<?php

namespace App\Http\Controllers;

use App\Households\AccountManagerHouseholds;
use App\Households\AccountManagerLabel;
use App\Models\Household;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Households page (server rendered, needs a connection): all households of the user with their
 * details (each one collapsible); create, rename and delete households. Members: HouseholdMemberController.
 * Read only with HOUSEHOLDS_DRIVER=account-manager: households are managed in the AccountManager and mirrored.
 */
class HouseholdController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('households.index', [
            'households' => $user->households()
                ->with([
                    'users' => fn ($query) => $query->orderBy('name')->orderBy('email'),
                    'taskLists' => fn ($query) => $query->with('owner')->orderBy('name'),
                    'invitations' => fn ($query) => $query->open()->orderBy('email'),
                ])
                ->withCount('pendingMembers')
                ->orderBy('name')
                ->get(),
            'invitations' => $user->householdInvitations()->with(['household', 'inviter'])->get(),
            // Opened household: after an action, or a form that failed validation.
            'open' => $request->session()->get('household', $request->old('_household')),
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

        return $this->toHousehold($household, __('Household created.'));
    }

    /**
     * Links to a household: the households page with it opened.
     */
    public function show(Request $request, Household $household): RedirectResponse
    {
        abort_unless($household->hasUser($request->user()), 404);

        return $this->toHousehold($household);
    }

    public function update(Request $request, Household $household): RedirectResponse
    {
        $this->ensureLocal();
        $this->ensureAdmin($request->user(), $household);

        $household->update(['name' => $this->validateName($request)]);
        $household->touchLists();

        return $this->toHousehold($household, __('Saved.'));
    }

    public function destroy(Request $request, Household $household): RedirectResponse
    {
        $this->ensureLocal();
        $this->ensureAdmin($request->user(), $household);

        $household->deleteWithShares();

        return $this->toHousehold(null, __('Household deleted.'));
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
}
