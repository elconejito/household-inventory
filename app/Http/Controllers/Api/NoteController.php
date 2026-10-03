<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNoteRequest;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Models\Household;
use App\Models\Note;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\NoteTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class NoteController extends Controller
{
    public function index(IndexNoteRequest $request, ApiResponse $apiResponse, NoteTransformer $transformer): JsonResponse
    {
        $user = $request->user();
        $notable = $this->notableFromParentRoute($request, $user);
        Gate::authorize('viewAny', [Note::class, $notable]);
        $includes = $this->requestedIncludes($request->query('include'));
        $paginator = QueryBuilder::for($notable->notes()->getQuery()->reorder())
            ->allowedFilters(AllowedFilter::exact('created_by'), AllowedFilter::trashed())
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->allowedIncludes(AllowedInclude::relationship('created_by', 'creator'))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();

        $response = $apiResponse->collection($paginator->items(), $transformer, $includes);
        $response['meta'] = [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
        $response['links'] = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        return response()->json($response);
    }

    public function store(StoreNoteRequest $request, ApiResponse $apiResponse, NoteTransformer $transformer): JsonResponse
    {
        $user = $request->user();
        $notable = $this->notableFromParentRoute($request, $user);
        Gate::authorize('create', [Note::class, $notable]);
        /** @var array{body: string} $data */
        $data = $request->validated('data');
        $includes = $this->requestedIncludes($request->query('include'));
        $note = DB::transaction(function () use ($notable, $user, $data): Note {
            $notable = $this->lockNotable($user, $notable);
            Gate::authorize('create', [Note::class, $notable]);
            $note = $notable->notes()->make(['body' => $data['body']]);
            $note->created_by = $user->getKey();
            $note->save();

            return $note;
        });

        if (in_array('created_by', $includes, true)) {
            $note->load('creator');
        }

        return response()->json($apiResponse->item($note, $transformer, $includes), 201);
    }

    public function update(UpdateNoteRequest $request, string $note, ApiResponse $apiResponse, NoteTransformer $transformer): JsonResponse
    {
        $note = $this->noteForUser($request, $note);
        /** @var array{body: string} $data */
        $data = $request->validated('data');
        $includes = $this->requestedIncludes($request->query('include'));
        $note = DB::transaction(function () use ($request, $note, $data): Note {
            $notable = $this->lockNotable($request->user(), $note->notable);
            $note = Note::query()->lockForUpdate()->findOrFail($note->getKey());
            $note->setRelation('notable', $notable);
            Gate::authorize('update', $note);

            if ($note->body !== $data['body']) {
                $note->body = $data['body'];
                $note->save();
            }

            return $note;
        });

        if (in_array('created_by', $includes, true)) {
            $note->load('creator');
        }

        return response()->json($apiResponse->item($note, $transformer, $includes));
    }

    public function destroy(Request $request, string $note): Response
    {
        $note = $this->noteForUser($request, $note);
        DB::transaction(function () use ($request, $note): void {
            $notable = $this->lockNotable($request->user(), $note->notable);
            $note = Note::query()->lockForUpdate()->findOrFail($note->getKey());
            $note->setRelation('notable', $notable);
            Gate::authorize('delete', $note);
            $note->delete();
        });

        return response()->noContent();
    }

    public function restore(Request $request, string $note, ApiResponse $apiResponse, NoteTransformer $transformer): JsonResponse
    {
        $note = Note::withTrashed()->findOrFail($note);
        $this->loadNotable($note);
        abort_unless($request->user()->households()->whereKey($note->notable->household_id)->exists(), 404);
        $includes = $this->requestedIncludes($request->query('include'));
        $note = DB::transaction(function () use ($request, $note): Note {
            $notable = $this->lockNotable($request->user(), $note->notable);
            $note = Note::withTrashed()->lockForUpdate()->findOrFail($note->getKey());
            $note->setRelation('notable', $notable);
            Gate::authorize('restore', $note);
            abort_unless($note->trashed(), 409);
            $note->restore();

            return $note;
        });

        if (in_array('created_by', $includes, true)) {
            $note->load('creator');
        }

        return response()->json($apiResponse->item($note, $transformer, $includes));
    }

    private function noteForUser(Request $request, string $id): Note
    {
        $note = Note::query()->findOrFail($id);
        $this->loadNotable($note);
        abort_unless($request->user()->households()->whereKey($note->notable->household_id)->exists(), 404);

        return $note;
    }

    private function loadNotable(Note $note): void
    {
        $class = Relation::getMorphedModel($note->notable_type);
        abort_unless($class !== null && is_subclass_of($class, Model::class), 404);
        $query = $class::query();

        if (method_exists($class, 'trashed')) {
            $query->withTrashed();
        }

        $note->setRelation('notable', $query->findOrFail($note->notable_id));
    }

    private function notableFromParentRoute(Request $request, User $user): Model
    {
        $morphType = $request->route('notable_type');
        $class = is_string($morphType) ? Relation::getMorphedModel($morphType) : null;
        abort_unless($class !== null && is_subclass_of($class, Model::class), 404);
        $parameters = $request->route()->parameters();
        unset($parameters['notable_type']);
        $id = reset($parameters);
        $household = $this->household($user);
        $query = $class::query()->where('household_id', $household->getKey());

        if (method_exists($class, 'trashed')) {
            $query->withTrashed();
        }

        return $query->findOrFail($id);
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }

    private function lockNotable(User $user, Model $notable): Model
    {
        $household = $user->households()->firstOrFail();
        Household::query()->lockForUpdate()->findOrFail($household->getKey());
        $class = $notable::class;
        $query = $class::query()
            ->where('household_id', $household->getKey())
            ->lockForUpdate();

        if (method_exists($class, 'trashed')) {
            $query->withTrashed();
        }

        return $query->findOrFail($notable->getKey());
    }

    /** @return array<int, string> */
    private function requestedIncludes(mixed $value): array
    {
        $allowed = ['created_by'];
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_string($value)) {
            throw InvalidIncludeQuery::includesNotAllowed(collect(['include']), collect($allowed));
        }
        $includes = array_values(array_filter(array_map('trim', explode(',', $value))));
        $unknown = array_diff($includes, $allowed);
        if ($unknown !== []) {
            throw InvalidIncludeQuery::includesNotAllowed(collect($unknown), collect($allowed));
        }

        return $includes;
    }
}
