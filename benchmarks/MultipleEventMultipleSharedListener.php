<?php

namespace ZendBench\EventManager;

use Zend\EventManager\SharedEventManager;
use Zend\EventManager\EventManager;
use Athletic\AthleticEvent;

class MultipleEventMultipleSharedListener extends AthleticEvent
{
    use TraitEventBench;

    private $sharedEvents;

    private $events;

    public function setUp()
    {
        $identifiers = $this->getIdentifierList();
        $this->sharedEvents = new SharedEventManager();
        foreach ($this->getIdentifierList() as $identifier) {
            foreach ($this->getEventList() as $event) {
                $this->sharedEvents->attach($identifier, $event, $this->generateCallback());
            }
        }
        $this->events = new EventManager();
        $this->events->setSharedManager($this->sharedEvents);
        $this->events->setIdentifiers(array_filter($identifiers, function ($value) {
            return ($value !== '*');
        }));
    }

    /**
     * Trigger the event list
     *
     * @iterations 5000
     */
    public function trigger()
    {
        foreach ($this->getEventList() as $event) {
            $this->events->trigger($event);
        }
    }
}
