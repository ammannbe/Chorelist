<?php

namespace App\Http\Controllers;

use App\Models\Household;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    /**
     * Back to the households page, with this household opened (also without JavaScript: `household` in the session).
     */
    protected function toHousehold(?Household $household, ?string $status = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route('households.index');
        if ($household !== null) {
            $redirect = $redirect->withFragment('household-'.$household->uuid)->with('household', $household->uuid);
        }

        return $error !== null ? $redirect->withErrors(['household' => $error]) : $redirect->with('status', $status);
    }
}
