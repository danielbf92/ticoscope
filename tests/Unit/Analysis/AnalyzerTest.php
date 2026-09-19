<?php

use TicoScope\Analysis\Analyzer;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Rules\Rule;

it('returns no findings when no rules are registered', function () {
    $analyzer = new Analyzer();

    $findings = $analyzer->analyze(new Diff('main', 'HEAD'));

    expect($findings)->toBe([]);
});

it('collects findings from every registered rule, in registration order', function () {
    $diff = new Diff('main', 'HEAD');
    $file = new ChangedFile('composer.lock', ChangeType::Modified);

    $ruleA = new class($file) implements Rule {
        public function __construct(private readonly ChangedFile $file)
        {
        }

        public function id(): string
        {
            return 'test.rule-a';
        }

        public function analyze(Diff $diff): array
        {
            return [new Finding('test.rule-a', Severity::Info, $this->file, 'from rule a')];
        }
    };

    $ruleB = new class($file) implements Rule {
        public function __construct(private readonly ChangedFile $file)
        {
        }

        public function id(): string
        {
            return 'test.rule-b';
        }

        public function analyze(Diff $diff): array
        {
            return [
                new Finding('test.rule-b', Severity::Warning, $this->file, 'first from rule b'),
                new Finding('test.rule-b', Severity::Critical, $this->file, 'second from rule b'),
            ];
        }
    };

    $findings = (new Analyzer([$ruleA, $ruleB]))->analyze($diff);

    expect($findings)->toHaveCount(3);
    expect($findings[0]->ruleId)->toBe('test.rule-a');
    expect($findings[1]->ruleId)->toBe('test.rule-b');
    expect($findings[1]->message)->toBe('first from rule b');
    expect($findings[2]->message)->toBe('second from rule b');
});

it('passes the same diff instance to every rule', function () {
    $diff = new Diff('main', 'HEAD');

    $rule = new class implements Rule {
        public array $seen = [];

        public function id(): string
        {
            return 'test.spy';
        }

        public function analyze(Diff $diff): array
        {
            $this->seen[] = $diff;

            return [];
        }
    };

    (new Analyzer([$rule]))->analyze($diff);

    expect($rule->seen)->toHaveCount(1);
    expect($rule->seen[0])->toBe($diff);
});
