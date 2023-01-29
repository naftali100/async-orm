<?php

namespace AsyncOrm;

/**
 * represent one row in db
 */
class OrmObject
{
    private $__info = [];
    private $properties = [];
    private $new_values = [];

    function __construct($type, array $data = [])
    {
        $this->__info['type'] = $type;
        $this->__info['created'] = empty($data);
        if ($this->__info['created']) {
            $data['id'] = 0;
        }
        $this->properties = $data;
    }

    function __get($key)
    {
        if (isset($this->new_values[$key])) {
            return $this->new_values[$key];
        }

        if (isset($this->properties[$key])) {
            return $this->properties[$key];
        }
    }

    function __set($key, $value)
    {
        if (gettype($value) == 'array') {
            $value = json_encode($value);
        }

        $this->new_values[$key] = $value;

        if (isset($this->properties[$key])) {
            if ($value != $this->properties[$key]) {
                $this->__info['changed'] = true;
            }
        } else {
            $this->__info['changed'] = true;
        }
    }

    function getChanges()
    {
        return $this->new_values;
    }

    function getMeta($type)
    {
        if (isset($this->__info[$type])) {
            return $this->__info[$type];
        }
    }

    /** revert all changes made to object */
    function revert()
    {
        $this->new_values = [];
    }

    function setMeta($type, $value)
    {
        // TODO: add allowed info values
        // if (isset($this->__info[$type]))
        $this->__info[$type] = $value;
    }

    public function getProperty($key)
    {
        if (isset($this->properties[$key])){
            return $this->properties[$key];
        }
    }

    function save()
    {
        $this->properties = array_merge($this->properties, $this->new_values);
        $this->new_values = [];
    }

    function trash(){
        return ORM::trash($this);
    }

    function store(){
        return ORM::store($this);
    }

    public function reload()
    {
        return ORM::load($this->getMeta('type'), $this->getProperty('id'));
    }
}
