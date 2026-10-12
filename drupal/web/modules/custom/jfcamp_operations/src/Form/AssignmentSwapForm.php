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

    $form['participant_id'] = ['#type' => 'hidden', '#value' => $context['participant_id']];
    $form['slot'] = ['#type' => 'hidden', '#value' => $context['slot']];
    $form['target_workshop_id'] = ['#type' => 'hidden', '#value' => $context['target_workshop_id']];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'content' => [
        '#markup' => $this->t(
          '„@target“ ist in Slot @slot voll (@occupied/@capacity). Es wird nichts überbucht. Stattdessen können zwei Teilnehmende ihre Workshops in diesem Slot direkt tauschen.',
          [
            '@target' => $context['target_workshop_label'],
            '@slot' => $context['slot'],
            '@occupied' => $context['target_occupancy'],
            '@capacity' => $context['target_capacity'],
          ],
        ),
      ],
    ];

    $form['swap'] = [
      '#type' => 'table',
      '#header' => [$this->t('Person'), $this->t('Bisher'), $this->t('Nach dem Tausch')],
      '#rows' => [
        [
          $context['participant_name'],
          $context['old_workshop_label'],
          $context['target_workshop_label'],
        ],
        [
          $this->t('Ausgewählte Tauschperson'),
          $context['target_workshop_label'],
          $context['old_workshop_label'],
        ],
      ],
    ];

    if (!$context['candidates']) {
      $form['no_candidates'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        'content' => [
          '#markup' => $this->t(
            'Für diesen direkten Tausch gibt es aktuell keine kompatible Person. Personen, die „@old“ bereits in einem anderen Slot haben, werden aus Sicherheitsgründen nicht angeboten.',
            ['@old' => $context['old_workshop_label']],
          ),
        ],
      ];
    }
    else {
      $form['partner_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Tauschperson'),
        '#options' => $context['candidates'],
        '#empty_option' => $this->t('- Person auswählen -'),
        '#required' => TRUE,
        '#description' => $this->t(
          'Die ausgewählte Person wechselt in Slot @slot von „@target“ nach „@old“. Beide Änderungen werden gemeinsam gespeichert oder gemeinsam verworfen.',
          [
            '@slot' => $context['slot'],
            '@target' => $context['target_workshop_label'],
            '@old' => $context['old_workshop_label'],
          ],
        ),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    if ($context['candidates']) {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Tausch durchführen'),
        '#button_type' => 'primary',
      ];
    }
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Zurück zur Zuteilung'),
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
