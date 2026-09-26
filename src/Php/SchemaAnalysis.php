<?php

namespace TicoScope\Php;

final readonly class SchemaAnalysis
{
    /**
     * @param string[] $droppedTables Tables dropped via a direct
     *     Schema::drop()/dropIfExists() call.
     * @param SchemaTableOperation[] $tableOperations
     */
    public function __construct(
        public array $droppedTables,
        public array $tableOperations,
    ) {
    }
}
