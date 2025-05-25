<?php

namespace Digihood\Digidocs\Tests\Unit\Services;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Services\CostTracker;
use PHPUnit\Framework\Attributes\Test;
use Digihood\Digidocs\Services\MemoryService;

class CostTrackerTest extends DigidocsTestCase
{
    private CostTracker $tracker;
    private MemoryService $memory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memory = new MemoryService();
        $this->tracker = new CostTracker($this->memory);
    }

    #[Test]
    public function it_can_calculate_costs()
    {
        $cost = $this->tracker->calculateCost('gpt-4', 1000, 500);

        $this->assertIsFloat($cost);
        $this->assertGreaterThan(0, $cost);
    }

    #[Test]
    public function it_can_get_model_rates()
    {
        $rates = $this->tracker->getModelRates('gpt-4');

        $this->assertIsArray($rates);
        $this->assertArrayHasKey('input', $rates);
        $this->assertArrayHasKey('output', $rates);
        $this->assertIsFloat($rates['input']);
        $this->assertIsFloat($rates['output']);
    }

    #[Test]
    public function it_can_estimate_costs()
    {
        $estimation = $this->tracker->estimateCost('gpt-4', 'This is a test message', 100);

        $this->assertIsArray($estimation);
        $this->assertArrayHasKey('estimated_input_tokens', $estimation);
        $this->assertArrayHasKey('estimated_output_tokens', $estimation);
        $this->assertArrayHasKey('estimated_cost', $estimation);
        $this->assertArrayHasKey('model', $estimation);
        $this->assertEquals('gpt-4', $estimation['model']);
        $this->assertGreaterThan(0, $estimation['estimated_cost']);
    }

    #[Test]
    public function it_can_calculate_costs_for_different_models()
    {
        $gpt4Cost = $this->tracker->calculateCost('gpt-4', 1000, 500);
        $gpt35Cost = $this->tracker->calculateCost('gpt-3.5-turbo', 1000, 500);

        $this->assertIsFloat($gpt4Cost);
        $this->assertIsFloat($gpt35Cost);
        $this->assertGreaterThan(0, $gpt4Cost);
        $this->assertGreaterThan(0, $gpt35Cost);
    }

    #[Test]
    public function it_provides_detailed_cost_breakdown()
    {
        $estimation = $this->tracker->estimateCost('gpt-4', 'Input for cost breakdown test', 100);

        $this->assertArrayHasKey('estimated_input_tokens', $estimation);
        $this->assertArrayHasKey('estimated_output_tokens', $estimation);
        $this->assertArrayHasKey('estimated_cost', $estimation);
        $this->assertArrayHasKey('model', $estimation);
        $this->assertArrayHasKey('rates_per_mtok', $estimation);

        $this->assertEquals('gpt-4', $estimation['model']);
        $this->assertGreaterThan(0, $estimation['estimated_cost']);
    }

    #[Test]
    public function it_handles_unknown_models()
    {
        $cost = $this->tracker->calculateCost('unknown-model', 1000, 500);

        $this->assertIsFloat($cost);
        $this->assertGreaterThanOrEqual(0, $cost);
    }

    #[Test]
    public function it_can_set_current_file()
    {
        $this->tracker->setCurrentFile('test.php');

        // Test že metoda nevrací chybu
        $this->assertTrue(true);
    }

    #[Test]
    public function it_tracks_multiple_requests()
    {
        $cost1 = $this->tracker->calculateCost('gpt-4', 1000, 500);
        $cost2 = $this->tracker->calculateCost('gpt-4', 2000, 1000);
        $cost3 = $this->tracker->calculateCost('gpt-4', 1500, 750);

        $this->assertIsFloat($cost1);
        $this->assertIsFloat($cost2);
        $this->assertIsFloat($cost3);
        $this->assertGreaterThan($cost1, $cost2); // Více tokenů = vyšší cena
    }

    #[Test]
    public function it_provides_formatted_cost_display()
    {
        $estimation = $this->tracker->estimateCost('gpt-4', 'Test message for formatting', 100);

        $this->assertArrayHasKey('rates_display', $estimation);
        $display = $estimation['rates_display'];

        $this->assertArrayHasKey('input', $display);
        $this->assertArrayHasKey('output', $display);
        $this->assertStringContainsString('1M tokens', $display['input']);
        $this->assertStringContainsString('1M tokens', $display['output']);
    }

    #[Test]
    public function it_handles_very_long_messages()
    {
        $longText = str_repeat('This is a very long message. ', 1000);
        $estimation = $this->tracker->estimateCost('gpt-4', $longText, 500);

        $this->assertGreaterThan(1000, $estimation['estimated_input_tokens']);
        $this->assertGreaterThan(0, $estimation['estimated_cost']);
    }

    #[Test]
    public function it_tracks_different_message_types()
    {
        $inputCost = $this->tracker->calculateCost('gpt-4', 1000, 0);
        $outputCost = $this->tracker->calculateCost('gpt-4', 0, 1000);

        $this->assertGreaterThan(0, $inputCost);
        $this->assertGreaterThan(0, $outputCost);
        $this->assertNotEquals($inputCost, $outputCost); // Input a output mají různé ceny
    }

    #[Test]
    public function it_provides_accurate_token_counting()
    {
        $shortEstimation = $this->tracker->estimateCost('gpt-4', 'Hi', 10);
        $longEstimation = $this->tracker->estimateCost('gpt-4', str_repeat('Hello world ', 100), 100);

        $this->assertLessThan($longEstimation['estimated_input_tokens'], $shortEstimation['estimated_input_tokens']);
        $this->assertGreaterThan(0, $shortEstimation['estimated_input_tokens']);
    }

    #[Test]
    public function it_handles_empty_messages()
    {
        $estimation = $this->tracker->estimateCost('gpt-4', '', 10);

        $this->assertGreaterThanOrEqual(0, $estimation['estimated_input_tokens']);
        $this->assertGreaterThan(0, $estimation['estimated_cost']); // I prázdná zpráva má nějakou cenu kvůli output tokenům
    }

    #[Test]
    public function it_calculates_cost_per_request()
    {
        $cost1 = $this->tracker->calculateCost('gpt-4', 1000, 500);
        $cost2 = $this->tracker->calculateCost('gpt-4', 1000, 500);

        $this->assertEquals($cost1, $cost2); // Stejné tokeny = stejná cena
        $this->assertGreaterThan(0, $cost1);
    }

    #[Test]
    public function it_supports_cost_estimation_without_api_calls()
    {
        $estimation = $this->tracker->estimateCost('gpt-4', 'This is a test message for cost estimation', 100);

        $this->assertGreaterThan(0, $estimation['estimated_cost']);
        $this->assertIsFloat($estimation['estimated_cost']);
        $this->assertEquals('gpt-4', $estimation['model']);
    }
}
