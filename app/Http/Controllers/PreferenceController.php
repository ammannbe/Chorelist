<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    /**
     * Kinds of notifications; every kind goes out by push, mail, both or not at all.
     */
    public const NOTIFICATIONS = ['reminder', 'overdue', 'completed', 'task_changed', 'list_shared', 'weekly_summary'];

    /**
     * Defaults of the app settings stored per user.
     */
    public const DEFAULTS = [
        // Row actions on touch devices: swipe left, or long press for a menu.
        'row_actions' => 'swipe',
        // Order of the lists overview.
        'list_sort' => 'name_asc',
        // light, dark, or system
        'theme' => 'system',
        // Time of day reminders are sent (the user's time zone).
        'reminder_time' => '08:00',
        // Personal suggestions which task to do next.
        'recommendations' => true,
        'notifications' => [
            'reminder' => ['push'],
            'overdue' => ['push'],
            'completed' => [],
            'task_changed' => [],
            'list_shared' => ['mail'],
            'weekly_summary' => [],
        ],
    ];

    public const SORTS = ['manual', 'name_asc', 'name_desc', 'newest', 'oldest'];

    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'row_actions' => ['sometimes', Rule::in(['swipe', 'longpress'])],
            'list_sort' => ['sometimes', Rule::in(self::SORTS)],
            'theme' => ['sometimes', Rule::in(['system', 'light', 'dark'])],
            'reminder_time' => ['sometimes', 'date_format:H:i'],
            'recommendations' => ['sometimes', 'boolean'],
            'notifications' => ['sometimes', 'array:'.implode(',', self::NOTIFICATIONS)],
            'notifications.*' => ['array'],
            'notifications.*.*' => [Rule::in(['push', 'mail'])],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);

        $user = $request->user();
        if (isset($validated['timezone'])) {
            $user->timezone = $validated['timezone'];
            unset($validated['timezone']);
        }
        if (isset($validated['notifications'])) {
            $validated['notifications'] = [...($user->preferences['notifications'] ?? []), ...array_map('array_values', array_map('array_unique', $validated['notifications']))];
        }
        $user->forceFill(['preferences' => [...($user->preferences ?? []), ...$validated]])->save();

        return response()->noContent();
    }
}
