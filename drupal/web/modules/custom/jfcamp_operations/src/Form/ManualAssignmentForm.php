<?php

namespace Drupal\jfcamp_operations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\jfcamp_operations\Service\AssignmentSwapService;
use Drupal\jfcamp_operations\Service\ManualAssignmentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Camp-Leitung form for replacing one workshop assignment.
 */
final class ManualAssignmentForm extends FormBase {

  public function __construct(
    private ManualAssignmentService $manualAssignment,
    private AssignmentSwapService $assignmentSwap,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_operations.manual_assignment'),
      $container->get('jfcamp_operations.assignment_swap'),
    );
  }

  public function getFormId(): string {
    return 'jfcamp_operations_manual_assignment_form';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?int $participant_id = NULL,
    ?int $slot = NULL,
  ): array {
    $participantId = (int) $participant_id;
    $slotNumber = (int) $slot;

    $state = $this->manualAssignment->getParticipantState($participantId);
    if ($slotNumber < 1 || $slotNumber > $state['assignment_count']) {
      throw new \InvalidArgumentException('Ungültiger Zuteilungs-Slot.');
    }

    $currentWorkshopId = (int) ($state['assignment_ids'][$slotNumber - 1] ?? 0);
    $currentWorkshop = (string) ($state['assignments'][$slotNumber - 1] ?? '—');
    $choiceData = $this->assignmentSwap->getWorkshopChoices($participantId, $slotNumber);

    $name = trim($state['first_name'] . ' ' . $state['last_name']);
    if ($name === '') {
      $name = $state['code'] !== '' ? $state['code'] : '#' . $participantId;
    }

    $form['#attributes']['class'][] = 'jfcamp-operation-form';
    $form['#attributes']['class'][] = 'jfcamp-operation-form--assignment';

    $form['participant_id'] = ['#type' => 'hidden', '#value' => $participantId];
    $form['slot'] = ['#type' => 'hidden', '#value' => $slotNumber];

    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operation-form__header']],
      'eyebrow' => [
        '#markup' => '<div class="jfcamp-operation-form__eyebrow">' . $this->t('Camp-Leitung · Zuteilung') . '</div>',
      ],
      'title' => [
        '#markup' => '<h2 class="jfcamp-operation-form__title">' . $this->t('Slot @slot ändern', ['@slot' => $slotNumber]) . '</h2>',
      ],
      'meta' => [
        '#markup' => '<div class="jfcamp-operation-form__meta"><strong>' . $this->t('@name', ['@name' => $name]) . '</strong>'
          . ($state['code'] !== '' ? ' · ' . $this->t('Code @code', ['@code' => $state['code']]) : '')
          . ($state['region'] !== '' ? ' · ' . $this->t('@region', ['@region' => $state['region']]) : '') . '</div>',
      ],
    ];

    $form['current'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operation-form__panel', 'jfcamp-operation-form__current']],
      'label' => [
        '#markup' => '<div class="jfcamp-operation-form__section-title">' . $this->t('Aktuelle Zuteilung') . '</div>',
      ],
      'value' => [
        '#markup' => '<div class="jfcamp-operation-form__current-value"><span>' . $this->t('Slot @slot', ['@slot' => $slotNumber]) . '</span><strong>' . $this->t('@workshop', ['@workshop' => $currentWorkshop]) . '</strong></div>',
      ],
    ];

    $form['notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operation-form__notice', 'is-warning']],
      'title' => ['#markup' => '<strong>' . $this->t('Direkte Änderung des aktuellen Zuteilungsstands') . '</strong>'],
      'content' => [
        '#markup' => '<div>' . $this->t('Der gespeicherte Matching-Snapshot bleibt unverändert. Volle Workshops können ausgewählt werden; statt einer Überbuchung folgt dann automatisch ein kapazitätssicherer 1:1-Tausch.') . '</div>',
      ],
    ];

    $form['selection'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operation-form__panel']],
    ];
    $form['selection']['workshop_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Neue Zuteilung für Slot @slot', ['@slot' => $slotNumber]),
      '#options' => $choiceData['options'],
      '#default_value' => $currentWorkshopId ?: NULL,
      '#required' => TRUE,
      '#description' => $this->t('Alle veröffentlichten Workshops werden mit ihrer aktuellen Belegung angezeigt. Volle Workshops sind auswählbar, werden aber niemals direkt überbucht. Workshops, die diese Person bereits in einem anderen Slot hat, sind ausgeblendet.'),
      '#wrapper_attributes' => ['class' => ['jfcamp-operation-form__field']],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['jfcamp-operation-form__actions']],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Änderung prüfen'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Zurück zum Teilnehmer'),
      '#url' => Url::fromRoute('jfcamp_operations.participant_detail', [
        'participant_id' => $participantId,
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $participantId = (int) $form_state->getValue('participant_id');
    $slot = (int) $form_state->getValue('slot');
    $workshopId = (int) $form_state->getValue('workshop_id');

    try {
      if ($this->assignmentSwap->requiresSwap($participantId, $slot, $workshopId)) {
        $form_state->setRedirect('jfcamp_operations.assignment_swap', [
          'participant_id' => $participantId,
          'slot' => $slot,
          'target_workshop_id' => $workshopId,
        ]);
        return;
      }

      $result = $this->manualAssignment->changeAssignment($participantId, $slot, $workshopId);
      if (!empty($result['changed'])) {
        $this->messenger()->addStatus($this->t(
          'Slot @slot wurde auf „@workshop“ geändert.',
          ['@slot' => $slot, '@workshop' => $result['new_workshop_label']],
        ));
      }
      else {
        $this->messenger()->addStatus($this->t('Die Zuteilung war bereits so gespeichert.'));
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }

    $form_state->setRedirect('jfcamp_operations.participant_detail', [
      'participant_id' => $participantId,
    ]);
  }

}
