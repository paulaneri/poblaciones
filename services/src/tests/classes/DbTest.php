<?php declare(strict_types=1);

namespace helena\tests\classes;

use helena\classes\App;
use helena\classes\TestCase;
use helena\db\admin\ContactModel;
use helena\entities\admin\Contact;

class DbTest extends TestCase
{
	public function testDbSaveWithRollback()
	{
		$contactModel = new ContactModel();
		$contactInfo = new Contact();
		$contactInfo->Person = 'test';
		$contactInfo->Email = 'test@test.com';
		App::Db()->begin();
		$res = $contactModel->DbSave($contactInfo);
		App::Db()->rollback();
		$this->assertGreaterThan(0, $res);
	}
}
