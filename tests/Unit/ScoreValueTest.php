<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\ScoreValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie que la comparaison des scores reproduit celle de `Score::ScoreValue`
 * côté Rails : on départage d'abord sur les points, puis sur les touchés.
 */
#[CoversClass(ScoreValue::class)]
final class ScoreValueTest extends TestCase
{
    public function testComparesOnValueBeforeHits(): void
    {
        $moreValueFewerHits = new ScoreValue(hits: 2, value: 20);
        $lessValueMoreHits = new ScoreValue(hits: 5, value: 15);

        self::assertGreaterThan(0, $moreValueFewerHits->compareTo($lessValueMoreHits));
    }

    public function testFallsBackOnHitsWhenValuesAreEqual(): void
    {
        $more = new ScoreValue(hits: 6, value: 12);
        $fewer = new ScoreValue(hits: 4, value: 12);

        self::assertGreaterThan(0, $more->compareTo($fewer));
        self::assertLessThan(0, $fewer->compareTo($more));
    }

    public function testEqualScoresCompareEqual(): void
    {
        $a = new ScoreValue(hits: 3, value: 9);
        $b = new ScoreValue(hits: 3, value: 9);

        self::assertSame(0, $a->compareTo($b));
        self::assertTrue($a->equals($b));
    }

    /**
     * En kinteki la valeur est nulle : elle doit être traitée comme zéro pour
     * le regroupement, comme le `hash` du modèle Rails.
     */
    public function testNullValueGroupsWithZeroValue(): void
    {
        $nullValue = new ScoreValue(hits: 4);
        $zeroValue = new ScoreValue(hits: 4, value: 0);

        self::assertSame($nullValue->groupKey(), $zeroValue->groupKey());
        self::assertSame(0, $nullValue->compareTo($zeroValue));
    }

    public function testTiedScoresShareGroupKey(): void
    {
        $a = new ScoreValue(hits: 3, value: 9);
        $b = new ScoreValue(hits: 3, value: 9);
        $c = new ScoreValue(hits: 4, value: 9);

        self::assertSame($a->groupKey(), $b->groupKey());
        self::assertNotSame($a->groupKey(), $c->groupKey());
    }

    public function testPlusSumsBothComponents(): void
    {
        $total = new ScoreValue(hits: 2, value: 7)
            ->plus(new ScoreValue(hits: 3, value: 10));

        self::assertSame(5, $total->hits);
        self::assertSame(17, $total->value);
    }

    /** Sans valeur des deux côtés, la somme reste sans valeur (cas kinteki). */
    public function testPlusKeepsNullValueWhenNeitherSideHasOne(): void
    {
        $total = new ScoreValue(hits: 2)->plus(new ScoreValue(hits: 3));

        self::assertSame(5, $total->hits);
        self::assertNull($total->value);
    }
}
