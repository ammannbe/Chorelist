<?php

namespace App\Http\Controllers;

use App\Households\AccountManagerLabel;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admins change roles and remove members, members leave. At least one admin has to remain.
 */
class HouseholdMemberController extends Controller
{
    public function update(Request $request, Household $household, User $member): RedirectResponse
    {
        $this->ensureLocal();
        $this->ensureAdmin($request->user(), $household);
        abort_unless($household->hasUser($member), 404);

        $role = $request->validate(['role' => ['required', Rule::in(Household::ROLES)]])['role'];
        if ($role !== Household::ADMIN && $household->isLastAdmin($member)) {
            return $this->toHousehold($household, error: __('A household needs at least one admin.'));
        }
        $household->setRole($member, $role);

        return $this->toHousehold($household, __('Saved.'));
    }

    /**
     * Remove a member, or leave the household. Only access goes, the lists stay with their owners.
     */
    public function destroy(Request $request, Household $household, User $member): RedirectResponse
    {
        $this->ensureLocal();
        $leaving = $member->is($request->user());
        if (! $leaving) {
            $this->ensureAdmin($request->user(), $household);
        }
        abort_unless($household->hasUser($member), 404);

        if ($household->isLastAdmin($member)) {
            return $this->toHousehold($household, error: $leaving
                ? __('You are the last admin. Make another member an admin first, or delete the household.')
                : __('A household needs at least one admin.'));
        }
        $household->removeUser($member);

        return $leaving
            ? $this->toHousehold(null, __('You left the household.'))
            : $this->toHousehold($household, __('Member removed.'));
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
