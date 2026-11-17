<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une charge en kg, au format français (#97) : « 70 », « 32,5 »,
 * « 1,25 ». NumberType la rendait selon la locale de l'app (anglaise), avec ses
 * deux décimales : « 70.00 » dans le compositeur, quand partout ailleurs on lit
 * « 32,5 kg ». Le point reste accepté à la saisie, par habitude du clavier.
 *
 * Même patron que DistanceType et PaceType : parent TextType, conversion par un
 * CallbackTransformer, kg en base (unité normalisée).
 *
 * @extends AbstractType<float|null>
 */
final class WeightType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?float $kg): string => null === $kg ? '' : self::format($kg),
            static fn (?string $text): ?float => self::parse($text),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('invalid_message', 'Charge invalide.');
        // Clavier numérique avec virgule sur mobile.
        $resolver->setDefault('attr', ['inputmode' => 'decimal']);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    /** 70.0 -> « 70 », 32.5 -> « 32,5 », 1.25 -> « 1,25 » (au plus deux décimales). */
    public static function format(float $kg): string
    {
        return rtrim(rtrim(number_format($kg, 2, ',', ''), '0'), ',');
    }

    private static function parse(?string $text): ?float
    {
        $text = str_replace([' ', "\u{a0}", "\u{202f}"], '', trim((string) $text));
        if ('' === $text) {
            return null;
        }

        $normalized = str_replace(',', '.', $text);
        if (!is_numeric($normalized) || (float) $normalized < 0) {
            throw new TransformationFailedException('Charge invalide.');
        }

        return round((float) $normalized, 2);
    }
}
