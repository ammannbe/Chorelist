<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\TaskList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    /**
     * Deletes the account: own lists are deleted for all members, shared ones are left.
     * Completions in other lists stay (without the user), so their ranking keeps its history.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('deleteAccount', [
            'password' => ['required', 'current_password'],
        ]);
        $user = $request->user();

        // Before deleting: logging out saves a new remember token, which would recreate the user.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        DB::transaction(function () use ($user) {
            // Another member takes over as admin, empty households are deleted.
            $user->households()->get()->each(fn (Household $household) => $household->removeUser($user, handOver: true));
            TaskList::where('owner_id', $user->id)->get()->each(function (TaskList $list) {
                $list->delete();
                $list->touchForMembers();
            });
            $user->memberships()->get()->each(fn ($membership) => $membership->taskList->removeMember($user));
            $user->delete();
        });

        return redirect('/?deleted=1');
    }
}
