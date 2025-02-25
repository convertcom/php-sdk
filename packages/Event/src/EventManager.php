<?php
namespace ConvertSdk\Event;

/**
 * Class EventManager
 *
 * Provides an event wrapper.
 */
class EventManager
{
    /**
     * @var mixed|null Optional logger manager instance.
     */
    private $loggerManager;

    /**
     * @var array Associative array where keys are event names and values are arrays of callables.
     */
    private $listeners = [];

    /**
     * @var array Associative array for storing deferred event data.
     */
    private $deferred = [];

    /**
     * @var callable Mapper function.
     */
    private $mapper;

    /**
     * EventManager constructor.
     *
     * @param array|null $config Optional configuration array. If provided, may contain a 'mapper' callable.
     * @param array $dependencies Optional dependencies; supports key 'loggerManager'.
     */
    public function __construct(?array $config = null, array $dependencies = [])
    {
        $this->listeners = [];
        $this->deferred  = [];
        $this->loggerManager = $dependencies['loggerManager'] ?? null;

        // Use mapper from config if provided; otherwise, default to identity function.
        $this->mapper = (isset($config['mapper']) && is_callable($config['mapper']))
            ? $config['mapper']
            : function ($value) {
                return $value;
            };
    }

    /**
     * Add a listener for an event.
     *
     * @param string $event Event name.
     * @param callable $fn Callback function with signature fn($args, $err).
     * @return void
     */
    public function on($event, callable $fn): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $fn;

        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('EventManager.on()', ['event' => $event]);
        }

        if (array_key_exists($event, $this->deferred)) {
            $deferredData = $this->deferred[$event];
            $this->fire($event, $deferredData['args'] ?? null, $deferredData['err'] ?? null);
        }
    }

    /**
     * Remove all listeners for an event.
     *
     * @param string $event Event name.
     * @return void
     */
    public function removeListeners(string $event): void
    {
        if (array_key_exists($event, $this->listeners)) {
            unset($this->listeners[$event]);
        }
        if (array_key_exists($event, $this->deferred)) {
            unset($this->deferred[$event]);
        }
    }

    /**
     * Fire an event with provided arguments and/or errors.
     *
     * @param string $event Event name.
     * @param mixed $args Optional arguments.
     * @param mixed $err Optional error.
     * @param bool $deferred Whether to store event data for listeners added later.
     * @return void
     */
    public function fire($event, $args = null, $err = null, bool $deferred = false): void
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
            $mappedData = call_user_func($this->mapper, [
                'event'    => $event,
                'args'     => $args,
                'err'      => $err,
                'deferred' => $deferred
            ]);
            $this->loggerManager->debug('EventManager.fire()', $mappedData);
        }

        if (isset($this->listeners[$event]) && is_array($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $fn) {
                if (is_callable($fn)) {
                    try {
                        call_user_func($fn, call_user_func($this->mapper, $args), $err);
                    } catch (\Throwable $error) {
                        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                            $this->loggerManager->error('EventManager.fire()', $error->getMessage());
                        }
                    }
                }
            }
        }

        if ($deferred && !array_key_exists($event, $this->deferred)) {
            $this->deferred[$event] = ['args' => $args, 'err' => $err];
        }
    }
}
