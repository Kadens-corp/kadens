<?php

namespace App\Form;

use App\Enum\DistanceUnit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une distance : une valeur et son unité, m ou km, choisie à côté du
 * champ (#97). Taper « 400 » avec « km » affiché donnait 400 km sans un mot ; en
 * fractionné on parle en mètres, en sortie longue en kilomètres, et c'est à la
 * personne qui saisit de le dire.
 *
 * La base ne stocke que des mètres (unité normalisée, cf. DistanceUnit) : l'unité
 * choisie n'est pas gardée, elle se redéduit à la relecture
 * (`DistanceUnit::forValue`). L'option `unit` donne l'unité proposée sur un champ
 * vide, celle de l'activité (km pour la course et le vélo, mètres sinon).
 *
 * Un seul champ pour la vue (libellé, erreurs, `data-action` de l'enregistrement
 * automatique sur le conteneur, que l'événement `change` des deux enfants
 * traverse), rendu en ligne par le bloc `distance_widget` du thème.
 *
 * @extends AbstractType<int|null>
 */
final class DistanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var DistanceUnit $default */
        $default = $options['unit'];

        $builder
            ->add('value', TextType::class, [
                'label' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => $default->placeholder()],
            ])
            ->add('unit', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    DistanceUnit::METERS->label() => DistanceUnit::METERS->value,
                    DistanceUnit::KM->label() => DistanceUnit::KM->value,
                ],
                'attr' => ['aria-label' => 'Unité de distance'],
            ])
        ;

        $builder->addModelTransformer(new CallbackTransformer(
            static function (?int $meters) use ($default): array {
                $unit = $default->forValue($meters);

                return [
                    'value' => null === $meters ? '' : $unit->toInputValue($meters),
                    'unit' => $unit->value,
                ];
            },
            static function (mixed $data): ?int {
                if (!\is_array($data)) {
                    return null;
                }

                $unit = DistanceUnit::tryFrom((string) ($data['unit'] ?? ''))
                    ?? throw new TransformationFailedException('Unité de distance inconnue.');

                return $unit->toMeters((string) ($data['value'] ?? ''));
            },
        ));
    }

    /**
     * Le libellé pointe le champ de saisie : un champ composé n'a pas d'`id`
     * focalisable, son `<label>` n'aurait sinon aucune cible.
     *
     * @param FormInterface<int|null> $form
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['label_attr']['for'] = $view['value']->vars['id'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'unit' => DistanceUnit::METERS,
            'compound' => true,
            'invalid_message' => 'Distance invalide.',
            // Les erreurs restent sur ce champ, pas sur les enfants sans libellé.
            'error_bubbling' => false,
        ]);
        $resolver->setAllowedTypes('unit', DistanceUnit::class);
    }

    public function getBlockPrefix(): string
    {
        return 'distance';
    }
}
