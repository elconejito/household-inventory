<?php

namespace App\Http\Controllers\Api;

use App\Actions\ManageItemImage;
use App\Actions\UploadItemImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexItemImageRequest;
use App\Http\Requests\StoreItemImageRequest;
use App\Http\Requests\UpdateItemImageRequest;
use App\Models\Household;
use App\Models\ItemImage;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\ItemImageTransformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class ItemImageController extends Controller
{
    public function index(
        IndexItemImageRequest $request,
        string $item,
        ApiResponse $apiResponse,
        ItemImageTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $item = $household->items()->withTrashed()->findOrFail($item);
        Gate::authorize('viewAny', ItemImage::class);

        $query = QueryBuilder::for(ItemImage::query()->where('item_id', $item->getKey()), $request)
            ->allowedFilters(AllowedFilter::trashed())
            ->allowedSorts()
            ->allowedIncludes(AllowedInclude::relationship('uploaded_by', 'uploader'))
            ->defaultSort('-is_primary', 'uploaded_at', 'id');
        $includes = $this->requestedIncludes($request);
        $paginator = $query
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

    public function store(
        StoreItemImageRequest $request,
        string $item,
        UploadItemImage $upload,
        ApiResponse $apiResponse,
        ItemImageTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $item = $household->items()->findOrFail($item);
        Gate::authorize('create', [ItemImage::class, $item]);
        $includes = $this->requestedIncludes($request);
        $data = $request->validated('data', []);
        $image = $upload->upload($household, $item, $request->user(), $request->file('image'), $data['caption'] ?? null);
        if (in_array('uploaded_by', $includes, true)) {
            $image->load('uploader');
        }

        return response()
            ->json($apiResponse->item($image, $transformer, $includes), 201)
            ->header('Location', route('items.images.index', ['item' => $item->getKey()]));
    }

    public function update(
        UpdateItemImageRequest $request,
        string $item_image,
        ManageItemImage $manage,
        ApiResponse $apiResponse,
        ItemImageTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $itemImage = $this->findImage($household, $item_image);
        Gate::authorize('update', $itemImage);
        $includes = $this->requestedIncludes($request);
        $itemImage = $manage->update($household, $itemImage, $request->validated('data'));
        if (in_array('uploaded_by', $includes, true)) {
            $itemImage->load('uploader');
        }

        return response()->json($apiResponse->item($itemImage, $transformer, $includes));
    }

    public function destroy(Request $request, string $item_image, ManageItemImage $manage): Response
    {
        $household = $this->household($request->user());
        $itemImage = $this->findImage($household, $item_image);
        Gate::authorize('delete', $itemImage);
        $this->requestedIncludes($request);
        $manage->delete($household, $itemImage);

        return response()->noContent();
    }

    public function restore(
        Request $request,
        string $item_image,
        ManageItemImage $manage,
        ApiResponse $apiResponse,
        ItemImageTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $itemImage = $this->findImage($household, $item_image, withTrashed: true);
        Gate::authorize('restore', $itemImage);
        $includes = $this->requestedIncludes($request);
        $itemImage = $manage->restore($household, $itemImage);
        if (in_array('uploaded_by', $includes, true)) {
            $itemImage->load('uploader');
        }

        return response()->json($apiResponse->item($itemImage, $transformer, $includes));
    }

    public function thumbnail(Request $request, string $item_image): Response
    {
        return $this->deliver($request, $item_image, thumbnail: true);
    }

    public function display(Request $request, string $item_image): Response
    {
        return $this->deliver($request, $item_image, thumbnail: false);
    }

    private function deliver(Request $request, string $id, bool $thumbnail): Response
    {
        $household = $this->household($request->user());
        $itemImage = $this->findImage($household, $id);
        Gate::authorize('view', $itemImage);
        $path = $thumbnail ? $itemImage->thumbnail_path : $itemImage->display_path;
        $mimeType = $thumbnail ? $itemImage->thumbnail_mime_type : $itemImage->display_mime_type;
        $storage = Storage::disk($itemImage->disk);

        abort_unless($storage->exists($path), 404);

        return response($storage->get($path), 200, [
            'Cache-Control' => 'private, max-age=300',
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Vary' => 'Authorization, Cookie',
        ]);
    }

    private function findImage(Household $household, string $id, bool $withTrashed = false): ItemImage
    {
        $query = ItemImage::query()->whereHas('item', function (Builder $items) use ($household): void {
            $items->where('household_id', $household->getKey())->whereNull('items.deleted_at');
        });

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->findOrFail($id);
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    private function requestedIncludes(Request $request): array
    {
        $query = QueryBuilder::for(ItemImage::query(), $request)
            ->allowedFilters(AllowedFilter::trashed())
            ->allowedSorts()
            ->allowedIncludes(AllowedInclude::relationship('uploaded_by', 'uploader'));

        return $request->query('include') === null || $request->query('include') === ''
            ? []
            : ['uploaded_by'];
    }
}
