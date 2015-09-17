<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace ZendTest\EventManager;

use Zend\EventManager\StaticEventManager;
use PHPUnit_Framework_TestCase as TestCase;

/**
 * @group      Zend_EventManager
 */
class StaticIntegrationTest extends TestCase
{
    public function setUp()
    {
        StaticEventManager::resetInstance();
    }

    public function testCanConnectStaticallyToClassWithEvents()
    {
        $counter = (object) ['count' => 0];
        $events  = StaticEventManager::getInstance();
        $events->attach(
            'ZendTest\EventManager\TestAsset\ClassWithEvents',
            'foo',
            function ($e) use ($counter) {
                $counter->count++;
            }
        );
        $class = new TestAsset\ClassWithEvents();
        $class->getEventManager()->setSharedManager($events);
        $class->foo();
        $this->assertEquals(1, $counter->count);
    }

    public function testLocalHandlersAreExecutedPriorToStaticHandlersWhenSetWithSamePriority()
    {
        $test = (object) ['results' => []];
        $events = StaticEventManager::getInstance();
        $events->attach(
            'ZendTest\EventManager\TestAsset\ClassWithEvents',
            'foo',
            function ($e) use ($test) {
                $test->results[] = 'static';
            }
        );
        $class = new TestAsset\ClassWithEvents();
        $class->getEventManager()->attach('foo', function ($e) use ($test) {
            $test->results[] = 'local';
        });
        $class->getEventManager()->setSharedManager($events);
        $class->foo();
        $this->assertEquals(['local', 'static'], $test->results);
    }

    public function testLocalHandlersAreExecutedInPriorityOrderRegardlessOfStaticOrLocalRegistration()
    {
        $test = (object) ['results' => []];
        $events = StaticEventManager::getInstance();
        $events->attach(
            'ZendTest\EventManager\TestAsset\ClassWithEvents',
            'foo',
            function ($e) use ($test) {
                $test->results[] = 'static';
            },
            10000 // high priority
        );
        $class = new TestAsset\ClassWithEvents();
        $class->getEventManager()->attach('foo', function ($e) use ($test) {
            $test->results[] = 'local';
        }, 1); // low priority
        $class->getEventManager()->attach('foo', function ($e) use ($test) {
            $test->results[] = 'local2';
        }, 1000); // medium priority
        $class->getEventManager()->attach('foo', function ($e) use ($test) {
            $test->results[] = 'local3';
        }, 15000); // highest priority
        $class->getEventManager()->setSharedManager($events);
        $class->foo();
        $this->assertEquals(['local3', 'static', 'local2', 'local'], $test->results);
    }

    public function testCallingUnsetSharedManagerDisablesStaticManager()
    {
        $counter = (object) ['count' => 0];
        StaticEventManager::getInstance()->attach(
            'ZendTest\EventManager\TestAsset\ClassWithEvents',
            'foo',
            function ($e) use ($counter) {
                $counter->count++;
            }
        );
        $class = new TestAsset\ClassWithEvents();
        //$class->getEventManager()->unsetSharedManager();
        $class->foo();
        $this->assertEquals(0, $counter->count);
    }

    public function testCanPassAlternateStaticConnectionsHolder()
    {
        $counter = (object) ['count' => 0];
        StaticEventManager::getInstance()->attach(
            'ZendTest\EventManager\TestAsset\ClassWithEvents',
            'foo',
            function ($e) use ($counter) {
                $counter->count++;
            }
        );
        $mockStaticEvents = new TestAsset\StaticEventsMock();
        $class = new TestAsset\ClassWithEvents();
        $class->getEventManager()->setSharedManager($mockStaticEvents);
        //$this->assertSame($mockStaticEvents, $class->getEventManager()->getSharedManager());
        $class->foo();
        $this->assertEquals(0, $counter->count);
    }

    public function testTriggerMergesPrioritiesOfStaticAndInstanceListeners()
    {
        $test = (object) ['results' => []];
        $events = StaticEventManager::getInstance();
        $events->attach(
            'ZendTest\EventManager\TestAsset\ClassWithEvents',
            'foo',
            function ($e) use ($test) {
                $test->results[] = 'static';
            },
            100
        );
        $class = new TestAsset\ClassWithEvents();
        $class->getEventManager()->attach('foo', function ($e) use ($test) {
            $test->results[] = 'local';
        }, -100);
        $class->getEventManager()->setSharedManager($events);
        $class->foo();
        $this->assertEquals(['static', 'local'], $test->results);
    }
}
