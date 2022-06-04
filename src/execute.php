<?php
/*
    Condorcet PHP - Election manager and results calculator.
    Designed for the Condorcet method. Integrating a large number of algorithms extending Condorcet. Expandable for all types of voting systems.

    By Julien Boudry and contributors - MIT LICENSE (Please read LICENSE.txt)
    https://github.com/julien-boudry/Condorcet
*/
declare(strict_types=1);

namespace CondorcetPHP\Tarot;

use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use CondorcetPHP\Condorcet\Throwable\Internal\IntegerOverflowException as CondorcetIntegerOverflowException;
use CondorcetPHP\Condorcet\Dev\CondorcetDocumentationGenerator\CondorcetDocAttributes\InternalModulesAPI;
use CondorcetPHP\Condorcet\Dev\CondorcetDocumentationGenerator\CondorcetDocAttributes\PublicAPI;
use CondorcetPHP\Condorcet\Throwable\Internal\CondorcetInternalException;
use CondorcetPHP\Condorcet\Timer\Chrono;
use CondorcetPHP\Condorcet\Timer\Manager;
use Error;
use SplFixedArray;

require_once 'vendor/autoload.php';

// Chrone
$chronoManager = new Manager;
$chrono = new Chrono($chronoManager);

// App code

Enum Enseignes {
    case Carreau;
    case Coeur;
    case Pique;
    case Trèfle;
}

Enum CardTypes: int {
    case Atout = 1;
    case Excuse = 2;
    case Roi = 3;
    case Dame = 4;
    case Cavalier = 5;
    case Valet = 6;
    case Mineur = 7;
}

class Card {
    public function __construct (public readonly CardTypes $type, public readonly ?Enseignes $enseigne = null, public readonly ?int $displayNumber = null) {}
}

// Initier avec excuse
$cards_set = [ new Card (type: CardTypes::Excuse) ];

// Construire les atouts
for ($i = 21 ; $i > 0 ; $i--) :
    $cards_set[] = new Card (CardTypes::Atout, displayNumber: $i);
endfor;

// Construire les enseignes
foreach (Enseignes::cases() as $Enseigne) :
    // Cartes mineures
    for ($i = 10 ; $i > 0 ; $i--) :
        $cards_set[] = new Card (CardTypes::Mineur, enseigne: $Enseigne, displayNumber: $i);
    endfor;

    // Cartes nobles
    $cards_set[] = new Card (CardTypes::Roi, enseigne: $Enseigne);
    $cards_set[] = new Card (CardTypes::Dame, enseigne: $Enseigne);
    $cards_set[] = new Card (CardTypes::Cavalier, enseigne: $Enseigne);
    $cards_set[] = new Card (CardTypes::Valet, enseigne: $Enseigne);
endforeach;

(\count($cards_set) !== 78) && throw new Error;
\shuffle($cards_set);

$mainCount = 18;

echo "Number of Combinations: ".Combinations::getNumberOfCombinations(count: 78, length: $mainCount)."\n";

$b = $c = $ps = $mi = 0;
foreach (Combinations::computeGenerator(values: $cards_set, length: $mainCount) as $oneCombination) :
    $c++;

    // Compte les atouts
    $atouts_count = 0;
    $atouts_majeurs_count = 0;
    $roi_count = 0;
    $_petit = false;
    $_21 = false;
    $_excuse = false;
    foreach ($oneCombination as $oneCard) :
        if ($oneCard->type === CardTypes::Atout) :
            ++$atouts_count;

            // Check le petit
            if ($oneCard->displayNumber === 1) :
                $_petit = true;

            // Check le 21
            elseif ($oneCard->displayNumber === 21) :
                $_21 = true;
                $atouts_majeurs_count++;

            // Check atouts majeurs
            elseif ($oneCard->displayNumber > 10):
                $atouts_majeurs_count++;
            endif;

        elseif ($oneCard->type === CardTypes::Excuse) :
            $_excuse = true;

        elseif ($oneCard->type === CardTypes::Roi):
            $roi_count++;
        endif;
    endforeach;

    // Check petit seul et annule la boucle
    if ($_petit && $atouts_count === 1) :
        ++$ps;
        continue;
    endif;

    // Ckeck Main imparable
    $isMainImparable = false;
    if ($atouts_majeurs_count >= 10 && $_21) : # Au moins 10 atouts hors excuse, et le 21

        $cards_remains_count = $mainCount - $atouts_count - $roi_count;
        $_excuse && $cards_remains_count--;

        if ($cards_remains_count > 7) : # Seulement 10 atout majeurs et pas d'excuse => on anule tout
            goto mainImparableRevelation;
        endif;

        $isMainImparable = true; # Présomption

        // Chaque carte doit-être dans une suite
        foreach ($oneCombination as $oneCard) :
            if ( $oneCard->type->value > 3  ) : # Cherche les Dame, Cavalier, Valet, Mineurs / Et ignore les rois

                # Si les dames, cavaliers, valets n'ont pas leur carte supérieur dans la même couleur => on annule tout
                # Si c'est une carte mineur, un valet de la même couleur doit-être présent (qui aura lui-même besoin de son cavalier, lui-même de sa dame, elle-même de son roi)
                if (haveCard($combination, CardTypes::from($oneCard->type->value -1), $oneCard->enseigne)) :
                    $isMainImparable = false; # Annulation
                    goto mainImparableRevelation;
                endif;
            endif;
        endforeach;

    endif;

    //
    mainImparableRevelation:
        $isMainImparable && $mi++;


    if (++$b >= 10_000) :
        break;
    endif;
endforeach;

echo 'Itérations: '.$c."\n";
echo 'Mains valide: '.$b."\n";
echo 'Petits seul: '.$ps."\n";
echo 'Main Imparable: '.$mi."\n";

unset($chrono);
echo 'Computation timer: '.$chronoManager->getGlobalTimer()." seconds\n";

// Lib Code

function haveCard (array $combination, CardTypes $cardType, ?Enseignes $enseigne): bool
{
    foreach ($combination as $oneCard) :
        if ($oneCard->type === $cardType && $oneCard->enseigne === $enseigne) :
            return true;
        endif;
    endforeach;

    return false;
}

#[InternalModulesAPI]
class Combinations
{

    #[PublicAPI]
    static bool $useBigIntegerIfAvailable = true;

    public static function getNumberOfCombinations (int $count, int $length): int
    {
        if ($count < 1 || $length < 1 || $count < $length) :
            throw new CondorcetInternalException('Parameters invalid');
        endif;

        if (self::$useBigIntegerIfAvailable && \class_exists('Brick\Math\BigInteger')) :
            $a = BigInteger::of(1);
            for ($i = $count ; $i > ($count - $length) ; $i--) :
                $a = $a->multipliedBy($i);
            endfor;

            $b = BigInteger::of(1);
            for ($i = $length ; $i > 0 ; $i--) :
                $b = $b->multipliedBy($i);
            endfor;

            try {
                return $a->dividedBy($b)->toInt();
            } catch (IntegerOverflowException $e) {
                throw new CondorcetIntegerOverflowException($e->getMessage());
            }
        else :
            $a = 1;
            for ($i = $count ; $i > ($count - $length) ; $i--) :
                $a = $a * $i;
            endfor;

            $b = 1;
            for ($i = $length ; $i > 0 ; $i--) :
                $b = $b * $i;
            endfor;

            if (\is_float($a) || \is_float($b)) :
                throw new CondorcetIntegerOverflowException;
            else :
                return (int) ($a / $b);
            endif;
        endif;
    }

    public static function compute (array $values, int $length, array $append_before = []): SplFixedArray
    {
        $count = \count($values);
        $r = new SplFixedArray(self::getNumberOfCombinations($count, $length));

        $arrKey = 0;
        foreach (self::computeGenerator($values, $length, $append_before) as $oneCombination) :
            $r[$arrKey++] = $oneCombination;
        endforeach;

        return $r;
    }

    public static function computeGenerator (array $values, int $length, array $append_before = []): \Generator
    {
        $count = \count($values);
        $size = 2 ** $count;
        $keys = \array_keys($values);

        for ($i = 0; $i < $size; $i++) :
            $b = \sprintf("%0" . $count . "b", $i);
            $out = [];

            for ($j = 0; $j < $count; $j++) :
                if ($b[$j] === '1') :
                    $out[$keys[$j]] = $values[$keys[$j]];
                endif;
            endfor;

            if (count($out) === $length) :
                 yield \array_values(\array_merge($append_before, $out));
            endif;
        endfor;
    }
}
