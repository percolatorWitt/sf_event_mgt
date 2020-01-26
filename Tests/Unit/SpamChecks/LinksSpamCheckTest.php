<?php
namespace DERHANSEN\SfEventMgt\Tests\Unit\SpamChecks;

/*
 * This file is part of the Extension "sf_event_mgt" for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use DERHANSEN\SfEventMgt\Domain\Model\Registration;
use DERHANSEN\SfEventMgt\SpamChecks\LinkSpamCheck;
use Nimut\TestingFramework\TestCase\UnitTestCase;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Test case for class DERHANSEN\SfEventMgt\SpamChecks\LinksSpamCheckTest
 */
class LinksSpamCheckTest extends UnitTestCase
{
    /**
     * @test
     */
    public function checkIsFailedWhenConfiguredAmountOfLinksIsExceeded()
    {
        $registration = new Registration();
        $registration->setFirstname('https://www.derhansen.com');
        $registration->setLastname('https://www.derhansen.com');
        $registration->setAddress('https://www.derhansen.com');
        $settings = [];
        $arguments = [];
        $configuration = [
            'maxAmountOfLinks' => 2
        ];

        $check = new LinkSpamCheck($registration, $settings, $arguments, $configuration);
        $this->assertTrue($check->isFailed());
    }

    /**
     * @test
     */
    public function checkIsFailedWhenConfiguredAmountOfLinksIsExceededInRegistrationField()
    {
        $registrationFieldValue = new Registration\FieldValue();
        $registrationFieldValue->setValue('https://www.derhansen.com');
        $objectStorage = new ObjectStorage();
        $objectStorage->attach($registrationFieldValue);
        $registration = new Registration();
        $registration->setFirstname('https://www.typo3.org');
        $registration->setFieldValues($objectStorage);
        $settings = [];
        $arguments = [];
        $configuration = [
            'maxAmountOfLinks' => 1
        ];

        $check = new LinkSpamCheck($registration, $settings, $arguments, $configuration);
        $this->assertTrue($check->isFailed());
    }

    /**
     * @test
     */
    public function checkIsNotFailedWhenConfiguredAmountOfLinksIsNotExceeded()
    {
        $registration = new Registration();
        $registration->setFirstname('https://www.derhansen.com');
        $settings = [];
        $arguments = [];
        $configuration = [
            'maxAmountOfLinks' => 1
        ];

        $check = new LinkSpamCheck($registration, $settings, $arguments, $configuration);
        $this->assertFalse($check->isFailed());
    }
}