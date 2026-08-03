<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'activity_log.view',
            message: 'Anda tidak memiliki akses melihat Activity Log.',
        );

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'user_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:50'],
            'log_name' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = Activity::query()
            ->with('causer:id,name,username')
            ->orderByDesc('id');

        $search = trim((string) ($validated['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('description', 'ILIKE', "%{$search}%")
                    ->orWhereHas(
                        'causer',
                        function ($causerQuery) use ($search) {
                            $causerQuery
                                ->where('name', 'ILIKE', "%{$search}%")
                                ->orWhere('username', 'ILIKE', "%{$search}%");
                        },
                    );
            });
        }

        if (!empty($validated['user_id'])) {
            $query->where('causer_id', (int) $validated['user_id']);
        }

        if (!empty($validated['event'])) {
            $query->where('event', $validated['event']);
        }

        if (!empty($validated['log_name'])) {
            $query->where('log_name', $validated['log_name']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $activities = $query->paginate($perPage);

        $activities->getCollection()->transform(
            fn(Activity $activity): array => $this->transform($activity),
        );

        return response()->json([
            'success' => true,
            'message' => 'Data activity log berhasil dimuat.',
            'data' => $activities->items(),

            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Filter Options
    |--------------------------------------------------------------------------
    | Daftar log_name dan event yang benar-benar ada di data, supaya dropdown
    | filter di frontend tidak hardcode dan selalu sinkron dengan data asli.
    |--------------------------------------------------------------------------
    */
    public function filterOptions(Request $request): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'activity_log.view',
            message: 'Anda tidak memiliki akses melihat Activity Log.',
        );

        $logNames = Activity::query()
            ->whereNotNull('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name');

        $events = Activity::query()
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event');

        return response()->json([
            'success' => true,
            'message' => 'Data filter activity log berhasil dimuat.',
            'data' => [
                'log_names' => $logNames,
                'events' => $events,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensurePermission(
            request: $request,
            permission: 'activity_log.view',
            message: 'Anda tidak memiliki akses melihat detail Activity Log.',
        );

        $activity = Activity::query()
            ->with('causer:id,name,username')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Detail activity log berhasil dimuat.',
            'data' => $this->transform($activity, true),
        ]);
    }

    private function transform(
        Activity $activity,
        bool $withProperties = false,
    ): array {
        $causer = $activity->causer;

        $data = [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,

            'causer' => $causer
                ? [
                    'id' => $causer->id,
                    'name' => $causer->name,
                    'username' => $causer->username ?? null,
                ]
                : null,

            'subject_type' => $activity->subject_type
                ? class_basename($activity->subject_type)
                : null,

            'subject_id' => $activity->subject_id,

            'ip' => $activity->properties['ip'] ?? null,
            'method' => $activity->properties['method'] ?? null,
            'path' => $activity->properties['path'] ?? null,
            'status_code' => $activity->properties['status_code'] ?? null,

            'created_at' => $activity->created_at,
        ];

        if ($withProperties) {
            $data['properties'] = $activity->properties;
        }

        return $data;
    }

    private function ensurePermission(
        Request $request,
        string $permission,
        string $message,
    ): void {
        $user = $request->user();

        if (
            !$user
            || !$user->hasPermission($permission)
        ) {
            abort(403, $message);
        }
    }
}
