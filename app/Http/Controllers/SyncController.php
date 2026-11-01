<?php

namespace App\Http\Controllers;

use App\Sync\AbsenceResource;
use App\Sync\ActivityResource;
use App\Sync\CompletionResource;
use App\Sync\ListResource;
use App\Sync\RetryLaterException;
use App\Sync\SyncResource;
use App\Sync\TaskResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * @var array<string, class-string<SyncResource>>
     */
    private const RESOURCES = [
        'lists' => ListResource::class,
        'tasks' => TaskResource::class,
        'completions' => CompletionResource::class,
        'activities' => ActivityResource::class,
        'absences' => AbsenceResource::class,
    ];

    public function pull(Request $request, string $collection): JsonResponse
    {
        $validated = $request->validate([
            'updated_at' => ['nullable', 'date_format:Y-m-d H:i:s.v'],
            'id' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json($this->resource($collection)->pull(
            $request->user(),
            $validated,
            (int) ($validated['limit'] ?? 100),
        ));
    }

    public function push(Request $request, string $collection): JsonResponse
    {
        $resource = $this->resource($collection);
        $fields = collect($resource->rules())->mapWithKeys(fn ($rules, $field) => ["*.newDocumentState.{$field}" => $rules])->all();

        $rows = $request->validate([
            '*' => ['array'],
            '*.assumedMasterState' => ['nullable', 'array'],
            '*.newDocumentState' => ['required', 'array'],
            '*.newDocumentState.id' => ['required', 'uuid'],
            '*.newDocumentState._deleted' => ['boolean'],
            '*.newDocumentState.version' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            ...$fields,
        ]);

        abort_if(count($rows) > 500, 413);

        try {
            return response()->json($resource->push($request->user(), array_values($rows)));
        } catch (RetryLaterException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function resource(string $collection): SyncResource
    {
        abort_unless(isset(self::RESOURCES[$collection]), 404);

        return app(self::RESOURCES[$collection]);
    }
}
