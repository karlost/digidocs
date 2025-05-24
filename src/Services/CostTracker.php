<?php

namespace Digihood\Digidocs\Services;

use SplObserver;
use SplSubject;
use NeuronAI\AgentInterface;
use NeuronAI\Observability\Events\MessageSaved;
use Digihood\Digidocs\Services\MemoryService;

class CostTracker implements SplObserver
{
    private ?string $currentFilePath = null;

    public function __construct(
        private MemoryService $memory
    ) {}

    /**
     * Nastaví aktuální soubor pro tracking
     */
    public function setCurrentFile(?string $filePath): void
    {
        $this->currentFilePath = $filePath;
    }

    /**
     * Observer metoda pro sledování NeuronAI událostí
     */
    public function update(SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if ($event === 'message-saved' && $data instanceof MessageSaved) {
            $this->handleMessageSaved($subject, $data);
        }
    }

    /**
     * Zpracuje uloženou zprávu s Usage informacemi
     */
    private function handleMessageSaved(AgentInterface $agent, MessageSaved $data): void
    {
        $usage = $data->message->getUsage();

        if (!$usage) {
            return;
        }

        $model = $this->getModelFromAgent($agent);
        $cost = $this->calculateCost($model, $usage->inputTokens, $usage->outputTokens);

        // Zaznamenej do databáze
        $this->memory->recordTokenUsage(
            $model,
            $usage->inputTokens,
            $usage->outputTokens,
            $cost,
            $this->currentFilePath
        );
    }

    /**
     * Získá model z agenta
     */
    private function getModelFromAgent(AgentInterface $agent): string
    {
        try {
            $provider = $agent->resolveProvider();

            // Pro OpenAI - zkus různé způsoby získání modelu
            if (method_exists($provider, 'getModel')) {
                return $provider->getModel();
            }

            // Zkus reflection pro protected/private properties
            $reflection = new \ReflectionClass($provider);

            // Zkus property 'model'
            if ($reflection->hasProperty('model')) {
                $modelProperty = $reflection->getProperty('model');
                $modelProperty->setAccessible(true);
                return $modelProperty->getValue($provider);
            }

            // Fallback na název třídy
            $className = get_class($provider);
            $baseName = basename(str_replace('\\', '/', $className));
            return strtolower($baseName);

        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Vypočítá náklady na základě modelu a tokenů
     */
    public function calculateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $rates = $this->getModelRates($model);

        $inputCost = ($inputTokens / 1000000) * $rates['input'];
        $outputCost = ($outputTokens / 1000000) * $rates['output'];

        return $inputCost + $outputCost;
    }

    /**
     * Získá aktuální ceny pro model
     */
    public function getModelRates(string $model): array
    {
        return $this->getModelRatesFromConfig($model);
    }

    /**
     * Získá ceny modelu z config souboru
     */
    private function getModelRatesFromConfig(string $model): array
    {
        $pricingConfig = config('digidocs.pricing', []);

        // Pokud není config načten, zkus načíst přímo
        if (empty($pricingConfig)) {
            $pricingConfig = include __DIR__ . '/../../config/pricing.php';
        }

        // Zkus najít model přímo
        if ($this->findModelInProviders($model, $pricingConfig['providers'] ?? [])) {
            return $this->findModelInProviders($model, $pricingConfig['providers']);
        }

        // Fallback na default ceny (už v MTok)
        return $pricingConfig['default'] ?? ['input' => 1.00, 'output' => 2.00];
    }

    /**
     * Najde model v providers konfiguraci
     */
    private function findModelInProviders(string $model, array $providers): ?array
    {
        foreach ($providers as $providerName => $models) {
            if (isset($models[$model])) {
                // Ceny jsou už v MTok, takže je vrátíme přímo
                return [
                    'input' => $models[$model]['input'],
                    'output' => $models[$model]['output'],
                ];
            }
        }

        // Zkus najít podle prefixu modelu
        $provider = $this->detectProviderFromModel($model);
        if ($provider && isset($providers[$provider])) {
            // Zkus najít podobný model
            $similarModel = $this->findSimilarModel($model, $providers[$provider]);
            if ($similarModel) {
                return [
                    'input' => $similarModel['input'],
                    'output' => $similarModel['output'],
                ];
            }
        }

        return null;
    }

    /**
     * Detekuje providera z názvu modelu
     */
    private function detectProviderFromModel(string $model): ?string
    {
        if (str_starts_with($model, 'gpt-') || str_starts_with($model, 'o3') || str_starts_with($model, 'o4-')) {
            return 'openai';
        }

        if (str_starts_with($model, 'claude-')) {
            return 'anthropic';
        }

        if (str_starts_with($model, 'gemini-')) {
            return 'gemini';
        }

        if (str_starts_with($model, 'deepseek-')) {
            return 'deepseek';
        }

        if (str_starts_with($model, 'mistral-')) {
            return 'mistral';
        }

        return null;
    }

    /**
     * Najde podobný model v rámci providera
     */
    private function findSimilarModel(string $model, array $providerModels): ?array
    {
        // Pro OpenAI modely
        if (str_starts_with($model, 'gpt-4.1-nano')) {
            return $providerModels['gpt-4.1-nano'] ?? null;
        }

        if (str_starts_with($model, 'gpt-4.1-mini')) {
            return $providerModels['gpt-4.1-mini'] ?? null;
        }

        if (str_starts_with($model, 'gpt-4.1')) {
            return $providerModels['gpt-4.1'] ?? null;
        }

        if (str_starts_with($model, 'gpt-4o-mini')) {
            return $providerModels['gpt-4o-mini'] ?? null;
        }

        if (str_starts_with($model, 'gpt-4o')) {
            return $providerModels['gpt-4o'] ?? null;
        }

        if (str_starts_with($model, 'gpt-4')) {
            return $providerModels['gpt-4'] ?? null;
        }

        // Pro Anthropic modely
        if (str_contains($model, 'claude-4')) {
            if (str_contains($model, 'opus')) {
                return $providerModels['claude-opus-4'] ?? null;
            }
            if (str_contains($model, 'sonnet')) {
                return $providerModels['claude-sonnet-4'] ?? null;
            }
        }

        return null;
    }

    /**
     * Získá informace o zdroji cen pro model
     */
    public function getPricingSource(string $model): string
    {
        // Všechny ceny jsou z config souboru
        return 'config';
    }

    /**
     * Odhadne náklady pro text (bez skutečného volání API)
     */
    public function estimateCost(string $model, string $inputText, int $estimatedOutputTokens = 500): array
    {
        // Jednoduchý odhad tokenů (přibližně 4 znaky = 1 token)
        $estimatedInputTokens = (int) ceil(strlen($inputText) / 4);

        $cost = $this->calculateCost($model, $estimatedInputTokens, $estimatedOutputTokens);
        $rates = $this->getModelRates($model);

        return [
            'estimated_input_tokens' => $estimatedInputTokens,
            'estimated_output_tokens' => $estimatedOutputTokens,
            'estimated_total_tokens' => $estimatedInputTokens + $estimatedOutputTokens,
            'estimated_cost' => $cost,
            'model' => $model,
            'rates_per_mtok' => $rates,
            'rates_display' => [
                'input' => '$' . number_format($rates['input'], 2) . ' / 1M tokens',
                'output' => '$' . number_format($rates['output'], 2) . ' / 1M tokens',
            ]
        ];
    }
}
