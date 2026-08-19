<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\EventLog;

describe('EventLog status constants', function () {
    it('defines all four required statuses', function () {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
    });

    it('has exactly four statuses in $statuses array', function () {
        expect(EventLog::$statuses)->toHaveCount(4);
    });

    it('contains all statuses in $statuses array', function () {
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    });

    it('has no duplicate statuses', function () {
        $unique = array_unique(EventLog::$statuses);
        expect(count($unique))->toBe(count(EventLog::$statuses));
    });

    it('has all statuses as string type', function () {
        foreach (EventLog::$statuses as $status) {
            expect($status)->toBeString();
        }
    });

    it('aligns with migration enum values', function () {
        // The migration defines: ['pending', 'dispatched', 'completed', 'failed']
        $migrationStatuses = ['pending', 'dispatched', 'completed', 'failed'];
        sort(EventLog::$statuses);
        sort($migrationStatuses);
        expect(EventLog::$statuses)->toBe($migrationStatuses);
    });
});
