<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Comprehensive provider and DI container binding verification.
 *
 * Ensures that the EventsServiceProvider correctly registers all services,
 * the Facade resolves to the right underlying class, and transient vs
 * singleton lifetimes are respected.
 */
describe('ServiceProvider Container Bindings', function (): void {
    it('registers EventManager as singleton', function (): void {
        $app = app();
        $a = $app->make(\ZeroBoiler\Events\EventManager::class);
        $b = $app->make(\ZeroBoiler\Events\EventManager::class);

        expect($a)->toBe($b);
    });

    it('registers ConditionEngine as singleton', function (): void {
        $app = app();
        $a = $app->make(ConditionEngine::class);
        $b = $app->make(ConditionEngine::class);

        expect($a)->toBe($b);
    });

    it('binds ConditionEngineContract to ConditionEngine', function (): void {
        $app = app();
        $instance = $app->make(ConditionEngineContract::class);

        expect($instance)->toBeInstanceOf(ConditionEngine::class);
    });

    it('registers ActionResolver as singleton', function (): void {
        $app = app();
        $a = $app->make(ActionResolver::class);
        $b = $app->make(ActionResolver::class);

        expect($a)->toBe($b);
    });

    it('registers TriggerBuilder as transient', function (): void {
        $app = app();
        $a = $app->make(TriggerBuilder::class);
        $b = $app->make(TriggerBuilder::class);

        expect($a)->not->toBe($b);
    });

    it('registers SubscriptionBuilder as transient', function (): void {
        $app = app();
        $a = $app->make(SubscriptionBuilder::class);
        $b = $app->make(SubscriptionBuilder::class);

        expect($a)->not->toBe($b);
    });

    it('provides list includes all service classes', function (): void {
        $provider = new EventsServiceProvider(app());
        $provides = $provider->provides();

        expect($provides)->toContain(
            \ZeroBoiler\Events\EventManager::class,
            ConditionEngine::class,
            ConditionEngineContract::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            \ZeroBoiler\Events\EventScheduler::class,
        );
    });

    it('facade accessor resolves to EventManager instance', function (): void {
        $root = EventManager::getFacadeRoot();

        expect($root)->toBeInstanceOf(\ZeroBoiler\Events\EventManager::class);
    });
});

describe('EventManager Edge Cases', function (): void {
    it('fire throws on empty event name', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $manager->fire('');
    })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

    it('fire throws on zero-string event name', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $manager->fire('0');
    })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

    it('fireModel throws on empty model class', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $manager->fireModel('', 'created', new \stdClass);
    })->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

    it('fireModel throws on empty action', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $manager->fireModel('App\\Models\\Order', '', new \stdClass);
    })->throws(\InvalidArgumentException::class, 'Model action cannot be empty');

    it('fire returns silently when globally disabled', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $manager->setEnabled(false);

        // Create a trigger that would normally fire
        $trigger = Trigger::factory()->enabled()->create([
            'event' => 'test.silent',
            'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification',
        ]);

        // Should not throw, should silently return
        $manager->fire('test.silent', ['key' => 'value']);

        // No event logs should have been created
        $logs = EventLog::where('event', 'test.silent')->count();
        expect($logs)->toBe(0);

        // Re-enable for other tests
        $manager->setEnabled(true);
    });

    it('deleteTrigger returns false for non-existent ID', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $result = $manager->deleteTrigger('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    it('enable returns false for non-existent trigger', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $result = $manager->enable('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    it('disable returns false for non-existent trigger', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $result = $manager->disable('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    it('getTrigger returns null for non-existent ID', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $result = $manager->getTrigger('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeNull();
    });

    it('listTriggers returns empty collection when no triggers exist', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        // Delete any existing triggers
        Trigger::query()->delete();

        $result = $manager->listTriggers();

        expect($result)->toBeInstanceOf(Collection::class);
        expect($result->count())->toBe(0);
    });

    it('listTriggers filters by enabled status', function (): void {
        Trigger::query()->delete();

        Trigger::factory()->create(['enabled' => true, 'event' => 'filter.test']);
        Trigger::factory()->create(['enabled' => false, 'event' => 'filter.test']);

        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

        $enabled = $manager->listTriggers(enabled: true);
        $disabled = $manager->listTriggers(enabled: false);

        expect($enabled->count())->toBe(1);
        expect($disabled->count())->toBe(1);
    });
});

describe('TriggerBuilder Validation', function (): void {
    it('save throws on empty event name', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->on('');

        $builder->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification')->save();
    })->throws(\InvalidArgumentException::class, 'Event name is required');

    it('save throws on zero-string event name', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->on('0');

        $builder->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification')->save();
    })->throws(\InvalidArgumentException::class, 'Event name is required');

    it('save throws when no action is provided', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->on('test.noaction');

        $builder->save();
    })->throws(\InvalidArgumentException::class, 'At least one action is required');

    it('actions validates each class is a non-empty string', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->on('test.validation');

        $builder->actions(['ValidClass', '', 'AnotherClass']);
    })->throws(\InvalidArgumentException::class, 'Each action class must be a non-empty string');
});

describe('SubscriptionBuilder Validation', function (): void {
    it('save throws on empty event name', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->subscribe('', 'https://example.com/webhook');

        $builder->save();
    })->throws(\InvalidArgumentException::class, 'Event name is required for subscription');

    it('save throws on empty URL', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->subscribe('test.event', '');

        $builder->save();
    })->throws(\InvalidArgumentException::class, 'Webhook URL is required for subscription');

    it('save throws on invalid URL', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->subscribe('test.event', 'not-a-url');

        $builder->save();
    })->throws(\InvalidArgumentException::class, 'Webhook URL must be a valid URL');

    it('save throws on non-HTTP scheme URL', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->subscribe('test.event', 'ftp://files.example.com/upload');

        $builder->save();
    })->throws(\InvalidArgumentException::class, 'Webhook URL must use HTTP or HTTPS protocol');

    it('save throws on file:// scheme URL', function (): void {
        $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->subscribe('test.event', 'file:///etc/passwd');

        $builder->save();
    })->throws(\InvalidArgumentException::class, 'Webhook URL must use HTTP or HTTPS protocol');
});

describe('ActionResolver Error Handling', function (): void {
    it('throws on non-existent class', function (): void {
        $resolver = app()->make(ActionResolver::class);

        $resolver->resolve('NonExistent\\ActionClass');
    })->throws(\InvalidArgumentException::class, 'does not exist');

    it('throws on class that does not implement Triggerable', function (): void {
        $resolver = app()->make(ActionResolver::class);

        $resolver->resolve(\stdClass::class);
    })->throws(\InvalidArgumentException::class, 'must implement');
});

describe('WildcardMatcher Pure Functions', function (): void {
    it('catch-all star matches non-empty event', function (): void {
        expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
        expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
    });

    it('catch-all star does not match empty string', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    });

    it('double star matches non-empty event', function (): void {
        expect(WildcardMatcher::matches('**', 'order.placed'))->toBeTrue();
    });

    it('double star does not match empty string', function (): void {
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    it('single segment wildcard matches exactly one segment', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    });

    it('exact match works without wildcards', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    it('extractWildcards returns empty for non-matching patterns', function (): void {
        expect(WildcardMatcher::extractWildcards('order.*', 'order.placed.extra'))->toBe([]);
    });

    it('extractWildcards returns empty for ** patterns', function (): void {
        expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
    });

    it('findMatchingPatterns returns correct subset', function (): void {
        $patterns = ['order.*', 'user.*', '*.created'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe(['order.*']);
    });
});

describe('DispatchTriggerJob Configuration', function (): void {
    it('reads tries from config', function (): void {
        app()['config']->set('events.retry.tries', 5);

        $job = new DispatchTriggerJob(
            triggerId: '00000000-0000-0000-0000-000000000001',
            event: 'config.test',
            payload: [],
        );

        expect($job->tries)->toBe(5);
    });

    it('falls back to default tries when config is invalid', function (): void {
        app()['config']->set('events.retry.tries', 'not_a_number');

        $job = new DispatchTriggerJob(
            triggerId: '00000000-0000-0000-0000-000000000002',
            event: 'config.test',
            payload: [],
        );

        expect($job->tries)->toBe(3);
    });

    it('reads queue name from config', function (): void {
        app()['config']->set('events.queue.queue', 'events');

        $job = new DispatchTriggerJob(
            triggerId: '00000000-0000-0000-0000-000000000003',
            event: 'config.test',
            payload: [],
        );

        expect($job->queue)->toBe('events');
    });

    it('reads connection from config', function (): void {
        app()['config']->set('events.queue.connection', 'redis');

        $job = new DispatchTriggerJob(
            triggerId: '00000000-0000-0000-0000-000000000004',
            event: 'config.test',
            payload: [],
        );

        expect($job->connection)->toBe('redis');
    });
});
