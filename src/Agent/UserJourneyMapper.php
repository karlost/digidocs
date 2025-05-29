<?php

namespace Digihood\Digidocs\Agent;

use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Chat\Messages\UserMessage;
use Digihood\Digidocs\Tools\CodeAnalyzerTool;
use Digihood\Digidocs\Tools\SemanticAnalysisTool;
use Digihood\Digidocs\Services\SimpleDocumentationMemory;

class UserJourneyMapper extends Agent
{
    private SimpleDocumentationMemory $ragMemory;
    
    public function __construct(SimpleDocumentationMemory $ragMemory)
    {
        $this->ragMemory = $ragMemory;
    }

    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: config('digidocs.ai.api_key'),
            model: config('digidocs.ai.model', 'gpt-4'),
        );
    }

    public function instructions(): string
    {
        return new SystemPrompt(
            background: [
                "You are an expert in user experience and journey mapping",
                "You analyze application structure to identify user workflows",
                "You understand e-commerce patterns and user behaviors",
                "You create comprehensive user journey maps",
                "You identify pain points and optimization opportunities"
            ],
            steps: [
                "Analyze application features and controllers",
                "Identify different user personas and their goals",
                "Map out step-by-step user journeys for each persona",
                "Identify decision points and alternative paths",
                "Consider error scenarios and edge cases",
                "Create journey maps that are practical and actionable",
                "Use existing documentation context from RAG system"
            ],
            output: [
                "Generate structured user journey data",
                "Include persona definitions",
                "Map complete workflows with all steps",
                "Identify required features for each step",
                "Suggest documentation needs for each journey",
                "Output in JSON format for processing"
            ]
        );
    }

    protected function tools(): array
    {
        return [
            CodeAnalyzerTool::make(),
            SemanticAnalysisTool::make(),
        ];
    }

    /**
     * Map user journeys based on application structure
     */
    public function mapUserJourneys(array $appStructure): array
    {
        // Get context from RAG memory
        $existingJourneys = $this->ragMemory->searchDocumentation(
            "user journey workflow process",
            topK: 10
        );

        $prompt = "Analyze this application structure and create comprehensive user journey maps.

Application structure: " . json_encode($appStructure) . "

Existing journey documentation context: " . json_encode($existingJourneys) . "

Create journey maps for these personas:
1. First-time visitor
2. Registered buyer
3. Seller/Vendor
4. Administrator

For each journey include:
- Entry point
- Goal/Intent
- Step-by-step process
- Required features at each step
- Decision points
- Success criteria
- Potential friction points
- Alternative paths

Return as structured JSON.";

        $response = $this->chat(new UserMessage($prompt));
        $journeys = json_decode($response->getContent(), true);

        // Store journey maps in RAG memory
        foreach ($journeys as $journey) {
            $this->ragMemory->storeDocumentationChunk(
                content: json_encode($journey),
                metadata: [
                    'type' => 'user_journey',
                    'persona' => $journey['persona'] ?? 'unknown',
                    'journey_name' => $journey['name'] ?? 'unknown',
                    'steps_count' => count($journey['steps'] ?? [])
                ]
            );
        }

        return $journeys;
    }

    /**
     * Analyze specific user flow
     */
    public function analyzeUserFlow(string $flowName, array $features): array
    {
        // Search for similar flows in memory
        $similarFlows = $this->ragMemory->searchDocumentation(
            "user flow {$flowName}",
            topK: 5
        );

        $prompt = "Analyze the user flow '{$flowName}' based on these features: " . json_encode($features) . "

Similar flows from documentation: " . json_encode($similarFlows) . "

Create a detailed flow analysis including:
1. Prerequisites
2. Entry points
3. Step-by-step actions
4. UI elements needed
5. Validation points
6. Success/failure scenarios
7. Exit points
8. Metrics to track

Focus on user perspective, not technical implementation.";

        $response = $this->chat(new UserMessage($prompt));
        return json_decode($response->getContent(), true);
    }

    /**
     * Identify critical user paths
     */
    public function identifyCriticalPaths(array $appStructure): array
    {
        $prompt = "Based on this e-commerce application structure: " . json_encode($appStructure) . "

Identify the most critical user paths that are essential for business success:
1. Primary conversion path (visitor to customer)
2. Repeat purchase path
3. Account management path
4. Problem resolution path

For each critical path, identify:
- Why it's critical
- Key steps that must work perfectly
- Common failure points
- Documentation priorities

Return as structured JSON.";

        $response = $this->chat(new UserMessage($prompt));
        $criticalPaths = json_decode($response->getContent(), true);

        // Store in RAG for future reference
        $this->ragMemory->storeDocumentationChunk(
            content: json_encode($criticalPaths),
            metadata: [
                'type' => 'critical_paths',
                'count' => count($criticalPaths),
                'generated_at' => now()->toIso8601String()
            ]
        );

        return $criticalPaths;
    }

    /**
     * Generate journey-based documentation structure
     */
    public function generateJourneyDocumentationPlan(array $journeys): array
    {
        $prompt = "Based on these user journeys: " . json_encode($journeys) . "

Create a documentation plan that:
1. Groups related journeys
2. Identifies common steps across journeys
3. Suggests documentation sections needed
4. Prioritizes based on user importance
5. Maps journeys to features

Structure the documentation to guide users through their goals, not just list features.

Return as JSON with:
- documentation_sections: array of sections with titles and descriptions
- journey_to_docs_mapping: which journeys belong in which sections
- shared_content: content that should be referenced across multiple sections
- priority_order: order to create documentation";

        $response = $this->chat(new UserMessage($prompt));
        return json_decode($response->getContent(), true);
    }

    /**
     * Analyze user pain points from journeys
     */
    public function analyzePainPoints(array $journeys, array $features): array
    {
        $context = $this->ragMemory->searchDocumentation(
            "user problems issues pain points friction",
            topK: 10
        );

        $prompt = "Analyze these user journeys to identify pain points: " . json_encode($journeys) . "

Available features: " . json_encode($features) . "
Known issues from documentation: " . json_encode($context) . "

Identify:
1. Complex multi-step processes that could be simplified
2. Missing features that would improve journeys
3. Confusing decision points
4. Error-prone steps
5. Accessibility concerns

For each pain point, suggest:
- How to address it in documentation
- Quick tips or workarounds
- Feature improvements

Return as structured JSON.";

        $response = $this->chat(new UserMessage($prompt));
        return json_decode($response->getContent(), true);
    }

    /**
     * Create persona-based journey maps
     */
    public function createPersonaJourneyMaps(array $appStructure): array
    {
        $personas = $this->identifyPersonas($appStructure);
        $journeyMaps = [];

        foreach ($personas as $persona) {
            $prompt = "Create a detailed journey map for persona: " . json_encode($persona) . "

Application features: " . json_encode($appStructure['features']) . "

Create a comprehensive journey including:
1. Persona background and goals
2. Typical scenarios they encounter
3. Step-by-step journeys for main tasks
4. Emotional journey (frustrations, satisfactions)
5. Touch points with the system
6. Required documentation at each step

Make it realistic and actionable for documentation purposes.";

            $response = $this->chat(new UserMessage($prompt));
            $journeyMap = json_decode($response->getContent(), true);
            
            $journeyMaps[$persona['name']] = $journeyMap;
            
            // Store in RAG
            $this->ragMemory->storeDocumentationChunk(
                content: json_encode($journeyMap),
                metadata: [
                    'type' => 'persona_journey',
                    'persona' => $persona['name'],
                    'goals' => $persona['goals'] ?? [],
                    'scenarios_count' => count($journeyMap['scenarios'] ?? [])
                ]
            );
        }

        return $journeyMaps;
    }

    /**
     * Identify user personas
     */
    private function identifyPersonas(array $appStructure): array
    {
        $prompt = "Based on this e-commerce application structure: " . json_encode($appStructure) . "

Identify user personas with:
1. Name and role
2. Goals and motivations
3. Technical proficiency
4. Typical tasks
5. Pain points
6. Documentation needs

Consider both primary and secondary personas.
Return as JSON array.";

        $response = $this->chat(new UserMessage($prompt));
        return json_decode($response->getContent(), true);
    }

    /**
     * Map features to journey steps
     */
    public function mapFeaturesToJourneySteps(array $journeys, array $features): array
    {
        $prompt = "Map application features to user journey steps.

Journeys: " . json_encode($journeys) . "
Features: " . json_encode($features) . "

For each journey step, identify:
1. Which features are used
2. How the feature supports the user goal
3. Alternative features available
4. Feature dependencies

Create a comprehensive mapping to ensure documentation covers all user needs.

Return as JSON with structure:
{
  'journey_step_id': {
    'primary_features': [],
    'alternative_features': [],
    'dependencies': [],
    'documentation_focus': ''
  }
}";

        $response = $this->chat(new UserMessage($prompt));
        $mapping = json_decode($response->getContent(), true);

        // Store mapping in RAG
        $this->ragMemory->storeDocumentationChunk(
            content: json_encode($mapping),
            metadata: [
                'type' => 'feature_journey_mapping',
                'journeys_count' => count($journeys),
                'features_count' => count($features),
                'mappings_count' => count($mapping)
            ]
        );

        return $mapping;
    }
    
    /**
     * Alias for mapUserJourneys - for backward compatibility
     */
    public function identifyUserJourneys(array $appStructure): array
    {
        return $this->mapUserJourneys($appStructure);
    }
}