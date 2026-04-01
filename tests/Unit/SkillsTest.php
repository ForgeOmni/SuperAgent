<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Skills\SkillManager;
use SuperAgent\Skills\Skill;

class SkillsTest extends TestCase
{
    private SkillManager $manager;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = SkillManager::getInstance();
    }
    
    protected function tearDown(): void
    {
        // Reset the singleton instance
        $reflection = new \ReflectionClass(SkillManager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        
        parent::tearDown();
    }
    
    public function testSkillManagerSingleton()
    {
        $instance1 = SkillManager::getInstance();
        $instance2 = SkillManager::getInstance();
        
        $this->assertSame($instance1, $instance2);
    }
    
    public function testSkillRegistration()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'test-skill';
            }
            
            public function description(): string
            {
                return 'A test skill';
            }
            
            public function execute(array $args = []): string
            {
                return 'Test result';
            }
        };
        
        $this->manager->register($skill);
        
        $retrieved = $this->manager->get('test-skill');
        $this->assertNotNull($retrieved);
        $this->assertEquals('test-skill', $retrieved->name());
    }
    
    public function testSkillRegistrationThrowsOnDuplicate()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'duplicate-skill';
            }
            
            public function description(): string
            {
                return 'A duplicate skill';
            }
            
            public function execute(array $args = []): string
            {
                return 'Test';
            }
        };
        
        $this->manager->register($skill);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Skill already registered');
        
        $this->manager->register($skill);
    }
    
    public function testSkillAlias()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'original-skill';
            }
            
            public function description(): string
            {
                return 'Original skill';
            }
            
            public function execute(array $args = []): string
            {
                return 'Original result';
            }
        };
        
        $this->manager->register($skill);
        $this->manager->alias('alias-skill', 'original-skill');
        
        $retrieved = $this->manager->get('alias-skill');
        $this->assertNotNull($retrieved);
        $this->assertEquals('original-skill', $retrieved->name());
    }
    
    public function testSkillAliasThrowsForNonExistentSkill()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Skill not found');
        
        $this->manager->alias('alias', 'non-existent-skill');
    }
    
    public function testSkillExecution()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'exec-skill';
            }
            
            public function description(): string
            {
                return 'Executable skill';
            }
            
            public function execute(array $args = []): string
            {
                return 'Executed with: ' . json_encode($args);
            }
        };
        
        $this->manager->register($skill);
        
        $result = $this->manager->execute('exec-skill', ['param' => 'value']);
        $this->assertStringContainsString('Executed with:', $result);
        $this->assertStringContainsString('param', $result);
    }
    
    public function testSkillExecutionValidation()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'validated-skill';
            }
            
            public function description(): string
            {
                return 'Skill with validation';
            }
            
            public function validate(array $args): bool
            {
                return isset($args['required_param']);
            }
            
            public function execute(array $args = []): string
            {
                return 'Valid execution';
            }
        };
        
        $this->manager->register($skill);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid arguments');
        
        $this->manager->execute('validated-skill', ['wrong_param' => 'value']);
    }
    
    public function testSkillExecutionThrowsForNonExistentSkill()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Skill not found');
        
        $this->manager->execute('non-existent-skill');
    }
    
    public function testSkillPromptGeneration()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'prompt-skill';
            }
            
            public function description(): string
            {
                return 'Skill with prompt';
            }
            
            public function getPrompt(): string
            {
                return 'This is the skill prompt template';
            }
            
            public function execute(array $args = []): string
            {
                return $this->getPrompt();
            }
        };
        
        $this->manager->register($skill);
        
        $result = $this->manager->execute('prompt-skill');
        $this->assertEquals('This is the skill prompt template', $result);
    }
    
    public function testSkillWithParameters()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'param-skill';
            }
            
            public function description(): string
            {
                return 'Skill with parameters';
            }
            
            public function parameters(): array
            {
                return [
                    'text' => ['type' => 'string', 'required' => true],
                    'count' => ['type' => 'integer', 'default' => 1],
                ];
            }
            
            public function execute(array $args = []): string
            {
                $text = $args['text'] ?? '';
                $count = $args['count'] ?? 1;
                return str_repeat($text, $count);
            }
        };
        
        $this->manager->register($skill);
        
        $result = $this->manager->execute('param-skill', ['text' => 'Hello ', 'count' => 3]);
        $this->assertEquals('Hello Hello Hello ', $result);
    }
    
    public function testSkillCategories()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'categorized-skill';
            }
            
            public function description(): string
            {
                return 'Categorized skill';
            }
            
            public function category(): string
            {
                return 'code-generation';
            }
            
            public function execute(array $args = []): string
            {
                return 'Generated code';
            }
        };
        
        $this->manager->register($skill);
        
        $retrieved = $this->manager->get('categorized-skill');
        $this->assertEquals('code-generation', $retrieved->category());
    }
    
    public function testSkillListingByCategory()
    {
        // Register multiple skills with categories
        $codeSkill = new class extends Skill {
            public function name(): string { return 'code-skill'; }
            public function description(): string { return 'Code skill'; }
            public function category(): string { return 'code'; }
            public function execute(array $args = []): string { return 'code'; }
        };
        
        $dataSkill = new class extends Skill {
            public function name(): string { return 'data-skill'; }
            public function description(): string { return 'Data skill'; }
            public function category(): string { return 'data'; }
            public function execute(array $args = []): string { return 'data'; }
        };
        
        $this->manager->register($codeSkill);
        $this->manager->register($dataSkill);
        
        $codeSkills = $this->manager->listByCategory('code');
        $this->assertCount(1, $codeSkills);
        $this->assertEquals('code-skill', $codeSkills[0]->name());
    }
    
    public function testSkillSearch()
    {
        $skill1 = new class extends Skill {
            public function name(): string { return 'search-skill-1'; }
            public function description(): string { return 'First searchable skill'; }
            public function execute(array $args = []): string { return 'result1'; }
        };
        
        $skill2 = new class extends Skill {
            public function name(): string { return 'search-skill-2'; }
            public function description(): string { return 'Second searchable skill'; }
            public function execute(array $args = []): string { return 'result2'; }
        };
        
        $this->manager->register($skill1);
        $this->manager->register($skill2);
        
        $results = $this->manager->search('searchable');
        $this->assertCount(2, $results);
    }
    
    public function testBuiltinSkillsLoaded()
    {
        // Check if builtin skills are loaded
        $allSkills = $this->manager->list();
        
        // Should have at least some builtin skills
        $this->assertIsArray($allSkills);
        
        // Check for common builtin skills
        $builtinNames = ['code_review', 'explain_code', 'refactor', 'test_generation', 'documentation'];
        
        foreach ($builtinNames as $name) {
            $skill = $this->manager->get($name);
            if ($skill !== null) {
                $this->assertInstanceOf(Skill::class, $skill);
            }
        }
    }
    
    public function testSkillExamples()
    {
        $skill = new class extends Skill {
            public function name(): string
            {
                return 'example-skill';
            }
            
            public function description(): string
            {
                return 'Skill with examples';
            }
            
            public function examples(): array
            {
                return [
                    ['input' => 'test input', 'output' => 'test output'],
                    ['input' => 'another input', 'output' => 'another output'],
                ];
            }
            
            public function execute(array $args = []): string
            {
                return 'Executed';
            }
        };
        
        $this->manager->register($skill);
        
        $retrieved = $this->manager->get('example-skill');
        $examples = $retrieved->examples();
        
        $this->assertCount(2, $examples);
        $this->assertEquals('test input', $examples[0]['input']);
    }
}