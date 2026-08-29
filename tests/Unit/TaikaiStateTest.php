<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\TaikaiState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie l'ordre de la frise d'avancement et la navigation `previous()`/`next()`,
 * dont dépendent `Taikai::getPreviousState()`/`getNextState()` et la machine à
 * états. Couvert jusqu'ici uniquement à travers les tests fonctionnels de
 * `TaikaiStateMachine`.
 */
#[CoversClass(TaikaiState::class)]
final class TaikaiStateTest extends TestCase
{
    public function testPositionMatchesDeclarationOrder(): void
    {
        self::assertSame(1, TaikaiState::New->position());
        self::assertSame(2, TaikaiState::Registration->position());
        self::assertSame(3, TaikaiState::Marking->position());
        self::assertSame(4, TaikaiState::TieBreak->position());
        self::assertSame(5, TaikaiState::Done->position());
    }

    public function testNextFollowsTheFrieze(): void
    {
        self::assertSame(TaikaiState::Registration, TaikaiState::New->next());
        self::assertSame(TaikaiState::Marking, TaikaiState::Registration->next());
        self::assertSame(TaikaiState::TieBreak, TaikaiState::Marking->next());
        self::assertSame(TaikaiState::Done, TaikaiState::TieBreak->next());
    }

    public function testPreviousFollowsTheFriezeBackwards(): void
    {
        self::assertSame(TaikaiState::TieBreak, TaikaiState::Done->previous());
        self::assertSame(TaikaiState::Marking, TaikaiState::TieBreak->previous());
        self::assertSame(TaikaiState::Registration, TaikaiState::Marking->previous());
        self::assertSame(TaikaiState::New, TaikaiState::Registration->previous());
    }

    public function testDoneHasNoNextState(): void
    {
        self::assertNull(TaikaiState::Done->next());
    }

    public function testNewHasNoPreviousState(): void
    {
        self::assertNull(TaikaiState::New->previous());
    }
}
