<?php

namespace PWalkow\MongoDBAclBundle\Tests\Security\Domain;

use PWalkow\MongoDBAclBundle\Security\Domain\AclProvider;
use PWalkow\MongoDBAclBundle\Security\Domain\MutableAclProvider;
use Symfony\Component\Security\Acl\Domain\Acl;
use Symfony\Component\Security\Acl\Domain\Entry;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Domain\PermissionGrantingStrategy;
use Symfony\Component\Security\Acl\Domain\RoleSecurityIdentity;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Acl\Exception\AclNotFoundException;
use Symfony\Component\Security\Acl\Model\AuditableEntryInterface;
use Symfony\Component\Security\Acl\Model\EntryInterface;
use Symfony\Component\Security\Acl\Model\FieldEntryInterface;

/**
 * @coversDefaultClass MutableAclProvider
 */
class MutableAclProviderTest extends AbstractAclProviderTest
{
    protected function setUp()
    {
        parent::setUp();
        $this->oid = [];
    }

    protected function tearDown()
    {
        $this->oid = [];
        parent::tearDown();
    }

    /**
     * @covers ::createAcl
     *
     * @expectedException \Symfony\Component\Security\Acl\Exception\AclAlreadyExistsException
     * @expectedExceptionMessage ObjectIdentity(123456, Foo) is already associated with an ACL.
     */
    public function testCreateAclThrowsExceptionWhenAclAlreadyExists()
    {
        $provider = $this->getProvider();
        $oid = new ObjectIdentity('123456', 'Foo');
        $provider->createAcl($oid);
        $provider->createAcl($oid);
    }

    /**
     * @covers ::findAcl
     */
    public function testCreateAcl()
    {
        $provider = $this->getProvider();
        $oid = new ObjectIdentity('123456', 'Foo');
        $acl = $provider->createAcl($oid);
        $cachedAcl = $provider->findAcl($oid);

        $oidCursor = $this->oidCollection->find();

        $this->assertInstanceOf(Acl::class, $acl);
        $this->assertSame($acl, $cachedAcl);
        $this->assertTrue($acl->getObjectIdentity()->equals($oid));
        $this->assertEquals(1, $oidCursor->count());
    }

    /**
     * @covers ::deleteAcl
     */
    public function testDeleteAcl()
    {
        $provider = $this->getProvider();
        $oid = new ObjectIdentity(1, 'Foo');
        $acl = $provider->createAcl($oid);

        $provider->deleteAcl($oid);
        $loadedAcls = $this->getField($provider, 'loadedAcls');
        $this->assertEquals(0, count($loadedAcls['Foo']));

        try {
            $provider->findAcl($oid);
            $this->fail('ACL has not been properly deleted.');
        } catch (AclNotFoundException $notFound) {
        }
    }

    /**
     * @covers ::updateAcl
     * @covers ::deleteAcl
     */
    public function testDeleteAclDeletesChildren()
    {
        $provider = $this->getProvider();
        $acl = $provider->createAcl(new ObjectIdentity(1, 'Foo'));
        $parentAcl = $provider->createAcl(new ObjectIdentity(2, 'Foo'));
        $acl->setParentAcl($parentAcl);
        $provider->updateAcl($acl);
        $provider->deleteAcl($parentAcl->getObjectIdentity());

        try {
            $provider->findAcl(new ObjectIdentity(1, 'Foo'));
            $this->fail('Child-ACLs have not been deleted.');
        } catch (AclNotFoundException $notFound) {
        }
    }

    /**
     * @covers ::updateAcl
     * @covers ::deleteAcl
     */
    public function testDeleteAclRemovesOidAndAces()
    {
        $provider = $this->getProvider();
        $oid = new ObjectIdentity(1, 'Foo');

        $acl = $provider->createAcl($oid);
        $acl->insertObjectAce(new RoleSecurityIdentity('ROLE_USER'), 1);

        $provider->updateAcl($acl);
        $provider->deleteAcl($oid);

        $oidCursor = $this->oidCollection->find();
        $entryCursor = $this->entryCollection->find();

        $this->assertEquals(0, $oidCursor->count());
        $this->assertEquals(0, $entryCursor->count());
    }

    /**
     * @covers ::createAcl
     */
    public function testFindAclsAddsPropertyListener()
    {
        $provider = $this->getProvider();
        $acl = $provider->createAcl(new ObjectIdentity(1, 'Foo'));

        $propertyChanges = $this->getField($provider, 'propertyChanges');
        $this->assertEquals(1, count($propertyChanges));
        $this->assertTrue($propertyChanges->contains($acl));
        $this->assertEquals([], $propertyChanges->offsetGet($acl));

        $listeners = $this->getField($acl, 'listeners');
        $this->assertSame($provider, $listeners[0]);
    }

    /**
     * @covers ::findAcl
     */
    public function testFindAclsAddsPropertyListenerOnlyOnce()
    {
        $provider = $this->getProvider();
        $acl = $provider->createAcl(new ObjectIdentity(1, 'Foo'));
        $acl = $provider->findAcl(new ObjectIdentity(1, 'Foo'));

        $propertyChanges = $this->getField($provider, 'propertyChanges');
        $this->assertEquals(1, count($propertyChanges));
        $this->assertTrue($propertyChanges->contains($acl));
        $this->assertEquals([], $propertyChanges->offsetGet($acl));

        $listeners = $this->getField($acl, 'listeners');
        $this->assertEquals(1, count($listeners));
        $this->assertSame($provider, $listeners[0]);
    }

    public function testFindAclsAddsPropertyListenerToParentAcls()
    {
        $provider = $this->getProvider();
        $this->importAcls(
            $provider,
            array(
              'main' => array(
                  'object_identifier' => '1',
                  'class_type' => 'foo',
                  'parent_acl' => 'parent',
              ),
              'parent' => array(
                  'object_identifier' => '1',
                  'class_type' => 'anotherFoo',
              )
            )
        );

        $propertyChanges = $this->getField($provider, 'propertyChanges');
        $this->assertEquals(0, count($propertyChanges));

        $acl = $provider->findAcl(new ObjectIdentity('1', 'foo'));
        $this->assertEquals(2, count($propertyChanges));
        $this->assertTrue($propertyChanges->contains($acl));
        $this->assertTrue($propertyChanges->contains($acl->getParentAcl()));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage $sender is not being tracked by this provider.
     *
     * @covers ::propertyChanged
     */
    public function testPropertyChangedDoesNotTrackUnmanagedAcls()
    {
        $provider = $this->getProvider();
        $acl = new Acl(1, new ObjectIdentity(1, 'foo'), new PermissionGrantingStrategy(), array(), false);

        $provider->propertyChanged($acl, 'classAces', array(), array('foo'));
    }

    /**
     * @covers ::propertyChanged
     */
    public function testPropertyChangedTracksChangesToAclProperties()
    {
        $provider = $this->getProvider();
        $acl = $provider->createAcl(new ObjectIdentity(1, 'Foo'));
        $propertyChanges = $this->getField($provider, 'propertyChanges');

        $provider->propertyChanged($acl, 'entriesInheriting', false, true);
        $changes = $propertyChanges->offsetGet($acl);
        $this->assertTrue(isset($changes['entriesInheriting']));
        $this->assertFalse($changes['entriesInheriting'][0]);
        $this->assertTrue($changes['entriesInheriting'][1]);

        $provider->propertyChanged($acl, 'entriesInheriting', true, false);
        $provider->propertyChanged($acl, 'entriesInheriting', false, true);
        $provider->propertyChanged($acl, 'entriesInheriting', true, false);
        $changes = $propertyChanges->offsetGet($acl);
        $this->assertFalse(isset($changes['entriesInheriting']));
    }

    /**
     * @covers ::propertyChanged
     */
    public function testPropertyChangedTracksChangesToAceProperties()
    {
        $provider = $this->getProvider();
        $acl = $provider->createAcl(new ObjectIdentity(1, 'Foo'));
        $ace = new Entry(1, $acl, new UserSecurityIdentity('foo', 'FooClass'), 'all', 1, true, true, true);
        $ace2 = new Entry(2, $acl, new UserSecurityIdentity('foo', 'FooClass'), 'all', 1, true, true, true);
        $propertyChanges = $this->getField($provider, 'propertyChanges');

        $provider->propertyChanged($ace, 'mask', 1, 3);
        $changes = $propertyChanges->offsetGet($acl);
        $this->assertTrue(isset($changes['aces']));
        $this->assertInstanceOf('\SplObjectStorage', $changes['aces']);
        $this->assertTrue($changes['aces']->contains($ace));
        $aceChanges = $changes['aces']->offsetGet($ace);
        $this->assertTrue(isset($aceChanges['mask']));
        $this->assertEquals(1, $aceChanges['mask'][0]);
        $this->assertEquals(3, $aceChanges['mask'][1]);

        $provider->propertyChanged($ace, 'strategy', 'all', 'any');
        $changes = $propertyChanges->offsetGet($acl);
        $this->assertTrue(isset($changes['aces']));
        $this->assertInstanceOf('\SplObjectStorage', $changes['aces']);
        $this->assertTrue($changes['aces']->contains($ace));
        $aceChanges = $changes['aces']->offsetGet($ace);
        $this->assertTrue(isset($aceChanges['mask']));
        $this->assertTrue(isset($aceChanges['strategy']));
        $this->assertEquals('all', $aceChanges['strategy'][0]);
        $this->assertEquals('any', $aceChanges['strategy'][1]);

        $provider->propertyChanged($ace, 'mask', 3, 1);
        $changes = $propertyChanges->offsetGet($acl);
        $aceChanges = $changes['aces']->offsetGet($ace);
        $this->assertFalse(isset($aceChanges['mask']));
        $this->assertTrue(isset($aceChanges['strategy']));

        $provider->propertyChanged($ace2, 'mask', 1, 3);
        $provider->propertyChanged($ace, 'strategy', 'any', 'all');
        $changes = $propertyChanges->offsetGet($acl);
        $this->assertTrue(isset($changes['aces']));
        $this->assertFalse($changes['aces']->contains($ace));
        $this->assertTrue($changes['aces']->contains($ace2));

        $provider->propertyChanged($ace2, 'mask', 3, 4);
        $provider->propertyChanged($ace2, 'mask', 4, 1);
        $changes = $propertyChanges->offsetGet($acl);
        $this->assertFalse(isset($changes['aces']));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage $acl is not tracked by this provider.
     *
     * @covers ::updateAcl
     */
    public function testUpdateAclDoesNotAcceptUntrackedAcls()
    {
        $provider = $this->getProvider();
        $acl = new Acl(1, new ObjectIdentity(1, 'Foo'), new PermissionGrantingStrategy(), array(), true);
        $provider->updateAcl($acl);
    }

    /**
     * @covers ::updateAcl
     */
    public function testUpdateDoesNothingWhenThereAreNoChanges()
    {
        $args = array(
            $this->connection, self::DATABASE_NAME, new PermissionGrantingStrategy(), array(),
        );
        $provider = $this->getMockBuilder(MutableAclProvider::class)
                ->setConstructorArgs($args)
                ->getMock();
        $acl = new Acl(1, new ObjectIdentity(1, 'Foo'), new PermissionGrantingStrategy(), array(), true);
        $propertyChanges = $this->getField($provider, 'propertyChanges');
        $propertyChanges->offsetSet($acl, array());

        $provider->updateAcl($acl);
    }

    /**
     * @expectedException Symfony\Component\Security\Acl\Exception\ConcurrentModificationException
     * @expectedExceptionMessage The "classAces" property has been modified concurrently.
     */
    public function testUpdateAclThrowsExceptionOnConcurrentModificationOfSharedProperties()
    {
        $provider = $this->getProvider();
        $acl1 = $provider->createAcl(new ObjectIdentity(1, 'Foo'));
        $acl2 = $provider->createAcl(new ObjectIdentity(2, 'Foo'));
        $acl3 = $provider->createAcl(new ObjectIdentity(1, 'AnotherFoo'));
        $sid = new RoleSecurityIdentity('ROLE_FOO');

        $acl1->insertClassAce($sid, 1);
        $acl3->insertClassAce($sid, 1);
        $provider->updateAcl($acl1);
        $provider->updateAcl($acl3);

        $acl2->insertClassAce($sid, 16);
        $provider->updateAcl($acl2);

        $acl1->insertClassAce($sid, 3);
        $acl2->insertClassAce($sid, 5);

        $provider->updateAcl($acl1);
    }

    /**
     * @covers ::updateAcl
     */
    public function testUpdateAcl()
    {
        $provider = $this->getProvider();
        $acl = $provider->createAcl(new ObjectIdentity(1, 'Foo'));
        $sid = new UserSecurityIdentity('johannes', 'FooClass');
        $acl->setEntriesInheriting(!$acl->isEntriesInheriting());

        $acl->insertObjectAce($sid, 1);
        $acl->insertClassAce($sid, 5, 0, false);
        $acl->insertObjectAce($sid, 2, 1, true);
        $acl->insertClassFieldAce('field', $sid, 2, 0, true);
        $provider->updateAcl($acl);

        $acl->updateObjectAce(0, 3);
        $acl->deleteObjectAce(1);
        $acl->updateObjectAuditing(0, true, false);
        $acl->updateClassFieldAce(0, 'field', 15);
        $provider->updateAcl($acl);

        $reloadProvider = $this->getProvider();
        $reloadedAcl = $reloadProvider->findAcl(new ObjectIdentity(1, 'Foo'));
        $this->assertNotSame($acl, $reloadedAcl);
        $this->assertSame($acl->isEntriesInheriting(), $reloadedAcl->isEntriesInheriting());

        $aces = $acl->getObjectAces();
        $classAces = $acl->getClassAces();
        $reloadedAces = $reloadedAcl->getObjectAces();
        $reloadedClassAces = $reloadedAcl->getClassAces();
        $this->assertEquals(count($aces), count($reloadedAces));
        $this->assertEquals(count($classAces), count($reloadedClassAces));

        $allAces = array_merge($aces, $classAces);
        $allReloadedAces = array_merge($reloadedAces, $reloadedClassAces);

        foreach ($allAces as $index => $ace) {
            $this->assertAceEquals($ace, $allReloadedAces[$index]);
        }
    }

    /**
     * @covers ::updateAcl
     */
    public function testUpdateAclWorksForChangingTheParentAcl()
    {
        $provider = $this->getProvider();
        $acl = $provider->createAcl(new ObjectIdentity(1, 'Foo'));
        $parentAcl = $provider->createAcl(new ObjectIdentity(1, 'AnotherFoo'));
        $acl->setParentAcl($parentAcl);
        $provider->updateAcl($acl);

        $reloadProvider = $this->getProvider();
        $reloadedAcl = $reloadProvider->findAcl(new ObjectIdentity(1, 'Foo'));
        $this->assertNotSame($acl, $reloadedAcl);
        $this->assertSame($parentAcl->getId(), $reloadedAcl->getParentAcl()->getId());
    }

    /**
     * Data must have the following format:
     * array(
     *     *name* => array(
     *         'object_identifier' => *required*
     *         'class_type' => *required*,
     *         'parent_acl' => *name (optional)*
     *     ),
     * )
     *
     * @param AclProvider $provider
     * @param array $data
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    protected function importAcls(AclProvider $provider, array $data)
    {
        $aclIds = $parentAcls = array();

        foreach ($data as $name => $aclData) {
            if (!isset($aclData['object_identifier'], $aclData['class_type'])) {
                throw new \InvalidArgumentException('"object_identifier", and "class_type" must be present.');
            }

            $objIdentity = new ObjectIdentity($aclData['object_identifier'], $aclData['class_type']);
            $this->callMethod($provider, 'createObjectIdentity', array($objIdentity));

            $acl = $this->callMethod($provider, 'getObjectIdentity', array($objIdentity));
            $aclIds[$name] = $aclId = (string)$acl['_id'];

            if (isset($aclData['parent_acl'])) {
                if (isset($aclIds[$aclData['parent_acl']])) {
                    $this->addParentToIdentity($provider, $aclId, $aclIds[$aclData['parent_acl']]);
                } else {
                    $parentAcls[$aclId] = $aclData['parent_acl'];
                }
            }
        }

        foreach ($parentAcls as $aclId => $name) {
            if (!isset($aclIds[$name])) {
                throw new \InvalidArgumentException(sprintf('"%s" does not exist.', $name));
            }
            $this->addParentToIdentity($provider, $aclId, $aclIds[$name]);
        }
    }

    protected function addParentToIdentity(AclProvider $provider, $identity, $parent)
    {
        $query = array(
            '_id' => new \MongoId($parent),
        );

        $this->getField($provider, 'connection');
        $parent = $this->oidCollection->findOne($query);

        // update parent relationship
        $updates['parent'] = $parent;

        if (isset($parent['ancestors'])) {
            $ancestors = $parent['ancestors'];
        }
        $ancestors[] = $parent['_id'];
        $updates['ancestors'] = $ancestors;

        $entry = array(
            '_id' => new \MongoId($identity),
        );
        $newData = array(
            '$set' => $updates,
        );

        $this->oidCollection->update($entry, $newData);
    }

    protected function callMethod($object, $method, array $args)
    {
        $method = new \ReflectionMethod($object, $method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }

    protected function getField($object, $field)
    {
        $reflection = new \ReflectionProperty($object, $field);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    public function setField($object, $field, $value)
    {
        $reflection = new \ReflectionProperty($object, $field);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
        $reflection->setAccessible(false);
    }

    protected function getStrategy()
    {
        return new PermissionGrantingStrategy();
    }

    protected function getProvider($cache = null)
    {
        return new MutableAclProvider($this->connection, self::DATABASE_NAME, $this->getStrategy(), AclProvider::getDefaultOptions(), $cache);
    }

    public static function assertAceEquals(EntryInterface $a, EntryInterface $b)
    {
        self::assertInstanceOf(get_class($a), $b);

        foreach (array('getId', 'getMask', 'getStrategy', 'isGranting') as $getter) {
            self::assertSame($a->$getter(), $b->$getter());
        }

        self::assertTrue($a->getSecurityIdentity()->equals($b->getSecurityIdentity()));
        self::assertSame($a->getAcl()->getId(), $b->getAcl()->getId());

        if ($a instanceof AuditableEntryInterface) {
            /** @var AuditableEntryInterface $a */
            self::assertSame($a->isAuditSuccess(), $b->isAuditSuccess());
            self::assertSame($a->isAuditFailure(), $b->isAuditFailure());
        }

        if ($a instanceof FieldEntryInterface) {
            self::assertSame($a->getField(), $b->getField());
        }
    }

}
