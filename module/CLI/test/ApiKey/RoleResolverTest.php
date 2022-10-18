<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\ApiKey;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\CLI\ApiKey\RoleResolver;
use Shlinkio\Shlink\CLI\Exception\InvalidRoleConfigException;
use Shlinkio\Shlink\Core\Domain\DomainServiceInterface;
use Shlinkio\Shlink\Core\Domain\Entity\Domain;
use Shlinkio\Shlink\Rest\ApiKey\Model\RoleDefinition;
use Shlinkio\Shlink\Rest\ApiKey\Role;
use Symfony\Component\Console\Input\InputInterface;

use function Functional\map;

class RoleResolverTest extends TestCase
{
    private RoleResolver $resolver;
    private MockObject $domainService;

    protected function setUp(): void
    {
        $this->domainService = $this->createMock(DomainServiceInterface::class);
        $this->resolver = new RoleResolver($this->domainService, 'default.com');
    }

    /**
     * @test
     * @dataProvider provideRoles
     */
    public function properRolesAreResolvedBasedOnInput(
        InputInterface $input,
        array $expectedRoles,
        int $expectedDomainCalls,
    ): void {
        $this->domainService
            ->expects($this->exactly($expectedDomainCalls))
            ->method('getOrCreate')
            ->with($this->equalTo('example.com'))
            ->willReturn(Domain::withAuthority('example.com')->setId('1'));

        $result = $this->resolver->determineRoles($input);

        self::assertEquals($expectedRoles, $result);
    }

    public function provideRoles(): iterable
    {
        $domain = Domain::withAuthority('example.com')->setId('1');
        $buildInput = function (array $definition): InputInterface {
            $input = $this->createStub(InputInterface::class);
            $input->method('getOption')->willReturnMap(
                map($definition, static fn (mixed $returnValue, string $param) => [$param, $returnValue]),
            );

            return $input;
        };

        yield 'no roles' => [
            $buildInput([Role::DOMAIN_SPECIFIC->paramName() => null, Role::AUTHORED_SHORT_URLS->paramName() => false]),
            [],
            0,
        ];
        yield 'domain role only' => [
            $buildInput(
                [Role::DOMAIN_SPECIFIC->paramName() => 'example.com', Role::AUTHORED_SHORT_URLS->paramName() => false],
            ),
            [RoleDefinition::forDomain($domain)],
            1,
        ];
        yield 'false domain role' => [
            $buildInput([Role::DOMAIN_SPECIFIC->paramName() => false]),
            [],
            0,
        ];
        yield 'true domain role' => [
            $buildInput([Role::DOMAIN_SPECIFIC->paramName() => true]),
            [],
            0,
        ];
        yield 'string array domain role' => [
            $buildInput([Role::DOMAIN_SPECIFIC->paramName() => ['foo', 'bar']]),
            [],
            0,
        ];
        yield 'author role only' => [
            $buildInput([Role::DOMAIN_SPECIFIC->paramName() => null, Role::AUTHORED_SHORT_URLS->paramName() => true]),
            [RoleDefinition::forAuthoredShortUrls()],
            0,
        ];
        yield 'both roles' => [
            $buildInput(
                [Role::DOMAIN_SPECIFIC->paramName() => 'example.com', Role::AUTHORED_SHORT_URLS->paramName() => true],
            ),
            [RoleDefinition::forAuthoredShortUrls(), RoleDefinition::forDomain($domain)],
            1,
        ];
    }

    /** @test */
    public function exceptionIsThrownWhenTryingToAddDomainOnlyLinkedToDefaultDomain(): void
    {
        $input = $this->createStub(InputInterface::class);
        $input
            ->method('getOption')
            ->willReturnMap([
                [Role::DOMAIN_SPECIFIC->paramName(), 'default.com'],
                [Role::AUTHORED_SHORT_URLS->paramName(), null],
            ]);

        $this->expectException(InvalidRoleConfigException::class);

        $this->resolver->determineRoles($input);
    }
}
