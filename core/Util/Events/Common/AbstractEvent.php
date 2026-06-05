<?php

namespace Blesta\Core\Util\Events\Common;

/**
 * Abstract event
 *
 * @package blesta
 * @subpackage core.Util.Events.Common
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
abstract class AbstractEvent implements EventInterface
{
    /**
     * @var string The name of the event
     */
    protected $name;
    /**
     * @var array|null An array of parameters held by this event
     */
    protected $params;
    /**
     * @var mixed The return value (if any) from the event listener
     */
    protected $returnValue;
    /**
     * @var array An array of errors held by this event
     */
    protected $errors = [];
    /**
     * @var array An array of rules held by this event
     */
    protected $rules = [];

    /**
     * Creates a new event
     *
     * @param string $name The name of the event
     * @param array $params An array of parameters to be held by this event (optional)
     */
    public function __construct($name, ?array $params = null)
    {
        $this->name = $name;
        $this->setParams($params);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * {@inheritdoc}
     */
    public function setParams(?array $params = null)
    {
        $this->params = $params;
    }

    /**
     * {@inheritdoc}
     */
    public function getReturnValue()
    {
        return $this->returnValue;
    }

    /**
     * {@inheritdoc}
     */
    public function setReturnValue($value)
    {
        $this->returnValue = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * {@inheritdoc}
     */
    public function setErrors(array $errors = [])
    {
        $this->errors = $errors;
    }

    /**
     * {@inheritdoc}
     */
    public function getRules()
    {
        return $this->rules;
    }

    /**
     * {@inheritdoc}
     */
    public function setRules(array $rules = [])
    {
        $this->rules = $rules;
    }
}
