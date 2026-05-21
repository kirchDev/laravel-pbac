<?php

declare(strict_types=1);

use KirchDev\Pbac\Decision\Decision;
use KirchDev\Pbac\Decision\DecisionTrace;
use KirchDev\Pbac\Facades\Pbac;
use KirchDev\Pbac\Support\Target;
use KirchDev\Pbac\Tests\Fixtures\Project;

describe('Target', function () {
    it('returns the first Model argument as a ModelIdentifier', function () {
        $project = Project::query()->create(['name' => 'Target Test']);

        $identifier = Target::fromArguments(['posts.update', $project, 'extra']);

        expect($identifier)->not->toBeNull()
            ->and($identifier->id)->toBe($project->getKey());
    });

    it('returns null when no argument is a Model', function () {
        expect(Target::fromArguments(['a', 1, true]))->toBeNull();
    });

    it('extracts the raw Model via modelFromArguments', function () {
        $project = Project::query()->create(['name' => 'Raw']);

        expect(Target::modelFromArguments(['ability', $project]))->toBe($project);
    });

    it('returns null from modelFromArguments when no Model is present', function () {
        expect(Target::modelFromArguments(['ability', 42]))->toBeNull();
    });
});

describe('DecisionTrace formatting', function () {
    beforeEach(function () {
        config()->set('app.env', 'local');
        config()->set('app.debug', true);
        config()->set('pbac.trace.redact', false);
    });

    it('formats step + context as a human-readable arrow chain', function () {
        $trace = (new DecisionTrace)
            ->add('looked_up_permission', ['ability' => 'posts.update'])
            ->add('matched_role', ['role' => 'editor'])
            ->add('allowed');

        expect($trace->formatted())->toBe(
            'looked_up_permission(ability=posts.update) → matched_role(role=editor) → allowed'
        );
    });

    it('formats redacted entries without context', function () {
        config()->set('pbac.trace.redact', true);

        $trace = (new DecisionTrace)
            ->add('looked_up_permission', ['ability' => 'posts.update'])
            ->add('allowed');

        expect($trace->formatted())->toBe('looked_up_permission → allowed');
    });

    it('honours the unredacted scope when formatting', function () {
        config()->set('pbac.trace.redact', true);

        $trace = (new DecisionTrace)->add('step', ['detail' => 'sensitive']);

        $output = Pbac::withUnredactedTrace(fn () => $trace->formatted());

        expect($output)->toBe('step(detail=sensitive)');
    });
});

describe('Decision factories', function () {
    it('allow() and deny() default the reason code', function () {
        expect(Decision::allow()->reason())->toBe('pbac.allowed')
            ->and(Decision::deny()->reason())->toBe('pbac.denied');
    });

    it('allow() exposes denied()=false and deny() exposes denied()=true', function () {
        expect(Decision::allow()->denied())->toBeFalse()
            ->and(Decision::deny()->denied())->toBeTrue();
    });
});
