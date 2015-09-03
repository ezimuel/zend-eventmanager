<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\EventManager;

use ArrayAccess;
use ArrayObject;
use Traversable;
use Zend\Stdlib\PriorityQueue;

/**
 * Event manager: notification system
 *
 * Use the EventManager when you want to create a per-instance notification
 * system for your objects.
 */
class EventManager implements EventManagerInterface
{
    /**
     * Subscribed events and their listeners
     * @var array Array of PriorityQueue objects
     */
    protected $events = [];

    /**
     * @var string Class representing the event being emitted
     */
    protected $eventClass = 'Zend\EventManager\Event';

    /**
     * Identifiers, used to pull shared signals from SharedEventManagerInterface instance
     * @var array
     */
    protected $identifiers = [];

    /**
     * Have shared/wildcard listeners been prepared already?
     *
     * @var bool
     */
    private $isPrepared = false;

    /**
     * Shared event manager
     * @var false|null|SharedEventManagerInterface
     */
    protected $sharedManager = null;

    /**
     * @var array List of wildcard listeners.
     */
    private $wildcardListeners = [];

    /**
     * Constructor
     *
     * Allows optionally specifying identifier(s) to use to pull signals from a
     * SharedEventManagerInterface.
     *
     * @param  null|string|int|array|Traversable $identifiers
     */
    public function __construct($identifiers = null, SharedEventManagerInterface $sharedEventManager = null)
    {
        if ($sharedEventManager) {
            $this->sharedManager = $sharedEventManager;
        }

        if ($identifiers !== null) {
            $this->setIdentifiers($identifiers);
        }
    }

    /**
     * Set the event class to utilize
     *
     * @param  string $class
     * @return EventManager
     */
    public function setEventClass($class)
    {
        $this->eventClass = $class;
        return $this;
    }

    /**
     * Set shared event manager
     *
     * @param SharedEventManagerInterface $sharedEventManager
     * @return EventManager
     */
    public function setSharedManager(SharedEventManagerInterface $sharedEventManager)
    {
        $this->sharedManager = $sharedEventManager;
        return $this;
    }

    /**
     * Get the identifier(s) for this EventManager
     *
     * @return array
     */
    public function getIdentifiers()
    {
        return $this->identifiers;
    }

    /**
     * Set the identifiers (overrides any currently set identifiers)
     *
     * @param string|string[]|Traversable $identifiers
     * @return EventManager Provides a fluent interface
     */
    public function setIdentifiers($identifiers)
    {
        if ($this->isPrepared) {
            throw new Exception\RuntimeException(sprintf(
                '%s cannot be called after any events have been triggered',
                __METHOD__
            ));
        }

        $this->identifiers = array_unique($this->prepareIdentifiers($identifiers));

        return $this;
    }

    /**
     * Add identifier(s) (appends to any currently set identifiers)
     *
     * @param string|int|array|Traversable $identifiers
     * @return EventManager Provides a fluent interface
     * @throws Exception\RuntimeException if called more than once.
     */
    public function addIdentifiers($identifiers)
    {
        if ($this->isPrepared) {
            throw new Exception\RuntimeException(sprintf(
                '%s cannot be called after any events have been triggered',
                __METHOD__
            ));
        }

        $this->identifiers = array_unique(array_merge(
            $this->identifiers,
            $this->prepareIdentifiers($identifiers)
        ));

        return $this;
    }

    /**
     * Trigger all listeners for a given event
     *
     * @param  string|EventInterface $event
     * @param  string|object     $target   Object calling emit, or symbol describing target (such as static method name)
     * @param  array|ArrayAccess $argv     Array of arguments; typically, should be associative
     * @param  null|callable     $callback Trigger listeners until return value of this callback evaluate to true
     * @return ResponseCollection All listener return values
     * @throws Exception\InvalidCallbackException
     */
    public function trigger($event, $target = null, $argv = array(), callable $callback = null)
    {
        $this->prepareListeners();

        if ($event instanceof EventInterface) {
            $e        = $event;
            $event    = $e->getName();
            $callback = $target;
        } elseif ($target instanceof EventInterface) {
            $e = $target;
            $e->setName($event);
            $callback = $argv;
        } elseif ($argv instanceof EventInterface) {
            $e = $argv;
            $e->setName($event);
            $e->setTarget($target);
        } else {
            $e = new $this->eventClass();
            $e->setName($event);
            $e->setTarget($target);
            $e->setParams($argv);
        }

        if ($callback && !is_callable($callback)) {
            throw new Exception\InvalidCallbackException('Invalid callback provided');
        }

        // Initial value of stop propagation flag should be false
        $e->stopPropagation(false);

        return $this->triggerListeners($e, $callback);
    }

    /**
     * Attach a listener to an event
     *
     * The first argument is the event, and the next argument is a
     * callable that will respond to that event.
     *
     * The last argument indicates a priority at which the event should be
     * executed; by default, this value is 1; however, you may set it for any
     * integer value. Higher values have higher priority (i.e., execute first).
     *
     * You can specify "*" for the event name. In such cases, the listener will
     * be triggered for every event *that has registered listeners at the time
     * it is attached*. As such, register wildcard events last whenever possible!
     *
     * @param  string|string[] $event An event or array of event names.
     * @param  callable $listener Event listener.
     * @param  int $priority If provided, the priority at which to register the
     *     listener.
     * @throws Exception\InvalidArgumentException
     */
    public function attach($event, callable $listener, $priority = 1)
    {
        if (! is_string($event) && ! is_array($event) && ! $event instanceof Traversable) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects a string or array/Traversable of strings for the event; received %s',
                (is_object($event) ? get_class($event) : gettype($event))
            ));
        }

        // Array of events should be registered individually, and return an array of all listeners
        if (! is_string($event)) {
            foreach ($event as $name) {
                $this->attach($name, $listener, $priority);
            }
            return;
        }

        // Is this the wildcard event? If so, add the listener to the wildcard
        // list with its priority, to inject later.
        if ('*' === $event) {
            $this->wildcardListeners[] = [
                'listener' => $listener,
                'priority' => $priority,
            ];
            return;
        }

        // If we don't have a priority queue for the event yet, create one
        if (empty($this->events[$event])) {
            $this->events[$event] = new PriorityQueue();
        }

        // Inject the listener into the queue
        $this->events[$event]->insert($listener, $priority);
    }

    /**
     * Attach a listener aggregate
     *
     * Listener aggregates accept an EventManagerInterface instance, and call attach()
     * one or more times, typically to attach to multiple events using local
     * methods.
     *
     * @param  ListenerAggregateInterface $aggregate
     * @param  int $priority If provided, a suggested priority for the aggregate to use
     * @return mixed return value of {@link ListenerAggregateInterface::attach()}
     */
    public function attachAggregate(ListenerAggregateInterface $aggregate, $priority = 1)
    {
        return $aggregate->attach($this, $priority);
    }

    /**
     * Retrieve all registered events
     *
     * @return array
     */
    public function getEvents()
    {
        return array_keys($this->events);
    }

    /**
     * Retrieve all listeners for a given event
     *
     * @param  string $event
     * @return PriorityQueue
     */
    public function getListeners($event)
    {
        if (! array_key_exists($event, $this->events)) {
            return new PriorityQueue();
        }

        return $this->events[$event];
    }

    /**
     * Clear all listeners for a given event
     *
     * @param  string $event
     * @return void
     */
    public function clearListeners($event)
    {
        if (empty($this->events[$event])) {
            return;
        }

        unset($this->events[$event]);
    }

    /**
     * Prepare arguments
     *
     * Use this method if you want to be able to modify arguments from within a
     * listener. It returns an ArrayObject of the arguments, which may then be
     * passed to trigger().
     *
     * @param  array $args
     * @return ArrayObject
     */
    public function prepareArgs(array $args)
    {
        return new ArrayObject($args);
    }

    /**
     * Trigger listeners
     *
     * Actual functionality for triggering listeners, to which trigger() delegate.
     *
     * @param  EventInterface $event
     * @param  null|callable $callback
     * @return ResponseCollection
     */
    protected function triggerListeners(EventInterface $event, callable $callback = null)
    {
        $responses = new ResponseCollection;

        foreach ($this->getListeners($event->getName()) as $listener) {
            // Trigger the listener, and push its result onto the response collection
            $response = call_user_func($listener, $event);
            $responses->push($response);

            // If the event was asked to stop propagating, do so
            if ($event->propagationIsStopped()) {
                $responses->setStopped(true);
                break;
            }

            // If the result causes our validation callback to return true,
            // stop propagation
            if ($callback && call_user_func($callback, $response)) {
                $responses->setStopped(true);
                break;
            }
        }

        return $responses;
    }

    /**
     * Prepare identifier arguments to inject in the instance.
     *
     * @param string|string[]|Traversable $identifiers
     * @return array
     * @throws Exception\InvalidArgumentException for invalid identifiers.
     */
    private function prepareIdentifiers($identifiers)
    {
        if ($identifiers instanceof Traversable) {
            $identifiers = iterator_to_array($identifiers);
        }

        if (is_string($identifiers) && ! empty($identifiers)) {
            $identifiers = (array) $identifiers;
        }

        if (! is_array($identifiers)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Identifiers must be a non-empty string, an array, or a Traversable set; received %s',
                (is_object($identifiers) ? get_class($identifiers) : gettype($identifiers))
            ));
        }

        return $identifiers;
    }

    /**
     * Prepare listeners.
     *
     * Attaches all listeners from the shared event manager to the current
     * instance by:
     *
     * - Looping through identifiers in this instance, and attaching any
     *   listeners from the shared manager on the given identifier.
     * - Looping through shared listeners on the wildcard identifier.
     * - Looping through any listeners on wildcard events and attaching them.
     *
     * This method is only called once per instance, the first time any event
     * is triggered; as such, all shared and wildcard listeners MUST be
     * injected BEFORE the first trigger.
     */
    private function prepareListeners()
    {
        if ($this->isPrepared) {
            return;
        }

        if ($this->sharedManager) {
            $this->attachSharedListeners();
        }

        $this->prepareWildcardListeners($this->getEvents(), $this->wildcardListeners);

        $this->isPrepared = true;
    }

    /**
     * Attach shared listeners.
     *
     * Attaches shared listeners for identifiers in the current instance, as
     * well as any on the wildcard listener.
     */
    private function attachSharedListeners()
    {
        foreach ($this->identifiers as $identifier) {
            foreach ($this->sharedManager->getListeners($identifier) as $event => $listeners) {
                $this->attachListenerStructs($event, $listeners);
            }
        }

        foreach ($this->sharedManager->getListeners('*') as $event => $listeners) {
            $this->attachListenerStructs($event, $listeners);
        }
    }

    /**
     * Attach listener structs to a given event.
     *
     * Loops through each listener struct, attaching the listener at the given
     * priority to the specified event.
     *
     * @param string $event
     * @param array $listeners
     */
    private function attachListenerStructs($event, array $listeners)
    {
        foreach ($listeners as $struct) {
            $this->attach($event, $struct['listener'], $struct['priority']);
        }
    }

    /**
     * Inject wildcard listeners.
     *
     * Loops through each event, injecting each wildcard listener available.
     *
     * @param array $events
     * @param array $listeners
     */
    private function prepareWildcardListeners(array $events, array $listeners)
    {
        foreach ($events as $event) {
            $this->attachListenerStructs($event, $listeners);
        }
    }
}
