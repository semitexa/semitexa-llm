<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Scope;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Llm\Application\Service\TenantSkillScope;
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
        // signed-in admin with a shell that can do nothing at all.
        $scope = new TenantSkillScope();
        $classes = $scope->classesFor(SkillScope::forTenant('a-tenant-that-owns-no-module'));

        $packaged = array_filter(
            $classes,
            static fn (string $class): bool => str_starts_with($class, 'Semitexa\\'),
        );

        self::assertNotSame([], $packaged);
    }
}
