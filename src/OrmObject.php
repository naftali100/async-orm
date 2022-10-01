<?php

namespace async_orm;

/**
 * represent one row in db
 */
class OrmObject
{
    private $__info = [];
    private $origin;
    private $new_values;

    function __construct($type, array $data = [])
    {
        $this->__info['type'] = $type;
        $this->__info['created'] = !isset($data['id']); // if obj has id - its already exist in db
        if ($this->__info['created']) {
            $data['id'] = 0;
        }
        $this->origin = $data;
    }

    function __get($key)
    {
        if (isset($this->new_values[$key])) {
            return $this->new_values[$key];
        }

        if (isset($this->origin[$key])) {
            return $this->origin[$key];
        }
    }

    function __set($key, $value)
    {
        if (gettype($value) == 'array') {
            $value = json_encode($value);
        }

        $this->new_values[$key] = $value;

        if (isset($this->origin[$key])) {
            if ($value != $this->origin[$key]) {
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

    function getOrigin($key)
    {
        if (isset($this->origin[$key]))
            return $this->origin[$key];
    }

    function save()
    {
        $this->origin = array_merge($this->origin, $this->new_values);
        $this->new_values = [];
    }

    function trash(){
        return ORM::trash($this);
    }

    function store(){
        return ORM::store($this);
    }
}
