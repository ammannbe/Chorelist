<?php

namespace App\Http\Controllers;

use App\Households\AccountManagerLabel;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\HouseholdInvited;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admins invite by email. Only the user with the invited, verified address joins, after
 * confirming: by the link in the mail or on the households page.
 */
class HouseholdInvitationController extends Controller
{
    public function store(Request $request, Household $household): RedirectResponse
    {
        $this->ensureLocal();
        abort_unless($household->hasUser($request->user()), 404);
        abort_unless($household->isAdmin($request->user()), 403);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', Rule::in(Household::ROLES)],
        ]);
        $email = Str::lower($validated['email']);

        $existing = User::firstWhere('email', $email);
        if ($existing !== null && $household->hasUser($existing)) {
            return $this->toHousehold($household, error: __(':email is already a member.', ['email' => $email]));
        }

        [, $token] = HouseholdInvitation::issue($household, $request->user(), $email, $validated['role'] ?? Household::MEMBER);
        Notification::route('mail', $email)->notify(
            (new HouseholdInvited($request->user(), $household, route('household-invitations.show', $token, false)))
                ->locale($existing?->locale ?? $request->user()->locale ?? app()->getLocale()),
        );

        return $this->toHousehold($household, __('Invitation sent to :email.', ['email' => $email]));
    }

    /**
     * Revokes an open invitation (admins).
     */
    public function destroy(Request $request, Household $household, HouseholdInvitation $invitation): RedirectResponse
    {
        $this->ensureLocal();
        abort_unless($household->hasUser($request->user()), 404);
        abort_unless($household->isAdmin($request->user()), 403);
        abort_unless($invitation->household_id === $household->id, 404);

        $invitation->delete();

        return $this->toHousehold($household, __('Invitation withdrawn.'));
    }

    /**
     * Link in the mail. Not logged in: login or registration first, then back here (intended URL).
     */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $invitation = HouseholdInvitation::findByToken($token);
        if ($invitation === null) {
            return redirect()->route('households.index')->withErrors(['household' => __('This invitation is invalid or has expired.')]);
        }
        if ($invitation->household->hasUser($request->user())) {
            return $this->toHousehold($invitation->household);
        }

        return view('households.invitation', ['invitation' => $invitation, 'token' => $token, 'local' => Household::managedLocally()]);
    }

    public function acceptToken(Request $request, string $token): RedirectResponse
    {
        return $this->accept($request, HouseholdInvitation::findByToken($token));
    }

    /**
     * Accepting from the households page (open invitations for the user's address).
     */
    public function acceptOpen(Request $request, HouseholdInvitation $invitation): RedirectResponse
    {
        return $this->accept($request, HouseholdInvitation::open()->find($invitation->id));
    }

    public function decline(Request $request, HouseholdInvitation $invitation): RedirectResponse
    {
        $this->ensureLocal();
        abort_unless($invitation->isFor($request->user()), 404);
        $invitation->delete();

        return redirect()->route('households.index')->with('status', __('Invitation declined.'));
    }

    private function accept(Request $request, ?HouseholdInvitation $invitation): RedirectResponse
    {
        $this->ensureLocal();
        if ($invitation === null) {
            return redirect()->route('households.index')->withErrors(['household' => __('This invitation is invalid or has expired.')]);
        }
        if (! $invitation->isFor($request->user())) {
            return redirect()->route('households.index')->withErrors(['household' => __('This invitation is for :email. Log in with that address.', ['email' => $invitation->email])]);
        }

        $invitation->accept($request->user());

        return $this->toHousehold($invitation->household, __('You joined the household ":household".', ['household' => $invitation->household->name]));
    }

    private function ensureLocal(): void
    {
        abort_unless(Household::managedLocally(), 403, __('Households are managed in “:name”.', ['name' => AccountManagerLabel::get()]));
    }
}
