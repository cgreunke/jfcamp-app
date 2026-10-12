<?php

namespace Drupal\jfcamp_team\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Creates and edits restricted Teamer*innen accounts.
 */
final class TeamerForm extends FormBase {

  private ?UserInterface $teamer = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'jfcamp_team_teamer_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $user_id = NULL): array {
    if ($user_id === NULL) {
      $route_user_id = \Drupal::routeMatch()->getParameter('user_id');
      if ($route_user_id !== NULL && $route_user_id !== '') {
        $user_id = (int) $route_user_id;
      }
    }

    if ($user_id !== NULL) {
      $account = $this->entityTypeManager->getStorage('user')->load($user_id);
      if (!$account instanceof UserInterface || !$this->isManageableTeamer($account)) {
        throw new AccessDeniedHttpException();
      }
      $this->teamer = $account;
    }

    $is_new = $this->teamer === NULL;
    $form['#attached']['library'][] = 'jfcamp_team/admin';
    $form['#attributes']['class'][] = 'jfcamp-team-form';

    $form['intro'] = [
      '#markup' => '<div class="jfcamp-team-callout"><strong>'
        . $this->t($is_new ? 'Neue Teamer*in anlegen' : 'Teamer*in bearbeiten')
        . '</strong><br>'
        . $this->t('Teamer*innen erhalten ausschließlich lesenden Zugriff auf Teilnehmende der hier zugeordneten Regionalverbände.')
        . '</div>',
    ];

    $form['account'] = [
      '#type' => 'details',
      '#title' => $this->t('Konto'),
      '#open' => TRUE,
    ];

    $form['account']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Benutzername'),
      '#required' => TRUE,
      '#default_value' => $this->teamer?->getAccountName() ?? '',
      '#maxlength' => 60,
      '#description' => $this->t('Dieser Name wird für die Anmeldung verwendet.'),
    ];

    $form['account']['status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Kontostatus'),
      '#options' => [
        '1' => $this->t('Aktiv – Anmeldung ist möglich'),
        '0' => $this->t('Gesperrt – Anmeldung ist nicht möglich'),
      ],
      '#default_value' => ($this->teamer?->isActive() ?? TRUE) ? '1' : '0',
      '#description' => $this->t('Sperren löscht das Konto nicht. Regionalverbände und Einstellungen bleiben erhalten.'),
    ];

    $form['account']['pass'] = [
      '#type' => 'password_confirm',
      '#title' => $is_new ? $this->t('Startpasswort') : $this->t('Passwort ändern'),
      '#required' => $is_new,
      '#description' => $is_new
        ? $this->t('Beim Anlegen ist ein Startpasswort erforderlich. Es wird der Teamerperson außerhalb des Systems mitgeteilt.')
        : $this->t('Optional: Leer lassen, damit das bestehende Passwort unverändert bleibt.'),
    ];

    $form['regions'] = [
      '#type' => 'details',
      '#title' => $this->t('Zugriff auf Regionalverbände'),
      '#open' => TRUE,
    ];

    $options = $this->loadRegionOptions();
    $defaults = $this->teamer ? $this->getRegions($this->teamer) : [];

    $form['regions']['regionalverbaende'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Zugeordnete Regionalverbände'),
      '#options' => $options,
      '#default_value' => $defaults,
      '#required' => TRUE,
      '#description' => $this->t('Diese Auswahl ist die serverseitige Zugriffsgrenze: Die Teamerperson sieht nur Teilnehmende der markierten Regionalverbände.'),
      '#attributes' => ['class' => ['jfcamp-team-regions']],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $is_new ? $this->t('Teamer*in anlegen') : $this->t('Änderungen speichern'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Abbrechen'),
      '#url' => Url::fromRoute('jfcamp_team.overview'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $name = trim((string) $form_state->getValue('name'));
    if ($name === '') {
      $form_state->setErrorByName('name', $this->t('Bitte einen Benutzernamen angeben.'));
      return;
    }

    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name);
    if ($this->teamer) {
      $query->condition('uid', $this->teamer->id(), '<>');
    }
    if ($query->count()->execute() > 0) {
      $form_state->setErrorByName('name', $this->t('Dieser Benutzername wird bereits verwendet.'));
    }

    $regions = array_values(array_filter($form_state->getValue('regionalverbaende') ?? []));
    if (!$regions) {
      $form_state->setErrorByName('regionalverbaende', $this->t('Mindestens ein Regionalverband muss zugeordnet werden.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $is_new = $this->teamer === NULL;
    $account = $this->teamer ?? User::create();

    $account->setUsername(trim((string) $form_state->getValue('name')));
    $account->set('status', $form_state->getValue('status') === '1');

    if ($is_new) {
      $account->addRole('teamer');
      $account->setEmail('');
    }

    $password = (string) $form_state->getValue('pass');
    if ($password !== '') {
      $account->setPassword($password);
    }

    $regions = array_values(array_filter($form_state->getValue('regionalverbaende') ?? []));
    sort($regions, SORT_NATURAL | SORT_FLAG_CASE);
    $account->set('field_jfcamp_regionalverbaende', array_map(
      static fn(string $region): array => ['value' => $region],
      $regions,
    ));

    if (!$is_new && $account->getRoles(TRUE) !== ['teamer']) {
      throw new \RuntimeException('Dieses Konto besitzt zusätzliche Rollen und kann nicht über die JF-Camp-Teamverwaltung geändert werden.');
    }

    $account->save();

    $this->messenger()->addStatus($is_new
      ? $this->t('Teamer*in @name wurde angelegt.', ['@name' => $account->getAccountName()])
      : $this->t('Teamer*in @name wurde aktualisiert.', ['@name' => $account->getAccountName()]));

    $form_state->setRedirect('jfcamp_team.overview');
  }

  private function isManageableTeamer(UserInterface $account): bool {
    return (int) $account->id() > 0 && $account->getRoles(TRUE) === ['teamer'];
  }

  private function getRegions(UserInterface $account): array {
    $regions = [];
    if (!$account->hasField('field_jfcamp_regionalverbaende')) {
      return $regions;
    }
    foreach ($account->get('field_jfcamp_regionalverbaende') as $item) {
      $value = trim((string) $item->value);
      if ($value !== '') {
        $regions[] = $value;
      }
    }
    sort($regions, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($regions));
  }

  private function loadRegionOptions(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->exists('field_regionalverband')
      ->execute();

    $regions = [];
    foreach ($storage->loadMultiple($ids) as $participant) {
      $value = trim((string) $participant->get('field_regionalverband')->value);
      if ($value !== '') {
        $regions[$value] = $value;
      }
    }
    ksort($regions, SORT_NATURAL | SORT_FLAG_CASE);
    return $regions;
  }

}
