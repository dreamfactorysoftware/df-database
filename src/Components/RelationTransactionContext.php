<?php

namespace DreamFactory\Core\Database\Components;

use Illuminate\Support\Facades\Log;

/**
 * Tracks transaction context for virtual relationship writes.
 *
 * For same-service relations, the existing DB transaction covers both tables.
 * For cross-service relations, this tracks compensating actions (saga pattern)
 * to reverse operations if a later step fails.
 */
class RelationTransactionContext
{
    /** @var bool Whether to rollback all changes on any failure */
    protected $rollback = false;

    /** @var bool Whether to continue processing after a failure */
    protected $continue = false;

    /** @var array Compensating actions to reverse cross-service operations */
    protected $compensatingActions = [];

    /** @var array Errors collected during continue mode */
    protected $errors = [];

    public function __construct(bool $rollback = false, bool $continue = false)
    {
        $this->rollback = $rollback;
        $this->continue = $continue;
    }

    /**
     * Register a compensating action to reverse a cross-service operation.
     * Actions are executed in LIFO order during rollback.
     */
    public function addCompensatingAction(
        string $service,
        string $resource,
        string $verb,
        $data = null,
        $params = null
    ) {
        $this->compensatingActions[] = compact('service', 'resource', 'verb', 'data', 'params');
    }

    /**
     * Record an error for a relation operation (used in continue mode).
     */
    public function addError(string $relationName, \Exception $ex)
    {
        $this->errors[$relationName] = $ex;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get error messages as a simple associative array.
     */
    public function getErrorMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $name => $ex) {
            $messages[$name] = $ex->getMessage();
        }
        return $messages;
    }

    /**
     * Execute all compensating actions in reverse order (LIFO).
     * Compensations are best-effort; failures are logged but not thrown.
     */
    public function executeCompensations(callable $handleVirtualRecordsCallback)
    {
        foreach (array_reverse($this->compensatingActions) as $action) {
            try {
                $handleVirtualRecordsCallback(
                    $action['service'],
                    $action['resource'],
                    $action['verb'],
                    $action['data'],
                    $action['params']
                );
            } catch (\Exception $e) {
                Log::error("Saga compensation failed: " . $e->getMessage(), [
                    'service' => $action['service'],
                    'resource' => $action['resource'],
                    'verb' => $action['verb'],
                ]);
            }
        }

        $this->compensatingActions = [];
    }

    public function hasCompensatingActions(): bool
    {
        return !empty($this->compensatingActions);
    }

    public function shouldRollback(): bool
    {
        return $this->rollback;
    }

    public function shouldContinue(): bool
    {
        return $this->continue;
    }
}
