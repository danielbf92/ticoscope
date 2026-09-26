<?php

namespace TicoScope\Php;

/**
 * The Blueprint methods that return exactly one ColumnDefinition for one
 * literal column name, verified against the real
 * Illuminate\Database\Schema\Blueprint/ColumnDefinition source rather than
 * assumed (see NonNullableWithoutDefaultRule and ColumnTypeChangedRule for
 * the full rationale). Shared by both rules: they depend on the same
 * underlying concept ("a Blueprint call that defines one column"), not a
 * coincidental overlap, so a Laravel upgrade adding a new column-type
 * method only needs updating here, not in two places.
 *
 * Deliberately excluded: timestamps()/timestampsTz()/datetimes()/
 * softDeletes()/rememberToken() (nullable or defaulted internally, and/or
 * multi-column), id()/increments()-family (auto-increment primary keys,
 * not the risk either rule targets), morphs()-family (multi-column
 * macros), foreignIdFor()/rawColumn()/addColumn()/removeColumn() (first
 * argument isn't a literal column name, or too dynamic to reason about).
 */
final class BlueprintColumnMethods
{
    public const SINGLE_COLUMN_METHODS = [
        'char', 'string', 'tinyText', 'text', 'mediumText', 'longText',
        'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger',
        'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger',
        'unsignedMediumInteger', 'unsignedBigInteger',
        'float', 'double', 'decimal', 'unsignedDecimal',
        'boolean', 'enum', 'set', 'json', 'jsonb',
        'date', 'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz',
        'year', 'binary', 'uuid', 'ulid', 'ipAddress', 'macAddress',
        'foreignId', 'foreignUuid', 'foreignUlid',
    ];

    /**
     * Methods for which ->useCurrent() supplies an effective default
     * (DEFAULT CURRENT_TIMESTAMP) that resolveArgument() can't see any
     * other way. Deliberately excludes ->useCurrentOnUpdate(), which only
     * governs behavior on UPDATE, not the initial INSERT — it does not
     * make a column safe to add without ->nullable()/->default().
     */
    public const USE_CURRENT_ELIGIBLE = ['timestamp', 'timestampTz'];
}
