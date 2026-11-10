<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Entities\UsageLogs;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use PHPUnit\Framework\TestCase;

/** Booked costs add up per usage scope and survive serialization. */
class AIUsageBookedCostsTest extends TestCase
{
    public function testBookedAmountsAddUpPerUsageScope(): void
    {
        $aIUsageBookedCosts = new AIUsageBookedCosts();
        $aIUsageBookedCosts->addBookedCost('TOOL.WEB_SEARCH', 0.25);
        $aIUsageBookedCosts->addBookedCost('TOOL.WEB_SEARCH', 0.5);
        $aIUsageBookedCosts->addBookedCost('TOOL.PAGE_READ', 0.1);
        $aIUsageBookedCosts->addBookedCost('TOOL.PAGE_READ', 0.0);

        $this->assertSame(0.85, $aIUsageBookedCosts->totalCostInUSD);
        $this->assertSame(3, $aIUsageBookedCosts->bookingCount);
        $this->assertSame(2, $aIUsageBookedCosts->scopeBookedCosts->count());
        $this->assertSame(0.75, $aIUsageBookedCosts->scopeBookedCosts->getCostInUSDForUsageScope('TOOL.WEB_SEARCH'));
        $this->assertSame(0.0, $aIUsageBookedCosts->scopeBookedCosts->getCostInUSDForUsageScope('TOOL.UNKNOWN'));
    }

    public function testTheCostsOfNestedWorkAreAddedWhole(): void
    {
        $outerBookedCosts = new AIUsageBookedCosts();
        $outerBookedCosts->addBookedCost('TOOL.WEB_SEARCH', 0.25);
        $innerBookedCosts = new AIUsageBookedCosts();
        $innerBookedCosts->addBookedCost('TOOL.WEB_SEARCH', 0.5);
        $innerBookedCosts->addBookedCost('TOOL.PAGE_READ', 0.1);

        $outerBookedCosts->addBookedCostsOf($innerBookedCosts);
        $emptyBookedCosts = new AIUsageBookedCosts();
        $outerBookedCosts->addBookedCostsOf($emptyBookedCosts);

        $this->assertSame(0.85, $outerBookedCosts->totalCostInUSD);
        $this->assertSame(3, $outerBookedCosts->bookingCount);
        $this->assertSame(0.75, $outerBookedCosts->scopeBookedCosts->getCostInUSDForUsageScope('TOOL.WEB_SEARCH'));
        $this->assertSame(0.1, $outerBookedCosts->scopeBookedCosts->getCostInUSDForUsageScope('TOOL.PAGE_READ'));
    }

    public function testTheSerializedFormIsReadBack(): void
    {
        $aIUsageBookedCosts = new AIUsageBookedCosts();
        $aIUsageBookedCosts->addBookedCost('TOOL.WEB_SEARCH', 0.25);
        $aIUsageBookedCosts->addBookedCost('TOOL.PAGE_READ', 0.1);

        $serialized = json_decode(json_encode($aIUsageBookedCosts->toObject(false, forPersistence: true)));
        $this->assertSame('TOOL.WEB_SEARCH', $serialized->scopeBookedCosts->elements[0]->usageScope);
        $this->assertSame(0.25, $serialized->scopeBookedCosts->elements[0]->costInUSD);

        $readBookedCosts = new AIUsageBookedCosts();
        $readBookedCosts->setPropertiesFromObject($serialized);
        $this->assertSame(0.35, $readBookedCosts->totalCostInUSD);
        $this->assertSame(2, $readBookedCosts->bookingCount);
        $this->assertSame(0.1, $readBookedCosts->scopeBookedCosts->getCostInUSDForUsageScope('TOOL.PAGE_READ'));
        $this->assertTrue($readBookedCosts->hasBookedCosts());
    }
}
