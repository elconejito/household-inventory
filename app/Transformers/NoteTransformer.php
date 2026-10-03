<?php

namespace App\Transformers;

use App\Models\Note;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class NoteTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['created_by'];

    /** @return array{type: string, id: string, body: string, created_at: string, updated_at: string, is_edited: bool} */
    public function transform(mixed $note): array
    {
        /** @var Note $note */
        return [
            'type' => 'notes',
            'id' => (string) $note->getKey(),
            'body' => $note->body,
            'created_at' => $note->created_at->utc()->toISOString(),
            'updated_at' => $note->updated_at->utc()->toISOString(),
            'is_edited' => ! $note->created_at->equalTo($note->updated_at),
        ];
    }

    public function includeCreatedBy(Note $note): ResourceInterface
    {
        return $note->creator === null
            ? $this->null()
            : $this->item($note->creator, new UserTransformer);
    }
}
