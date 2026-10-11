<?php

namespace Drupal\jfcamp_team\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    // Route parameters are not passed to FormBase::buildForm() automatically.
    // Resolve the edit target explicitly from the current route.
    if ($user_id === NULL) {
      $route_user_id = \Drupal::routeMatch()->getParameter('user_id');
      if ($route_user_id !== NULL && $route_user_id !== '') {
        $user_id = (int) $route_user_id;
      }
    }

    if ($user_id !== NULL) {
      $account = $this->entityTypeManager->getStorage('user')->load($user_id);
      if (!$account instanceof UserInterface || !$this->isManageableTeamer($account)) {
        throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
      }
      $this->teamer = $account;
    }

    $is_new = $this->teamer === NULL;

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
      '#description' => $this->t('Der Benutzername wird zum Anmelden verwendet.'),
    ];

    $form['account']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Konto aktiv'),
      '#default_value' => $this->teamer?->isActive() ?? TRUE,
      '#description' => $this->t('Deaktivieren sperrt den Login, ohne das Konto oder seine Zuordnung zu löschen.'),
    ];

    $form['account']['pass'] = [
      '#type' => 'password_confirm',
      '#title' => $is_new ? $this->t('Startpasswort') : $this->t('Neues Passwort'),
      '#required' => $is_new,
      '#description' => $is_new
        ? $this->t('Das Startpasswort wird der Teamerperson außerhalb des Systems mitgeteilt. E-Mail wird nicht verwendet.')
        : $this->t('Leer lassen, wenn das bestehende Passwort unverändert bleiben soll.'),
    ];

    $form['regions'] = [
      '#type' => 'details',
      '#title' => $this->t('Regionalverbände'),
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
      '#description' => $this->t('Teamer*innen sehen ausschließlich Teilnehmende dieser Regionalverbände.'),
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
      '#url' => \Drupal\Core\Url::fromRoute('jfcamp_team.overview'),
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
    $account->set('status', (bool) $form_state->getValue('status'));

    if ($is_new) {
      $account->addRole('teamer');
      $account->setEmail('');
    }

    $password = $form_state->getValue('pass');
    if (is_array($password) && !empty($password['pass1'])) {
      $account->setPassword((string) $password['pass1']);
    }

    $regions = array_values(array_filter($form_state->getValue('regionalverbaende') ?? []));
    sort($regions, SORT_NATURAL | SORT_FLAG_CASE);
    $account->set('field_jfcamp_regionalverbaende', array_map(
      static fn(string $region): array => ['value' => $region],
      $regions,
    ));

    // Safety boundary: this UI must never grant or preserve elevated roles.
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
