<?php

declare(strict_types=1);

namespace Semitexa\Llm\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\TenantModuleScopeResolver;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Llm\Domain\Model\SkillScope;

/**
 * Builds the skill manifest one scope is allowed to see.
 *
 * A skill belongs to whichever module declares it, and modules are mapped to
 * tenants by TENANT_*_MODULES in the environment. That gives three cases, and
 * the third is the one worth stating plainly:
 *
 *  - the module is mapped to tenants → only those tenants' admins get it;
 *  - the module ships with the framework (a vendor/composer package: the OS
 *    shell's own notes, calendar, theme and locale skills) → everyone gets it,
 *    because those act on the console and the session, not on site content;
 *  - the module is a PROJECT module mapped to no tenant → the operator only.
 *
 * That last rule is the important one. Left as "unmapped means shared" — which
 * reads as the generous, obvious default — a project's own cross-site tooling
 * (server commands, the client registry, anything under src/modules that was
 * never listed for a tenant) lands in every site admin's manifest. It is
 * precisely the module nobody scoped that is most likely to be dangerous.
 */
#[AsService]
final class TenantSkillScope
{
    /** Module types that ship as packages rather than as this project's own code. */
    private const PACKAGED = ['vendor', 'composer'];

    public function manifestFor(SkillScope $scope): SkillManifest
    {
        $discovery = new ClassDiscovery();

        return (new SkillRegistry($discovery))->buildManifestFromClasses(
            $this->classesFor($scope, $discovery),
        );
    }

    /**
     * @return list<class-string>
     */
    public function classesFor(SkillScope $scope, ?ClassDiscovery $discovery = null): array
    {
        $discovery ??= new ClassDiscovery();

        /** @var list<class-string> $all */
        $all = $discovery->findClassesWithAttribute(AsAiSkill::class);

        if ($scope->unrestricted) {
            return $all;
        }

        $tenantId = (string) $scope->tenantId;
        $registry = new ModuleRegistry();
        $packaged = $this->packagedModuleNames($registry);

        $kept = [];
        foreach ($all as $class) {
            if ($this->isVisible($class, $tenantId, $registry, $packaged)) {
                $kept[] = $class;
            }
        }

        return $kept;
    }

    /**
     * @param class-string $class
     * @param array<string, true> $packaged
     */
    private function isVisible(string $class, string $tenantId, ModuleRegistry $registry, array $packaged): bool
    {
        $module = $registry->getModuleNameForClass($class);

        // A skill outside every module is project code with no module to scope
        // it by; treat it the way an unmapped project module is treated.
        if ($module === null || $module === '' || $module === 'project') {
            return false;
        }

        $tenants = TenantModuleScopeResolver::scopesForModule($module);

        if ($tenants !== []) {
            return in_array($tenantId, $tenants, true);
        }

        return isset($packaged[$module]);
    }

    /**
     * @return array<string, true>
     */
    private function packagedModuleNames(ModuleRegistry $registry): array
    {
        $names = [];

        foreach (self::PACKAGED as $type) {
            foreach ($registry->getModulesByType($type) as $module) {
                $names[(string) $module['name']] = true;

                foreach ($module['aliases'] as $alias) {
                    $names[(string) $alias] = true;
                }
            }
        }

        return $names;
    }
}
