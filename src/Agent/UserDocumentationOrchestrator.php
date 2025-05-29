<?php

namespace Digihood\Digidocs\Agent;

use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Chat\Messages\UserMessage;
use Digihood\Digidocs\Services\DocumentationStructureAnalyzer;
use Digihood\Digidocs\Services\CostTracker;
use Digihood\Digidocs\Services\SimpleDocumentationMemory;
use Illuminate\Support\Facades\File;

class UserDocumentationOrchestrator extends Agent
{
    private ?CostTracker $costTracker = null;
    private DocumentationStructureAnalyzer $structureAnalyzer;
    private UserJourneyMapper $journeyMapper;
    private SimpleDocumentationMemory $ragMemory;
    private CrossReferenceManager $crossRefManager;
    private string $language = 'cs-CZ';
    
    public function __construct()
    {
        $this->ragMemory = new SimpleDocumentationMemory();
        $this->structureAnalyzer = new DocumentationStructureAnalyzer();
        $this->journeyMapper = new UserJourneyMapper($this->ragMemory);
        $this->crossRefManager = new CrossReferenceManager($this->ragMemory);
    }

    public function setCostTracker(CostTracker $costTracker): self
    {
        $this->costTracker = $costTracker;
        $this->observe($costTracker);
        return $this;
    }
    
    public function setLanguage(string $language): self
    {
        $this->language = $language;
        return $this;
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
        // Use English prompts from config
        $promptData = config('digidocs.prompts.user_documentation_agent.system');
        
        if (!$promptData) {
            $promptData = [
                'background' => [
                    'You are an expert user documentation writer',
                    'You write friendly, accessible documentation for end users',
                    'You focus on practical guides and problem-solving',
                ],
                'steps' => [
                    'Analyze application functionality from user perspective',
                    'Create logical guide structure',
                    'Write step-by-step instructions',
                ],
                'output' => [
                    'Generate documentation in Markdown format',
                    'Use friendly and accessible language',
                    'Structure content based on user goals',
                ],
            ];
        }
        
        return new SystemPrompt(
            background: $promptData['background'],
            steps: $promptData['steps'],
            output: $promptData['output']
        );
    }
    
    /**
     * Get language instruction from ISO code for AI
     * AI models understand ISO codes directly
     */
    private function getLanguageInstruction(string $languageCode): string
    {
        // Simply pass the ISO code to AI - it understands them directly
        return "ISO {$languageCode}";
    }

    public function generateSection(string $section, ?array $appStructure = null): void
    {
        if (!$appStructure) {
            $appStructure = $this->structureAnalyzer->analyzeApplication();
        }

        switch ($section) {
            case 'index':
                $this->generateIndexPage($appStructure);
                break;
            case 'getting-started':
                $this->generateGettingStarted($appStructure);
                break;
            case 'features':
                $this->generateFeatures($appStructure);
                break;
            case 'guides':
                $this->generateGuides($appStructure);
                break;
            case 'troubleshooting':
                $this->generateTroubleshooting($appStructure);
                break;
            case 'reference':
                $this->generateReference($appStructure);
                break;
        }
    }

    private function generateIndexPage(array $appStructure): void
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $prompt = "Create a comprehensive index page for user documentation in {$targetLang} language.

Application structure: " . json_encode($appStructure) . "

Requirements:
- Welcoming introduction to the application
- Clear overview of main features
- Navigation to main documentation sections
- Quick links to most important guides
- Brief description of each section
- Contact information for support
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        $this->saveDocument('index.md', $response->getContent());
    }

    private function generateGettingStarted(array $appStructure): void
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $docs = [
            'installation' => $this->generateInstallationGuide($appStructure),
            'first-login' => $this->generateFirstLoginGuide($appStructure),
            'basic-concepts' => $this->generateBasicConcepts($appStructure),
            'quick-start' => $this->generateQuickStart($appStructure)
        ];

        foreach ($docs as $filename => $content) {
            $this->saveDocument("getting-started/{$filename}.md", $content);
        }
    }

    private function generateInstallationGuide(array $appStructure): string
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $prompt = "Create an installation guide for end users in {$targetLang} language.

Application info: " . json_encode($appStructure) . "

Include:
- System requirements
- Step-by-step installation process
- Initial configuration
- Common installation issues
- Verification steps
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        return $response->getContent();
    }

    private function generateFirstLoginGuide(array $appStructure): string
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $prompt = "Create a first login guide for new users in {$targetLang} language.

Include:
- How to access the application
- Login process with screenshots placeholders
- Password requirements
- Account setup steps
- Profile configuration
- Navigation overview
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        return $response->getContent();
    }

    private function generateBasicConcepts(array $appStructure): string
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $existingContent = $this->ragMemory->search("basic concepts terminology", 5);
        
        $prompt = "Create a basic concepts guide explaining key terminology and concepts in {$targetLang} language.

Application structure: " . json_encode($appStructure) . "
Related content: " . json_encode($existingContent) . "

Include:
- Key terminology explained simply
- Main concepts of the application
- User roles and permissions
- Basic workflow overview
- Visual diagrams (as markdown)
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        return $response->getContent();
    }

    private function generateQuickStart(array $appStructure): string
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $journeys = $this->journeyMapper->identifyUserJourneys($appStructure);
        
        $prompt = "Create a quick start guide for new users in {$targetLang} language.

User journeys: " . json_encode($journeys) . "

Create a 5-minute guide including:
- Most common first tasks
- Step-by-step walkthrough
- Tips for quick success
- Links to detailed guides
- Checklist format where appropriate
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        return $response->getContent();
    }

    private function generateFeatures(array $appStructure): void
    {
        foreach ($appStructure['features'] as $feature => $details) {
            $docs = $this->generateFeatureDocumentation($feature, $details);
            foreach ($docs as $filename => $content) {
                $this->saveDocument("features/{$feature}/{$filename}.md", $content);
            }
        }
    }

    private function generateFeatureDocumentation(string $feature, array $details): array
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $docs = [];
        
        // Overview
        $prompt = "Create feature overview documentation for '{$feature}' in {$targetLang} language.

Feature details: " . json_encode($details) . "

Include:
- What the feature does
- Key benefits for users
- Main components/sections
- Common use cases
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        $docs['overview'] = $response->getContent();

        // Specific guides based on feature type
        if (in_array($feature, ['products', 'orders', 'users'])) {
            $docs = array_merge($docs, $this->generateCrudGuides($feature, $details));
        }

        return $docs;
    }

    private function generateCrudGuides(string $feature, array $details): array
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $guides = [];
        
        // Creating items
        $prompt = "Create a guide for creating new {$feature} in {$targetLang} language.

Include step-by-step instructions with:
- Required fields explanation
- Validation rules
- Best practices
- Common mistakes to avoid
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        $guides['creating'] = $response->getContent();

        // Managing items
        $prompt = "Create a guide for managing existing {$feature} in {$targetLang} language.

Include:
- How to view and search
- Editing procedures
- Bulk operations
- Status management
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        $guides['managing'] = $response->getContent();

        return $guides;
    }

    private function generateGuides(array $appStructure): void
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $userRoles = $this->identifyUserRoles($appStructure);
        
        foreach ($userRoles as $role) {
            $prompt = "Create a comprehensive guide for {$role} users in {$targetLang} language.

Application features: " . json_encode($appStructure['features']) . "

Include:
- Role-specific workflows
- Daily tasks and procedures
- Advanced features for this role
- Tips and best practices
- Common scenarios and solutions
- Write ALL content in {$targetLang} language (not English)";

            $response = $this->chat(new UserMessage($prompt));
            $this->saveDocument("guides/{$role}-guide.md", $response->getContent());
        }
    }

    private function generateTroubleshooting(array $appStructure): void
    {
        $docs = [
            'common-issues' => $this->generateCommonIssues($appStructure),
            'error-messages' => $this->generateErrorMessages($appStructure),
            'contact-support' => $this->generateContactSupport()
        ];

        foreach ($docs as $filename => $content) {
            $this->saveDocument("troubleshooting/{$filename}.md", $content);
        }
    }

    private function generateCommonIssues(array $appStructure): string
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $prompt = "Create a troubleshooting guide for common issues in {$targetLang} language.

Application context: " . json_encode($appStructure) . "

Include:
- Login problems and solutions
- Performance issues
- Data not saving/loading
- Permission errors
- Browser compatibility
- Step-by-step solutions
- When to contact support
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        return $response->getContent();
    }

    private function generateErrorMessages(array $appStructure): string
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $prompt = "Create a guide explaining common error messages in {$targetLang} language.

Include:
- List of common error messages
- What each error means in simple terms
- How to resolve each error
- Preventive measures
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        return $response->getContent();
    }

    private function generateContactSupport(): string
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        $prompt = "Create a 'Contact Support' page in {$targetLang} language.

Include:
- When to contact support
- What information to provide
- Support channels (email, phone, chat)
- Expected response times
- Self-service resources
- Emergency contact procedures
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        return $response->getContent();
    }

    private function generateReference(array $appStructure): void
    {
        $targetLang = $this->getLanguageInstruction($this->language);
        // Generate glossary
        $prompt = "Create a comprehensive glossary of terms used in the application in {$targetLang} language.

Application context: " . json_encode($appStructure) . "

Include:
- All technical terms explained simply
- Business-specific terminology
- Abbreviations and acronyms
- Alphabetical organization
- Cross-references where helpful
- Write ALL content in {$targetLang} language (not English)";

        $response = $this->chat(new UserMessage($prompt));
        $this->saveDocument("reference/glossary.md", $response->getContent());
    }

    private function identifyUserRoles(array $appStructure): array
    {
        // Simple role identification based on common patterns
        $roles = ['user'];
        
        if (isset($appStructure['features']['users'])) {
            $roles[] = 'admin';
        }
        
        if (isset($appStructure['features']['products'])) {
            $roles[] = 'seller';
            $roles[] = 'buyer';
        }
        
        return array_unique($roles);
    }

    private function saveDocument(string $path, string $content): void
    {
        $fullPath = base_path("docs/user/{$path}");
        $directory = dirname($fullPath);
        
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
        
        File::put($fullPath, $content);
        
        // Store in memory for cross-referencing
        $this->ragMemory->remember($path, $content, [
            'type' => 'user_documentation',
            'section' => explode('/', $path)[0] ?? 'general',
            'language' => $this->language,
            'generated_at' => now()->toIso8601String()
        ]);
    }
}