<?php
/*
    By Julien Boudry - MIT LICENSE (Please read LICENSE.txt)
    https://github.com/julien-boudry/Condorcet
*/
declare(strict_types=1);

namespace JulienBoudry\Tarot;

use Brick\Math\{BigDecimal, BigInteger, RoundingMode};
use CondorcetPHP\Condorcet\Throwable\Internal\{IntegerOverflowException as CondorcetIntegerOverflowException, CondorcetInternalException};
use CondorcetPHP\Condorcet\Timer\{Chrono, Manager};

require_once 'vendor/autoload.php';

// Config
const MAIN_LENGTH = 18; # Nombre de carte par main
const ITERATION = 10_000_000_000;

// Chrono
$chronoManager = new Manager;
$chrono = new Chrono($chronoManager);

// Structure de données

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
        public function __construct (
            public readonly CardTypes $type,
            public readonly ?Enseignes $enseigne = null,
            public readonly ?int $displayNumber = null
        ) {}
    }

// Construction du deck

    # Initier avec excuse
    $card_deck = [ new Card (type: CardTypes::Excuse) ];

    # Construire les atouts
    for ($i = 21 ; $i > 0 ; $i--) :
        $card_deck[] = new Card (CardTypes::Atout, displayNumber: $i);
    endfor;

    # Construire les enseignes
    foreach (Enseignes::cases() as $Enseigne) :
        # Cartes mineures
        for ($i = 10 ; $i > 0 ; $i--) :
            $card_deck[] = new Card (CardTypes::Mineur, enseigne: $Enseigne, displayNumber: $i);
        endfor;

        # Cartes nobles
        $card_deck[] = new Card (CardTypes::Roi, enseigne: $Enseigne);
        $card_deck[] = new Card (CardTypes::Dame, enseigne: $Enseigne);
        $card_deck[] = new Card (CardTypes::Cavalier, enseigne: $Enseigne);
        $card_deck[] = new Card (CardTypes::Valet, enseigne: $Enseigne);
    endforeach;

    (\count($card_deck) !== 78) && throw new \Error; # Dev test


// Code Applicatif

    $oneCombination = getShuffleGameWithTrueCryptographicRandomGeneratorOrThatWeLikeToBelieveTrue($card_deck, MAIN_LENGTH);

    $vm = $c = $ps = $mi = 0;
    while ($vm < ITERATION) :
        $c++;

        # Mélange du jeu et main aléatoire
        \shuffle($card_deck);
        $oneCombination = \array_slice($card_deck, 0, MAIN_LENGTH, false);

        # Compte les atouts
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

                // Check atouts majeurs, aouts > 10
                elseif ($oneCard->displayNumber > 10):
                    $atouts_majeurs_count++;
                endif;

            elseif ($oneCard->type === CardTypes::Excuse) :
                $_excuse = true;

            elseif ($oneCard->type === CardTypes::Roi):
                $roi_count++;
            endif;
        endforeach;

        # Check petit seul et annule la boucle
        if ($_petit && $atouts_count === 1) :
            ++$ps;
            continue;
        endif;

        // Ckeck Main imparable
        $isMainImparable = false;
        if ($atouts_majeurs_count >= 10 && $_21) : # Au moins 10 atouts hors excuse, et le 21

            $cards_remains_count = MAIN_LENGTH - $atouts_count - $roi_count;
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
                    if (haveCard($oneCombination, CardTypes::from($oneCard->type->value -1), $oneCard->enseigne)) :
                        $isMainImparable = false; # Annulation
                        goto mainImparableRevelation;
                    endif;
                endif;
            endforeach;

        endif;

        // La main est-elle vraiment imparable ?
        mainImparableRevelation:
            $isMainImparable && $mi++;

        $vm++;
    endwhile;

    $c = BigInteger::of($c);
    $vm = BigInteger::of($vm);

    $nc = Combinations::getNumberOfCombinations(count: 78, length: MAIN_LENGTH);
    echo "Nombre de combinaisons théoriques : ".formatBigInteger($nc)."\n";
    echo 'Nombre de combinaisons aléatoires testées : '.formatBigInteger($c)."\n";

    echo "\n";

    echo 'Petits seul: '.$ps."\n";
    $ps_rate = BigDecimal::of($ps)->dividedBy($vm, 50, RoundingMode::HALF_DOWN);
    echo 'Taux de petit sec par main distribuée : '. ((string) $ps_rate->multipliedBy(100)->toScale(6, RoundingMode::HALF_DOWN))."%\n";
    echo 'Soit un taux de '. ((string) $ps_rate->multipliedBy(4)->multipliedBy(100)->toScale(5, RoundingMode::HALF_DOWN))."% par distribution (4 joueurs)\n";

    $ps_t = $ps_rate->multipliedBy($nc);
    $ps_t = $ps_t->toScale(0, RoundingMode::HALF_DOWN)->toBigInteger();
    echo 'Estimation du nombre de petit sec possibles : '. formatBigInteger($ps_t) .' sur un total de '. formatBigInteger($nc) ." mains possibles\n";


    echo "\n";

    echo 'Mains valide (itérations) : '.formatBigInteger($vm)."\n";

    echo "\n";

    echo 'Main Imparable : '.$mi."\n";

    $mi_rate = BigDecimal::of($mi)->dividedBy($vm, 50, RoundingMode::HALF_DOWN);
    echo 'Taux de Mains Imparables (par mains valides) : '. ((string) $mi_rate->multipliedBy(100)->toScale(7,RoundingMode::HALF_DOWN))."%\n";

    $mi_t = $mi_rate->multipliedBy($nc);
    $mi_t = $mi_t->toScale(0, RoundingMode::HALF_DOWN)->toBigInteger();
    echo 'Estimation du nombre de mains imparables possibles : '. formatBigInteger($mi_t) .' sur un total de '. formatBigInteger($nc->minus($ps_t)) ." mains valides possibles\n";

    echo "\n";

    unset($chrono);
    echo 'Computation timer: '.round($chronoManager->getGlobalTimer(),2)." seconds\n";
    echo 'Performance: '.\number_format(round($c->toInt() / $chronoManager->getGlobalTimer(),2), 0, ',', ' ')." combinaisons par secondes.";

// Lib Code

    function haveCard (array $oneCombination, CardTypes $cardType, ?Enseignes $enseigne): bool
    {
        foreach ($oneCombination as $oneCard) :
            if ($oneCard->type === $cardType && $oneCard->enseigne === $enseigne) :
                return true;
            endif;
        endforeach;

        return false;
    }

    function getShuffleGameWithTrueCryptographicRandomGeneratorOrThatWeLikeToBelieveTrue (array $arr, int $length): array
    {
        $r = [];
        $done = [];

        while (\count($r) < $length) :
            $t = \random_int(0, 77);

            if (!in_array($t, $done, true)) :
                $r[] = $arr[$t];
            endif;
        endwhile;

        return $r;
    }

    function formatBigInteger (BigInteger $number): string
    {
        $asString = (string) $number;
        $arr = \mb_str_split($asString, 1);
        $arr = \array_reverse($arr);

        $asString = reset($arr);

        $d = 0;
        while ($d !== \count($arr)) :
            $n = next($arr);

            if (++$d % 3 === 0 && $n !== false) :
                $asString = '_'.$asString;
            endif;

            $asString = $n.$asString;
        endwhile;

        return $asString;
    }

    class Combinations # From Condorcet PHP, with a few twists
    {
        static bool $useBigIntegerIfAvailable = true;

        public static function getNumberOfCombinations (int $count, int $length): BigInteger
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

                return $a->dividedBy($b);

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
}
