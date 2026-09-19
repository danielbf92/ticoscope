<?php

use TicoScope\Diff\ChangeType;

it('backs each case with its stable string value', function () {
    expect(ChangeType::Added->value)->toBe('added');
    expect(ChangeType::Modified->value)->toBe('modified');
    expect(ChangeType::Deleted->value)->toBe('deleted');
    expect(ChangeType::Renamed->value)->toBe('renamed');
});

it('resolves cases from their string value', function () {
    expect(ChangeType::from('deleted'))->toBe(ChangeType::Deleted);
});
