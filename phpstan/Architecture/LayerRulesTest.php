<?php

declare(strict_types=1);

namespace App\PhpStan\Architecture;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule as RuleBuilder;
use PHPat\Test\PHPat;

/**
 * Layer and module dependency rules (T-ARCH-01, T-ARCH-02, T-ARCH-02b).
 *
 * Registered in phpstan.neon with the "phpat.test" tag.
 */
final class LayerRulesTest
{
    private const string ANY_MODULE_DOMAIN = '/^App\\\\(Shared|Ordering|Inventory|Payment)\\\\Domain(\\\\|$)/';
    private const string ANY_MODULE_APPLICATION = '/^App\\\\(Shared|Ordering|Inventory|Payment)\\\\Application(\\\\|$)/';
    private const string ANY_MODULE_INFRASTRUCTURE = '/^App\\\\(Shared|Ordering|Inventory|Payment|Saga)\\\\Infrastructure(\\\\|$)/';

    /** Modules between which Domain and Application imports are forbidden. */
    private const array ISOLATED_MODULES = ['Ordering', 'Inventory', 'Payment'];

    /** T-ARCH-01: Domain depends only on PHP core and Shared\Domain. */
    #[TestRule]
    public function domainDependsOnlyOnItself(): RuleBuilder
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ANY_MODULE_DOMAIN, true))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::ANY_MODULE_APPLICATION, true),
                Selector::inNamespace(self::ANY_MODULE_INFRASTRUCTURE, true),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Temporal'),
                Selector::inNamespace('Psr'),
            )
            ->because('T-ARCH-01: Domain must not depend on outer layers or frameworks');
    }

    /** T-ARCH-02b: Application depends on Domain and Psr\Clock / Psr\Log only. */
    #[TestRule]
    public function applicationDoesNotDependOnInfrastructureOrFrameworks(): RuleBuilder
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ANY_MODULE_APPLICATION, true))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::ANY_MODULE_INFRASTRUCTURE, true),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Temporal'),
            )
            ->because('T-ARCH-02b: Application must not depend on Infrastructure or frameworks');
    }

    /**
     * T-ARCH-02: Domain and Application of one module do not import another module.
     *
     * @return iterable<string, RuleBuilder>
     */
    #[TestRule]
    public function modulesAreIsolated(): iterable
    {
        foreach (self::ISOLATED_MODULES as $module) {
            foreach (self::ISOLATED_MODULES as $other) {
                if ($module === $other) {
                    continue;
                }

                yield $module . ' -> ' . $other => PHPat::rule()
                    ->classes(...$this->domainAndApplicationOf($module))
                    ->shouldNotDependOn()
                    ->classes(Selector::inNamespace('App\\' . $other))
                    ->because('T-ARCH-02: modules communicate only through the saga and events');
            }
        }
    }

    /**
     * @return list<SelectorInterface>
     */
    private function domainAndApplicationOf(string $module): array
    {
        return [
            Selector::inNamespace('App\\' . $module . '\\Domain'),
            Selector::inNamespace('App\\' . $module . '\\Application'),
        ];
    }
}
