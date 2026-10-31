<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Scope;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\TenantModuleScopeResolver;
use Semitexa\Llm\Application\Service\TenantSkillScope;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Domain\Model\SkillScope;

/**
 * Exercised against the real discovery, because the thing worth pinning is the
 * decision the rule makes about THIS install's modules — a stubbed registry
 * would only prove the stub.
 */
final class TenantSkillScopeTest extends TestCase
{
    #[Test]
    public function the_operator_scope_keeps_every_discovered_skill(): void
    {
        $scope = new TenantSkillScope();
        $discovery = new ClassDiscovery();

        self::assertSame(
            count($discovery->findClassesWithAttribute(\Semitexa\Llm\Attribute\AsAiSkill::class)),
            count($scope->classesFor(SkillScope::unrestricted(), $discovery)),
        );
    }

    #[Test]
    public function a_tenant_never_receives_a_project_module_that_was_not_mapped_to_it(): void
    {
        // The rule that matters: an unmapped PROJECT module is the operator's
        // own cross-site tooling — server commands, a client registry — and
        // reading "unmapped" as "shared" is what puts those in every site
        // admin's manifest.
        $scope = new TenantSkillScope();
        $registry = new ModuleRegistry();

        $localModules = [];
        foreach ($registry->getLocalModules() as $module) {
            $localModules[(string) $module['name']] = true;
        }

        $leaked = [];
        foreach ($scope->classesFor(SkillScope::forTenant('a-tenant-that-owns-no-module')) as $class) {
            $module = $registry->getModuleNameForClass($class);
            if ($module !== null && isset($localModules[$module])) {
                $leaked[] = $class;
            }
        }

        self::assertSame([], $leaked, 'Project-module skills reached a tenant that was never mapped to them.');
    }

    #[Test]
    public function a_tenant_scope_is_a_subset_of_what_the_operator_sees(): void
    {
        $scope = new TenantSkillScope();

        $all = $scope->classesFor(SkillScope::unrestricted());
        $scoped = $scope->classesFor(SkillScope::forTenant('a-tenant-that-owns-no-module'));

        self::assertSame([], array_diff($scoped, $all));
        self::assertLessThanOrEqual(count($all), count($scoped));
    }

    #[Test]
    public function the_console_s_own_skills_survive_scoping(): void
    {
        // Notes, calendar, theme, locale and friends act on the console and the
        // session rather than on site content. Filtering them out would leave a
        // signed-in admin with a shell that can do nothing at all. Which ones
        // exist depends on the install (a bare one has none a tenant may use:
        // the stack smoke failed on asserting "some" there), so the rule is
        // pinned instead: every skill of a packaged module, not operator-only and
        // mapped to no tenant, is kept. One such skill is added to what this
        // install discovers, so the rule is exercised even where it has none,
        // with no TENANT_*_MODULES mapping in force: one naming llm would take
        // that skill out of the rule. They are put back afterwards.
        $mappings = array_filter(getenv(), static fn (string $key): bool => preg_match('/^TENANT_[A-Z0-9_]+?_MODULES$/', $key) === 1, ARRAY_FILTER_USE_KEY);
        foreach (array_keys($mappings) as $key) {
            putenv($key);
        }
        try {
            $this->assertConsoleSkillsSurvive();
        } finally {
            foreach ($mappings as $key => $value) {
                putenv($key . '=' . $value);
            }
        }
    }

    private function assertConsoleSkillsSurvive(): void
    {
        $scope = new TenantSkillScope();
        $discovery = new class extends ClassDiscovery {
            public function findClassesWithAttribute(string $attributeClass): array
            {
                return [...parent::findClassesWithAttribute($attributeClass), EverybodysSkillFixture::class];
            }
        };
        $classes = $scope->classesFor(SkillScope::forTenant('a-tenant-that-owns-no-module'), $discovery);

        $registry = new ModuleRegistry();
        $packaged = [];
        foreach (['vendor', 'composer'] as $type) {
            foreach ($registry->getModulesByType($type) as $module) {
                foreach ([$module['name'], ...$module['aliases']] as $name) {
                    $packaged[(string) $name] = true;
                }
            }
        }
        /** @var list<class-string> $all */
        $all = $discovery->findClassesWithAttribute(AsAiSkill::class);
        $consoles = array_values(array_filter(
            $all,
            static function (string $class) use ($registry, $packaged): bool {
                $module = $registry->getModuleNameForClass($class);

                return !TenantSkillScope::isOperatorOnly($class)
                    && $module !== null
                    && isset($packaged[$module])
                    && TenantModuleScopeResolver::scopesForModule($module) === [];
            },
        ));

        self::assertContains(EverybodysSkillFixture::class, $consoles, 'the added skill qualifies, so the comparison below cannot be two empty lists');
        self::assertSame($consoles, array_values(array_intersect($classes, $consoles)));
    }

    #[Test]
    public function operator_only_is_read_however_the_attribute_was_written(): void
    {
        self::assertTrue(TenantSkillScope::isOperatorOnly(OperatorOnlySkillFixture::class));
        self::assertTrue(TenantSkillScope::isOperatorOnly(PositionalOperatorOnlySkillFixture::class), 'a positional true is not missed');
        self::assertFalse(TenantSkillScope::isOperatorOnly(EverybodysSkillFixture::class));
        self::assertFalse(TenantSkillScope::isOperatorOnly(ExplicitlyEverybodysSkillFixture::class));
    }

    #[Test]
    public function no_tenant_receives_an_operator_only_skill_the_operator_does(): void
    {
        // Both fixtures sit in this package's module, which every tenant gets:
        // the everybody skill is the control, so a scope that hands the tenant
        // nothing at all cannot pass.
        $discovery = new class extends ClassDiscovery {
            public function findClassesWithAttribute(string $attributeClass): array
            {
                return [OperatorOnlySkillFixture::class, EverybodysSkillFixture::class];
            }
        };
        $scope = new TenantSkillScope();

        self::assertSame([OperatorOnlySkillFixture::class, EverybodysSkillFixture::class], $scope->classesFor(SkillScope::unrestricted(), $discovery));
        self::assertSame([EverybodysSkillFixture::class], $scope->classesFor(SkillScope::forTenant('museum'), $discovery));
    }
}

#[AsAiSkill(name: 'fixture-operator', channels: ['ui'], operatorOnly: true)]
final class OperatorOnlySkillFixture
{
}

#[AsAiSkill(true, 'positional', null, null, AiRiskLevel::Low, AiConfirmationMode::Never, false, AiArgumentPolicy::None, [], [], AiExecutionKind::DirectCommand, ['ui'], 'fixture-positional', null, null, [], true)]
final class PositionalOperatorOnlySkillFixture
{
}

#[AsAiSkill(name: 'fixture-everybody', channels: ['ui'])]
final class EverybodysSkillFixture
{
}

#[AsAiSkill(name: 'fixture-everybody-explicit', channels: ['ui'], operatorOnly: false)]
final class ExplicitlyEverybodysSkillFixture
{
}
