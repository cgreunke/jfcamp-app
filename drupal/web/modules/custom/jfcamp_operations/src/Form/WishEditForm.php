<?php

namespace Drupal\jfcamp_operations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\jfcamp_operations\Service\WishManagementService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Camp-leadership form for editing ordered participant wishes.
 */
final class WishEditForm extends FormBase {

  public function __construct(
    private WishManagementService $wishManagement,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('jfcamp_operations.wish_management'));
  }

  public function getFormId(): string {
    return 'jfcamp_operations_wish_edit_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $participant_id = NULL): array {
    if ($participant_id === NULL) {
      $routeValue = \Drupal::routeMatch()->getParameter('participant_id');
      $participant_id = $routeValue !== NULL ? (int) $routeValue : 0;
    }

    $state = $this->wishManagement->getState($participant_id);
    $participant = $state['participant'];
    $options = ['' => $this->t('- Kein Wunsch -')] + $this->wishManagement->getWorkshopOptions();

    $first = $participant->hasField('field_vorname') ? trim((string) $participant->get('field_vorname')->value) : '';
    $last = $participant->hasField('field_name') ? trim((string) $participant->get('field_name')->value) : '';
    $name = trim($first . ' ' . $last);
    $code = $participant->hasField('field_code') ? trim((string) $participant->get('field_code')->value) : '';

    $form['participant_id'] = [
      '#type' => 'hidden',
      '#value' => $participant_id,
    ];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'content' => [
        '#markup' => '<strong>' . $this->t('Wünsche von @name bearbeiten', ['@name' => $name !== '' ? $name : $code]) . '</strong><br>'
          . $this->t('Wunschänderungen verändern bestehende Zuteilungen nicht automatisch. Sie wirken erst bei einem neuen Matching oder wenn Zuteilungen anschließend manuell angepasst werden.'),
      ],
    ];

    for ($position = 1; $position <= WishManagementService::MAX_WISHES; $position++) {
      $form['wish_' . $position] = [
        '#type' => 'select',
        '#title' => $this->t('Wunsch @position', ['@position' => $position]),
        '#options' => $options,
        '#default_value' => $state['wish_ids'][$position - 1] ?? '',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Wünsche speichern'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Abbrechen'),
      '#url' => Url::fromRoute('jfcamp_operations.participant_detail', ['participant_id' => $participant_id]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $ids = [];
    $gapSeen = FALSE;
    for ($position = 1; $position <= WishManagementService::MAX_WISHES; $position++) {
      $value = (int) $form_state->getValue('wish_' . $position);
      if ($value <= 0) {
        $gapSeen = TRUE;
        continue;
      }
      if ($gapSeen) {
        $form_state->setErrorByName(
          'wish_' . $position,
          $this->t('Bitte Wünsche ohne Lücken anordnen. Nach einem leeren Wunsch dürfen keine weiteren Wünsche folgen.'),
        );
      }
      if (in_array($value, $ids, TRUE)) {
        $form_state->setErrorByName(
          'wish_' . $position,
          $this->t('Ein Workshop darf nur einmal ausgewählt werden.'),
        );
      }
      $ids[] = $value;
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $participantId = (int) $form_state->getValue('participant_id');
    $ids = [];
    for ($position = 1; $position <= WishManagementService::MAX_WISHES; $position++) {
      $value = (int) $form_state->getValue('wish_' . $position);
      if ($value > 0) {
        $ids[] = $value;
      }
    }

    try {
      $result = $this->wishManagement->saveWishes($participantId, $ids);
      if (!empty($result['changed'])) {
        $this->messenger()->addStatus($this->t('Die Wünsche wurden gespeichert und im Änderungsprotokoll dokumentiert.'));
      }
      else {
        $this->messenger()->addStatus($this->t('Es wurden keine Änderungen vorgenommen.'));
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Die Wünsche konnten nicht gespeichert werden: @message', ['@message' => $e->getMessage()]));
      return;
    }

    $form_state->setRedirect('jfcamp_operations.participant_detail', ['participant_id' => $participantId]);
  }

}
