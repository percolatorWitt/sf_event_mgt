<?php
namespace SKYFILLERS\SfEventMgt\Controller;


/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2014 Torben Hansen <derhansen@gmail.com>, Skyfillers GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use SKYFILLERS\SfEventMgt\Domain\Model\Event;
use SKYFILLERS\SfEventMgt\Domain\Model\Registration;
use SKYFILLERS\SfEventMgt\Util\RegistrationResult;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * EventController
 */
class EventController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController {

	/**
	 * eventRepository
	 *
	 * @var \SKYFILLERS\SfEventMgt\Domain\Repository\EventRepository
	 * @inject
	 */
	protected $eventRepository = NULL;

	/**
	 * Registration repository
	 *
	 * @var \SKYFILLERS\SfEventMgt\Domain\Repository\RegistrationRepository
	 * @inject
	 */
	protected $registrationRepository = NULL;

	/**
	 * Notification Service
	 *
	 * @var \SKYFILLERS\SfEventMgt\Service\NotificationService
	 * @inject
	 */
	protected $notificationService = NULL;

	/**
	 * Hash Service
	 *
	 * @var \TYPO3\CMS\Extbase\Security\Cryptography\HashService
	 * @inject
	 */
	protected $hashService;

	/**
	 * Create a demand object with the given settings
	 *
	 * @param array $settings
	 * @return \SKYFILLERS\SfEventMgt\Domain\Model\Dto\EventDemand
	 */
	public function createDemandObjectFromSettings($settings) {
		$demand = $this->objectManager->get('SKYFILLERS\\SfEventMgt\\Domain\\Model\\Dto\\EventDemand');
		$demand->setDisplayMode($settings['displayMode']);
		$demand->setStoragePage($settings['storagePage']);
		$demand->setCategory($settings['category']);
		return $demand;
	}

	/**
	 * List view
	 *
	 * @return void
	 */
	public function listAction() {
		$demand = $this->createDemandObjectFromSettings($this->settings);

		$events = $this->eventRepository->findDemanded($demand);
		$this->view->assign('events', $events);
	}

	/**
	 * Detail view for an event
	 *
	 * @param $event \SKYFILLERS\SfEventMgt\Domain\Model\Event
	 * @return void
	 */
	public function detailAction(Event $event) {
		$this->view->assign('event', $event);
	}

	/**
	 * Registration view for an event
	 *
	 * @param $event \SKYFILLERS\SfEventMgt\Domain\Model\Event
	 * @return void
	 */
	public function registrationAction(Event $event) {
		$this->view->assign('event', $event);
	}

	/**
	 * Saves the registration
	 *
	 * @param $registration \SKYFILLERS\SfEventMgt\Domain\Model\Registration
	 * @param $event \SKYFILLERS\SfEventMgt\Domain\Model\Event
	 * @return void
	 */
	public function saveRegistrationAction(Registration $registration, Event $event) {
		$success = TRUE;
		$result = RegistrationResult::REGISTRATION_SUCCESSFUL;
		if ($event->getStartdate() < new \DateTime()) {
			$success = FALSE;
			$result = RegistrationResult::REGISTRATION_FAILED_EVENT_EXPIRED;
		} elseif ($event->getRegistration()->count() >= $event->getMaxParticipants()
			&& $event->getMaxParticipants() > 0) {
			$success = FALSE;
			$result = RegistrationResult::REGISTRATION_FAILED_MAX_PARTICIPANTS;
		}

		// Save registration if no errors
		if ($success) {
			$linkValidity = $this->settings['confirmation']['linkValidity'];
			if ($linkValidity === '' || !is_int($linkValidity)) {
				// Use 3600 seconds as default value if not set
				$linkValidity = 3600;
			}
			$confirmationUntil = new \DateTime();
			$confirmationUntil->add(new \DateInterval('PT' . $linkValidity . 'S'));

			$registration->setEvent($event);
			$registration->setPid($event->getPid());
			$registration->setConfirmationUntil($confirmationUntil);
			$this->registrationRepository->add($registration);

			// Persist registration, so we have an UID
			$this->objectManager->get('TYPO3\\CMS\\Extbase\\Persistence\\Generic\\PersistenceManager')->persistAll();

			// Send notifications to user and admin
			$this->notificationService->sendUserConfirmationMessage($event, $registration, $this->settings);
			$this->notificationService->sendAdminNewRegistrationMessage($event, $registration, $this->settings);
		}

		$this->redirect('saveRegistrationResult', NULL, NULL,
			array('result' => $result));
	}

	/**
	 * Shows the result of the saveRegistrationAction
	 *
	 * @param int $result
	 * @return void
	 */
	public function saveRegistrationResultAction($result) {
		switch ($result) {
			case RegistrationResult::REGISTRATION_SUCCESSFUL:
				$message = LocalizationUtility::translate('event.message.registrationsuccessful', 'SfEventMgt');
				break;
			case RegistrationResult::REGISTRATION_FAILED_EVENT_EXPIRED:
				$message = LocalizationUtility::translate('event.message.registrationfailedeventexpired',
					'SfEventMgt');
				break;
			case RegistrationResult::REGISTRATION_FAILED_MAX_PARTICIPANTS:
				$message = LocalizationUtility::translate('event.message.registrationfailedmaxparticipants',
					'SfEventMgt');
				break;
			default:
				$message = '';
		}

		$this->view->assign('message', $message);
	}

	/**
	 * Confirms the registration if possible and sends e-mails to admin and user
	 *
	 * @param int $reguid UID of registration
	 * @param string $hmac HMAC for parameters
	 *
	 * @return void
	 */
	public function confirmRegistrationAction($reguid, $hmac) {
		/** @var Registration $registration */
		$registration = NULL;
		$failed = FALSE;
		$message = LocalizationUtility::translate('event.message.confirmation_successful', 'SfEventMgt');

		if (!$this->hashService->validateHmac('reg-' . $reguid, $hmac)) {
			$failed = TRUE;
			$message = LocalizationUtility::translate('event.message.confirmation_failed_wrong_hmac', 'SfEventMgt');
		} else {
			$registration = $this->registrationRepository->findByUid($reguid);
		}

		if (!$failed && is_null($registration)) {
			$failed = TRUE;
			$message = LocalizationUtility::translate('event.message.confirmation_failed_registration_not_found',
				'SfEventMgt');
		}

		if (!$failed && $registration->getConfirmationUntil() < new \DateTime()) {
			$failed = TRUE;
			$message = LocalizationUtility::translate('event.message.confirmation_failed_confirmation_until_expired',
				'SfEventMgt');
		}

		if (!$failed && $registration->getConfirmed() === TRUE) {
			$failed = TRUE;
			$message = LocalizationUtility::translate('event.message.confirmation_failed_already_confirmed',
				'SfEventMgt');
		}

		if ($failed === FALSE) {
			$registration->setConfirmed(TRUE);
			$this->registrationRepository->update($registration);

			// @todo - Send confirmation e-mails #38
		}

		$this->view->assign('message', $message);
	}
}
