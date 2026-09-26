<?php

namespace TicoScope\Php;

/**
 * Every fluent call chain found on a Schema::table()/Schema::create()
 * closure's Blueprint variable, for one table.
 */
final readonly class SchemaTableOperation
{
    /**
     * @param list<list<array{method: string, args: list<string|null|list<string|null>>}>> $statements
     *     Each inner list is one fluent chain (e.g. a single
     *     `$table->string('x')->nullable();` statement becomes two steps
     *     in one chain). Each step's args mirror its syntactic argument
     *     positions: a resolved string literal, null where the argument
     *     isn't a literal (a variable, a call — never guessed), or a
     *     nested list of the same for an array-literal argument (e.g.
     *     `dropColumn(['a', 'b'])`).
     */
    public function __construct(
        public string $table,
        public array $statements,
    ) {
    }
}
