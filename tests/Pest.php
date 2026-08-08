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
uses(TestCase::class)->in(
    'ActionResolverTest.php',
    'ConditionEngineTest.php',
    'ConfigCompletenessTest.php',
    'ConfigTableNamesTest.php',
    'ContractBindingTest.php',
    'DispatchTriggerJobTest.php',
    'DomainEventTest.php',
    'DomainEventImmutabilityTest.php',
    'EdgeCasesTest.php',
    'EdgeCasesPhase2Test.php',
    'EdgeCasesPhase3Test.php',
    'EventsComprehensiveTest.php',
    'EventsEdgeCaseTest.php',
    'EventsFacadeProxyTest.php',
    'EventsFireCommandTest.php',
    'EventsFinalClassesTest.php',
    'EventHistoryStatsTest.php',
    'EventsListCommandTest.php',
    'EventsLogCommandTest.php',
    'EventsServiceProviderConfigTest.php',
    'EventsSubscribeCommandTest.php',
    'EventsSubscriptionsCommandTest.php',
    'EventsUnsubscribeCommandTest.php',
    'EventLogTest.php',
    'EventManagerAdvancedTest.php',
    'EventManagerCacheTtlTest.php',
    'EventManagerIntegrationTest.php',
    'EventManagerParseActionsTest.php',
    'EventManagerRegisterAliasTest.php',
    'EventManagerTest.php',
    'EventsRedeliverCommandTest.php',
    'EventsServiceProviderTest.php',
    'EventsPhase4Test.php',
    'EventsPhase5QualityTest.php',
    'EventsPhase6ProductionTest.php',
    'EventsPhase7FinalTest.php',
    'EventSourcingTest.php',
    'MigrationConfigDrivenTest.php',
    'MigrationStructureTest.php',
    'ProductionDeploymentTest.php',
    'ProductionReadyTest.php',
    'ReadonlyPropertiesTest.php',
    'ServiceProviderBindingTest.php',
    'SubscriptionBuilderTest.php',
    'SubscriptionMaxFailuresConfigTest.php',
    'SubscriptionSignConfigTest.php',
    'SubscriptionTest.php',
    'TraitConsistencyTest.php',
    'TriggerBuilderExtendedTest.php',
    'TriggerModelTest.php',
    'TypedPropertiesTest.php',
    'WildcardIntegrationTest.php',
    'WildcardMatcherEdgeCasesTest.php',
    'WebhookActionTest.php',
    'EventsEnableCommandTest.php',
    'EventsDisableCommandTest.php',
    'EventsRetryCommandTest.php',
    'EventsRegisterCommandTest.php',
    'ProductionHardeningTest.php',
    'EventManagerParseActionsTypeTest.php',
    'EventManagerFireModelTest.php',
    'ConditionEngineEdgeCasesTest.php',
    'EventsPhase8ProductionTest.php',
    'EventsPhase9ProductionTest.php',
    'EventsPhase10ProductionTest.php',
    'EventsPhase11ProductionTest.php',
    'EventsPhase12ProductionTest.php',
    'EventsPhase13ProductionTest.php',
    'EventsPhase14ProductionTest.php',
    'EventsPhase15ProductionTest.php',
    'EventsPhase16ProductionTest.php',
    'EventsPhase17ProductionTest.php',
    'EventsPhase18ProductionTest.php',
    'EventManagerCrudTest.php',
    'EventsPhase19ProductionTest.php',
    'EventsPhase20ProductionTest.php',
    'ConditionEngineNullComparisonTest.php',
);

// WildcardMatcherTest and EscapesWildcardLikeTest run without TestCase (plain PHP tests)
