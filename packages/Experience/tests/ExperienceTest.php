<?php
use PHPUnit\Framework\TestCase;
use ConvertSdk\Experience\ExperienceManager;
use ConvertSdk\Data\DataManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Api\ApiManager;
use ConvertSdk\Logger\LogManager;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Config\Config;

class ExperienceTest extends TestCase
{
    private $experienceManager;
    private $dataManager;
    private $eventManager;
    private $apiManager;
    private $logManager;
    
    private $visitorId = 'XXX';
    private $configuration;
    
    public function setUp(): void
    {
        // Setup configuration and dependencies
        $this->configuration = [
            'sdkKey' => '100414055/100415443',
            'data' => [
                'account_id' => '100414055',
                'project' => [
                    'id' => '100415443',
                    'name' => 'Project #100415443'
                ]
            ]
        ];

        // Initialize dependencies
        $this->logManager = new LogManager();
        $this->eventManager = new EventManager($this->configuration, ['loggerManager' => $this->logManager]);
        $this->apiManager = new ApiManager($this->configuration, ['eventManager' => $this->eventManager]);
        $this->dataManager = new DataManager($this->configuration, [
          'apiManager' => $this->apiManager,
          'loggerManager' => $this->logManager,
          'eventManager' => $this->eventManager  // Add this line
      ]);

        // Initialize ExperienceManager
        $this->experienceManager = new ExperienceManager($this->configuration, [
            'dataManager' => $this->dataManager
        ]);
    }

    public function testExperienceManagerInitialization()
    {
        $this->assertInstanceOf(ExperienceManager::class, $this->experienceManager);
    }

    public function testGetList()
    {
        // Simulating the behavior of getList method
        $experiences = $this->experienceManager->getList();
        $this->assertIsArray($experiences);
        $this->assertNotEmpty($experiences); // Assuming the configuration contains at least one experience
    }

    public function testGetExperienceByKey()
    {
        $experienceKey = 'test-experience-key';
        $experience = $this->experienceManager->getExperience($experienceKey);
        $this->assertIsArray($experience);
        $this->assertArrayHasKey('key', $experience);
        $this->assertEquals($experienceKey, $experience['key']);
    }

    public function testSelectVariation()
    {
        // Example for testing selectVariation
        $experienceKey = 'test-experience-key';
        $attributes = [
            'visitorProperties' => ['varName3' => 'something'],
            'locationProperties' => ['url' => 'https://convert.com/']
        ];
        
        $variation = $this->experienceManager->selectVariation($this->visitorId, $experienceKey, $attributes);

        $this->assertIsArray($variation);
        $this->assertArrayHasKey('experienceKey', $variation);
        $this->assertEquals($experienceKey, $variation['experienceKey']);
    }

    public function testGetVariation()
    {
        $experienceKey = 'test-experience-key';
        $variationKey = 'test-variation-key';
        $variation = $this->experienceManager->getVariation($experienceKey, $variationKey);
        
        $this->assertIsArray($variation);
        $this->assertArrayHasKey('key', $variation);
        $this->assertEquals($variationKey, $variation['key']);
    }

    public function testSelectVariations()
    {
        // Example for testing selectVariations
        $attributes = [
            'visitorProperties' => ['varName3' => 'something'],
            'locationProperties' => ['url' => 'https://convert.com/']
        ];
        
        $variations = $this->experienceManager->selectVariations($this->visitorId, $attributes);
        
        $this->assertIsArray($variations);
        $this->assertNotEmpty($variations); // Assuming at least one variation is returned
    }

    public function tearDown(): void
    {
        // Clean up after each test if necessary
    }
}
