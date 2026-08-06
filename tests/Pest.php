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
uses(TestCase::class)->in('ActionResolverTest.php', 'ConditionEngineTest.php', 'ConfigCompletenessTest.php', 'DispatchTriggerJobTest.php', 'DomainEventTest.php', 'EdgeCasesTest.php', 'EdgeCasesPhase2Test.php', 'EventsComprehensiveTest.php', 'EventsEdgeCaseTest.php', 'EventsFacadeProxyTest.php', 'EventsFireCommandTest.php', 'EventsFinalClassesTest.php', 'EventHistoryStatsTest.php', 'EventLogTest.php', 'EventManagerCacheTtlTest.php', 'EventManagerIntegrationTest.php', 'EventManagerParseActionsTest.php', 'EventManagerRegisterAliasTest.php', 'EventManagerTest.php', 'EventsRedeliverCommandTest.php', 'EventsServiceProviderTest.php', 'ProductionReadyTest.php', 'ServiceProviderBindingTest.php', 'SubscriptionBuilderTest.php', 'SubscriptionMaxFailuresConfigTest.php', 'SubscriptionSignConfigTest.php', 'SubscriptionTest.php', 'TraitConsistencyTest.php', 'TriggerBuilderExtendedTest.php', 'TriggerModelTest.php', 'TypedPropertiesTest.php', 'WildcardIntegrationTest.php');

// WildcardMatcherTest and EscapesWildcardLikeTest run without TestCase (plain PHP tests)
