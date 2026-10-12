<?php

namespace Drupal\jfcamp_operations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\jfcamp_operations\Service\AssignmentSwapService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Camp-Leitung form for an atomic 1:1 workshop swap.
 */
final class AssignmentSwapForm extends FormBase {

  public function __construct(
    private AssignmentSwapService $assignmentSwap,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('jfcamp_operations.assignment_swap'));
  }

  public function getFormId(): string {
    return 'jfcamp_operations_assignment_swap_form';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?int $participant_id = NULL,
    ?int $slot = NULL,
    ?int $target_workshop_id = NULL,
  ): array {
    $context = $this->assignmentSwap->getSwapContext(
      (int) $participant_id,
      (int) $slot,
      (int) $target_workshop_id,
    );

    $form['#attributes']['class'][] = 'jfcamp-operation-form';
    $form['#attributes']['class'][] = 'jfcamp-operation-form--swap';

    $form['participant_id'] = ['#type' => 'hidden', '#value' => $context['participant_id']];
    $form['slot'] = ['#type' => 'hidden', '#value' => $context['slot']];
    $form['target_workshop_id'] = ['#type' => 'hidden', '#value' => $context['target_workshop_id']];

    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operation-form__header']],
      'eyebrow' => ['#markup' => '<div class="jfcamp-operation-form__eyebrow">' . $this->t('Camp-Leitung · Zuteilungstausch') . '</div>'],
      'title' => ['#markup' => '<h2 class="jfcamp-operation-form__title">' . $this->t('Tausch für Slot @slot organisieren', ['@slot' => $context['slot']]) . '</h2>'],
      'meta' => ['#markup' => '<div class="jfcamp-operation-form__meta"><strong>' . $this->t('@name', ['@name' => $context['participant_name']]) . '</strong></div>'],
    ];

    $form['notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operation-form__notice', 'is-warning']],
      'title' => ['#markup' => '<strong>' . $this->t('Zielworkshop ist voll – keine Überbuchung') . '</strong>'],
      'content' => [
        '#markup' => '<div>' . $this->t(
          '„@target“ ist in Slot @slot mit @occupied/@capacity Plätzen belegt. Deshalb wird nur ein direkter 1:1-Tausch angeboten.',
          [
            '@target' => $context['target_workshop_label'],
            '@slot' => $context['slot'],
            '@occupied' => $context['target_occupancy'],
            '@capacity' => $context['target_capacity'],
          ],
        ) . '</div>',
      ],
    ];

    $form['preview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operation-form__panel']],
      'title' => ['#markup' => '<div class="jfcamp-operation-form__section-title">' . $this->t('Geplanter Tausch') . '</div>'],
      'grid' => [
        '#markup' => '<div class="jfcamp-swap-preview">'
          . '<div class="jfcamp-swap-preview__row"><div><strong>' . $this->t('@name', ['@name' => $context['participant_name']]) . '</strong></div><div>'
          . $this->t('@old → @target', ['@old' => $context['old_workshop_label'], '@target' => $context['target_workshop_label']]) . '</div></div>'
          . '<div class="jfcamp-swap-preview__row"><div><strong>' . $this->t('Tauschperson') . '</strong></div><div>'
          . $this->t('@target → @old', ['@target' => $context['target_workshop_label'], '@old' => $context['old_workshop_label']]) . '</div></div>'
          . '</div>',
      ],
    ];

    if (!$context['candidates']) {
      $form['no_candidates'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-operation-form__notice', 'is-error']],
        'title' => ['#markup' => '<strong>' . $this->t('Kein direkter Tausch möglich') . '</strong>'],
        'content' => [
          '#markup' => '<div>' . $this->t(
            'Aktuell gibt es keine kompatible Tauschperson. Personen, die „@old“ bereits in einem anderen Slot haben, werden aus Sicherheitsgründen nicht angeboten.',
            ['@old' => $context['old_workshop_label']],
          ) . '</div>',
        ],
      ];
    }
    else {
      $form['partner'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-operation-form__panel']],
      ];
      $form['partner']['partner_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Tauschperson auswählen'),
        '#options' => $context['candidates'],
        '#empty_option' => $this->t('- Person auswählen -'),
        '#required' => TRUE,
        '#description' => $this->t(
          'Die ausgewählte Person wechselt in Slot @slot von „@target“ nach „@old“. Beide Änderungen werden in einer Transaktion gespeichert oder vollständig verworfen.',
          [
            '@slot' => $context['slot'],
            '@target' => $context['target_workshop_label'],
            '@old' => $context['old_workshop_label'],
          ],
        ),
        '#wrapper_attributes' => ['class' => ['jfcamp-operation-form__field']],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['jfcamp-operation-form__actions']],
    ];
    if ($context['candidates']) {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Tausch durchführen'),
        '#button_type' => 'primary',
      ];
    }
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Zurück zur Workshop-Auswahl'),
      '#url' => Url::fromRoute('jfcamp_operations.assignment_edit', [
        'participant_id' => $context['participant_id'],
        'slot' => $context['slot'],
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $participantId = (int) $form_state->getValue('participant_id');
    $slot = (int) $form_state->getValue('slot');
    $targetWorkshopId = (int) $form_state->getValue('target_workshop_id');
    $partnerId = (int) $form_state->getValue('partner_id');

    try {
      $result = $this->assignmentSwap->swap($participantId, $slot, $targetWorkshopId, $partnerId);
      $this->messenger()->addStatus($this->t(
        'Tausch gespeichert: @a → „@target“, @b → „@old“ (Slot @slot).',
        [
          '@a' => $result['participant_name'],
          '@b' => $result['partner_name'],
          '@target' => $result['target_workshop_label'],
          '@old' => $result['old_workshop_label'],
          '@slot' => $result['slot'],
        ],
      ));
      $form_state->setRedirect('jfcamp_operations.participant_detail', ['participant_id' => $participantId]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild(TRUE);
    }
  }

}
