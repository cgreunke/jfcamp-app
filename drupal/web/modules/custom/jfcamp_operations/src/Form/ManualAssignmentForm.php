<?php

namespace Drupal\jfcamp_operations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\jfcamp_operations\Service\ManualAssignmentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Camp-Leitung form for replacing one workshop assignment.
 */
final class ManualAssignmentForm extends FormBase {

  public function __construct(
    private ManualAssignmentService $manualAssignment,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('jfcamp_operations.manual_assignment'));
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
    $choices = $this->manualAssignment->getWorkshopChoices($participantId, $slotNumber);

    $name = trim($state['first_name'] . ' ' . $state['last_name']);
    if ($name === '') {
      $name = $state['code'] !== '' ? $state['code'] : '#' . $participantId;
    }

    $form['participant_id'] = ['#type' => 'hidden', '#value' => $participantId];
    $form['slot'] = ['#type' => 'hidden', '#value' => $slotNumber];

    $form['context'] = [
      '#type' => 'table',
      '#header' => [$this->t('Merkmal'), $this->t('Wert')],
      '#rows' => [
        [$this->t('Teilnehmer'), $name],
        [$this->t('Code'), $state['code'] !== '' ? $state['code'] : '—'],
        [$this->t('Regionalverband'), $state['region'] !== '' ? $state['region'] : '—'],
        [$this->t('Slot'), (string) $slotNumber],
        [$this->t('Aktuelle Zuteilung'), $currentWorkshop],
      ],
    ];

    $form['notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'content' => [
        '#markup' => $this->t(
          'Die Änderung wird direkt in den aktuellen Zuteilungsstand geschrieben. Der gespeicherte Matching-Snapshot bleibt unverändert.'
        ),
      ],
    ];

    $form['workshop_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Neue Zuteilung für Slot @slot', ['@slot' => $slotNumber]),
      '#options' => $choices,
      '#default_value' => $currentWorkshopId ?: NULL,
      '#required' => TRUE,
      '#description' => $this->t(
        'Angezeigt werden veröffentlichte Workshops mit freier Kapazität in diesem Slot. Bereits in einem anderen Slot zugeteilte Workshops werden ausgeblendet.'
      ),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Zuteilung speichern'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Abbrechen'),
      '#url' => Url::fromRoute('jfcamp_operations.participants', [], [
        'query' => $state['code'] !== '' ? ['q' => $state['code']] : [],
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

    $state = $this->manualAssignment->getParticipantState($participantId);
    $form_state->setRedirect('jfcamp_operations.participants', [], [
      'query' => $state['code'] !== '' ? ['q' => $state['code']] : [],
    ]);
  }

}
