<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries;

final readonly class UpdateCounts
{
    public function __construct(
        public int $added = 0,
        public int $changed = 0,
        public int $deleted = 0,
        public int $unchanged = 0,
    ) {
    }

    /** @return array{added:int, changed:int, deleted:int, unchanged:int} */
    public function toArray(): array
    {
        return ['added' => $this->added, 'changed' => $this->changed, 'deleted' => $this->deleted, 'unchanged' => $this->unchanged];
    }

    public function plus(self $other): self
    {
        return new self(
            $this->added + $other->added,
            $this->changed + $other->changed,
            $this->deleted + $other->deleted,
            $this->unchanged + $other->unchanged,
        );
    }
}
