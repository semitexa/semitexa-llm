<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Scope;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Domain\Model\SkillScope;

final class SkillScopeTest extends TestCase
{
    #[Test]
    public function the_operator_scope_names_no_tenant_and_says_so(): void
    {
        $scope = SkillScope::unrestricted();

        self::assertTrue($scope->unrestricted);
        self::assertNull($scope->tenantId);
    }

    #[Test]
    public function a_tenant_scope_is_bound_and_not_unrestricted(): void
    {
        $scope = SkillScope::forTenant('regmus');

        self::assertFalse($scope->unrestricted);
        self::assertSame('regmus', $scope->tenantId);
    }

    #[Test]
    public function an_empty_tenant_id_is_refused_rather_than_read_as_everything(): void
    {
        // The dangerous reading of an empty tenant is "no restriction". Making
        // it throw means a missing tenant surfaces as a failure at the call
        // site instead of as silent full access.
        $this->expectException(\InvalidArgumentException::class);

        SkillScope::forTenant('   ');
    }
}
