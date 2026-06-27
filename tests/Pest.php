<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest Test Suite Configuration
|--------------------------------------------------------------------------
|
| Here you may configure the Pest test runner to your needs.
|
*/

// Apply TestCase to tests that need Laravel bootstrap
uses(TestCase::class)->in('ActionResolverTest.php', 'ConditionEngineTest.php', 'EventLogTest.php', 'EventManagerTest.php', 'TriggerModelTest.php');

// WildcardMatcherTest runs without TestCase (plain PHP tests)
