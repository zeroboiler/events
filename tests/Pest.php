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
uses(TestCase::class)->in('ActionResolverTest.php', 'ConditionEngineTest.php', 'ConfigCompletenessTest.php', 'DispatchTriggerJobTest.php', 'DomainEventTest.php', 'EdgeCasesTest.php', 'EdgeCasesPhase2Test.php', 'EventHistoryStatsTest.php', 'EventLogTest.php', 'EventManagerCacheTtlTest.php', 'EventManagerIntegrationTest.php', 'EventManagerParseActionsTest.php', 'EventManagerTest.php', 'EventsServiceProviderTest.php', 'SubscriptionBuilderTest.php', 'SubscriptionSignConfigTest.php', 'SubscriptionTest.php', 'TriggerBuilderExtendedTest.php', 'TriggerModelTest.php');

// WildcardMatcherTest and EscapesWildcardLikeTest run without TestCase (plain PHP tests)
